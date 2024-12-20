<?php

namespace VisionAura\LaravelCore\Http\Resolvers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use VisionAura\LaravelCore\Casts\CastPrimitives;
use VisionAura\LaravelCore\Enums\FilterOperatorsEnum;
use VisionAura\LaravelCore\Enums\QueryTypeEnum;
use VisionAura\LaravelCore\Exceptions\CoreException;
use VisionAura\LaravelCore\Exceptions\ErrorBag;
use VisionAura\LaravelCore\Exceptions\InvalidRelationException;
use VisionAura\LaravelCore\Interfaces\RelationInterface;
use VisionAura\LaravelCore\Structs\FilterClauseStruct;

class FilterResolver
{
    protected Model&RelationInterface $model;

    /** @var array<int, string|FilterClauseStruct> */
    protected array $clauses = [];

    protected bool $hasFilter = false;

    public function __construct(Model&RelationInterface $model)
    {
        $typoFilters = array_filter(request()->all('filters'));

        if ($typoFilters) {
            throw new CoreException(ErrorBag::make(
                title: 'Typo in filter parameter',
                description: 'An unknown parameter with the name \'filters\' was passed. Did you mean \'filter\'?',
                status: Response::HTTP_BAD_REQUEST
            )->bag);
        }

        $this->model = $model;

        $filters = array_filter(request()->all('filter'));

        if (! Arr::has($filters, 'filter')) {
            return;
        }

        $this->hasFilter = true;

        $filters = $this->mapFilters(Arr::get($filters, 'filter'));

        Arr::map($filters, function ($filterSet, $key) {
            [$attribute, $relation] = $this->resolveAttributeAndRelation($key);

            foreach (Arr::wrap($filterSet) as $queryOperator => $queryValue) {
                $value = $this->resolveValue($queryValue, $attribute, $relation);

                if (str_contains($queryOperator, '.')) {
                    $queryType = strtok($queryOperator, '.');
                    $queryOperator = substr($queryOperator, strlen("$queryType."));
                }

                $operator = $this->resolveOperator($queryOperator, $relation);

                if (! $operator) {
                    throw new CoreException(ErrorBag::make(
                        __('core::errors.Invalid request'),
                        "An invalid operator '{$queryOperator}' was used on a filter in the query.",
                        'filter['.($relation ? "$relation." : '')."$attribute][".(isset($queryType) ? "$queryType." : '')."{$queryOperator}]={$queryValue}",
                        Response::HTTP_BAD_REQUEST
                    )->bag);
                }

                if (is_string($operator)) { // Filter is a scope.
                    $this->addClause(value: $value, type: $operator);

                    continue;
                }

                $type = $this->resolveQueryType($value, $operator, $queryType ?? null);
                if (! $type) {
                    $queryValue = is_array($queryValue) ? head($queryValue) : $queryValue;

                    if (isset($queryType)) {
                        throw new CoreException(ErrorBag::make(
                            __('core::errors.Invalid request'),
                            "An invalid query type '{$queryType}' was used on a filter in the query.",
                            'filter['.($relation ? "$relation." : '')."{$attribute}][{$queryType}.{$queryOperator}]={$queryValue}",
                            Response::HTTP_BAD_REQUEST
                        )->bag);
                    } else {
                        throw new CoreException(ErrorBag::make(
                            __('core::errors.Server error'),
                            "The query type could not be determined for the query.",
                            'filter['.($relation ? "$relation." : '')."{$attribute}][{$queryOperator}]={$queryValue}",
                            Response::HTTP_INTERNAL_SERVER_ERROR
                        )->bag);
                    }
                }

                $this->addClause($value, $type, $attribute, $operator, $relation);
            }
        });
    }

    public function addClause(
        mixed $value,
        string|QueryTypeEnum $type = QueryTypeEnum::WHERE,
        ?string $attribute = null,
        FilterOperatorsEnum|null $operator = FilterOperatorsEnum::EQUALS,
        ?string $relation = null
    ): self {
        if (is_string($type)) {
            $this->clauses[ $type ] = $value;

            return $this;
        }

        $this->clauses[] = new FilterClauseStruct($type, $value, $relation, $attribute, $operator);

        return $this;
    }

    public function hasFilter(): bool
    {
        return $this->hasFilter;
    }

    /** @return array{}|array<int, string|FilterClauseStruct> */
    public function get(): array
    {
        return $this->clauses;
    }

    /** @return array{}|array<int, string|FilterClauseStruct> */
    public function getRelations(?string $relation = null): array
    {
        $filterClauses = Arr::where($this->clauses, function (mixed $clause) {
            return $clause instanceof FilterClauseStruct;
        });

        return Arr::where($filterClauses, function (FilterClauseStruct $args) use ($relation) {
            return $relation
                ? ($args->relation === $relation && $args->attribute !== null)
                : ($args->relation !== null && $args->attribute !== null);
        });
    }

    /**
     * Takes a Builder or a Relation (usually from a whereHas() function) and adds the clauses to the query.
     *
     * @param  array{}|array<int, string|FilterClauseStruct>  $clauses
     */
    public function bind(Builder|Relation $query, array $clauses): Builder|Relation
    {
        return match (true) {
            ($query instanceof Builder) => $this->bindBuilderQuery($query, $clauses),
            ($query instanceof Relation) => $this->bindRelationQuery($query, $clauses),
            default => $query
        };
    }

    /**
     * Set the equals operator for all filters without an operator.
     *
     * @return array<mixed>
     */
    private function mapFilters(array $filters): array
    {
        return Arr::map($filters, function (array|string $filterSet) {
            // When the operator is or, it's mapped to be or.equals.
            $equals = array_filter(
                Arr::wrap($filterSet),
                (fn(int|string $key) => is_numeric($key) || $key === 'or' || $key === 'equals'),
                ARRAY_FILTER_USE_KEY
            );

            $equalsSet = [];
            if (in_array('or', array_keys($equals))) {
                $equalsSet[ 'or.'.FilterOperatorsEnum::EQUALS->value ] = $equals[ 'or' ];
                unset($equals[ 'or' ]);
                unset($filterSet[ 'or' ]);
            }

            if ($equals) {
                $equalsSet[ FilterOperatorsEnum::EQUALS->value ] = Arr::unwrapSingle(array_values($equals));
            }

            $equals = Arr::unwrapSingle($equals);

            if ($equalsSet) {
                return array_merge($equalsSet, array_diff_assoc(Arr::wrap($filterSet), Arr::wrap($equals)));
            }

            return $filterSet;
        });
    }

    /** @param  array{}|array<int, string|FilterClauseStruct>  $clauses */
    private function bindBuilderQuery(Builder $query, array $clauses): Builder
    {
        foreach ($clauses as $index => $clause) {
            if (is_string($index)) {
                // Operator is a scope function
                $this->attachScope($query, $index, $clause);

                continue;
            }

            if ($clause->relation !== null && $clause->attribute === null) {
                // The clause is probably for a relation. The value is probably true or false.
                $where = $clause->value ? 'whereHas' : 'doesntHave';
                $query->{$where}($clause->relation);

                continue;
            }

            if (! $clause->relation) {
                $this->attachWhereClause($query, $clause);

                continue;
            }

            $query->whereHas($clause->relation, function (Builder $query) use ($clause) {
                $this->attachWhereClause($query, $clause);
            });
        }

        return $query;
    }

    /** @param  array{}|array<int, string|FilterClauseStruct>  $clauses */
    private function bindRelationQuery(Relation $query, array $clauses): Relation
    {
        foreach ($clauses as $index => $clause) {
            if (is_string($index)) {
                // Operator is a scope function
                $this->attachScope($query, $index, $clause);

                continue;
            }

            if ($clause->attribute === null) {
                continue;
            }

            if (! $related = $this->model->getRelated($clause->relation)) {
                continue;
            }

            if (! $related->is($query->getModel())) {
                continue;
            }

            $query->where($clause->attribute, $clause->operator->toOperator(), $clause->resolveValue());
        }

        return $query;
    }

    private function attachWhereClause(Builder|Relation &$query, FilterClauseStruct $clause): self
    {
        match ($clause->type) {
            QueryTypeEnum::WHERE, QueryTypeEnum::OR_WHERE, QueryTypeEnum::WHERE_NOT, QueryTypeEnum::OR_WHERE_NOT
                => $query->{$clause->type->value}($clause->attribute, $clause->operator->toOperator(), $clause->resolveValue()),
            QueryTypeEnum::WHERE_IN, QueryTypeEnum::OR_WHERE_IN, QueryTypeEnum::WHERE_NOT_IN, QueryTypeEnum::OR_WHERE_NOT_IN
                => $query->{$clause->type->value}($clause->attribute, $clause->resolveValue()),
        };

        return $this;
    }

    private function attachScope(Builder|Relation &$query, string $scope, mixed $value): self
    {
        if (method_exists($this->model, "scope{$scope}")) {
            $query->{$scope}($value);
        }

        return $this;
    }

    /**
     * Check if the attribute is an attribute on the model. If not, check if it's a valid relation
     *
     * @param  string  $key
     *
     * @return array{}|array{attribute?:string, relation?:string}|null
     * @throws InvalidRelationException
     */
    private function resolveAttributeAndRelation(string $key): ?array
    {
        $attribute = function (RelationInterface&Model $owner, string $name): ?string {
            if (AttributeResolver::verify($owner, $name)) {
                return $name;
            }

            return null;
        };

        $possibleAttribute = $key;
        $owner = $this->model;

        if ($this->model->verifyRelation($key)) {
            // Filter queries on a relation, not an attribute.
            return [null, $key];
        }

        if (str_contains($key, '.')) {
            // Try to resolve the attribute by taking the last attribute in the path.
            [$relation, $possibleAttribute] = split_on_last($key);
            $owner = $this->model->getRelated($relation);

            if (! $owner) {
                throw new InvalidRelationException("A non-existing relationship was requested: $relation", "filter[{$key}]");
            }
        }

        return [$attribute($owner, $possibleAttribute), $relation ?? null];
    }

    private function resolveOperator(string $operator, ?string $relation = null): string|FilterOperatorsEnum|null
    {
        $resolved = FilterOperatorsEnum::tryFrom($operator);
        if ($resolved) {
            return $resolved;
        }

        $owner = $relation ? ($this->model->getRelated($relation) ?? $this->model) : $this->model;

        // If the operator is not in the Enum, check if it's a custom (scope) filter method.
        return method_exists($owner, "scope{$operator}") ? $operator : null;
    }

    private function resolveValue(mixed $value, ?string $attribute = null, ?string $relation = null): mixed
    {
        if ($value === null) {
            // The value was not set in the query parameter and therefore null
            return null;
        }

        $casts = function () use ($relation) {
            if (! $relation) {
                return $this->model->getCasts();
            }

            return $this->model->getRelated($relation)?->getCasts() ?? [];
        };

        if ($attribute === null && $relation) {
            // The value is probably a boolean to query whether a relation exists or not.
            return (new CastPrimitives($value))->castBool();
        }

        return (new CastPrimitives($value, $casts()[ $attribute ] ?? ''))->cast();
    }

    /**
     * @param  mixed                       $value
     * @param  string|FilterOperatorsEnum  $operator
     * @param  string|null                 $queryType  The string that's passed in the request.
     *
     * @return QueryTypeEnum|null
     */
    private function resolveQueryType(mixed $value, string|FilterOperatorsEnum $operator, ?string $queryType = null): ?QueryTypeEnum
    {
        if (is_string($operator)) {
            return QueryTypeEnum::WHERE;
        }

        $where = Str::of('where');

        if ($queryType === 'or') {
            $where = $where->ucfirst()->prepend('or');
        }

        if ($operator === FilterOperatorsEnum::NOT_EQUALS) {
            $where = $where->append('Not');
        }

        if (is_array($value)) {
            $where = $where->append('In');
        }

        return QueryTypeEnum::tryFrom((string) $where);
    }
}