<?php

namespace VisionAura\LaravelCore\Http\Resolvers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use VisionAura\LaravelCore\Interfaces\RelationInterface;

class AttributeResolver
{
    public bool $hasHiddenAttributes = false;

    protected array $visibleAttributes = ['*'];

    /** @var string[] $force */
    protected array $force = [];

    public function __construct()
    {
        $fields = request()->query->all('fields') ?? [];

        $this->hasHiddenAttributes = (bool) $fields;

        if (! $this->hasHiddenAttributes) {
            return;
        }

        $this->visibleAttributes = [];

        foreach ($fields as $field => $attributes) {
            foreach (explode(',', $attributes) as $attribute) {
                $this->visibleAttributes[ $field ][] = $attribute;
            }
        }
    }

    /**
     * @param  string|null  $from  The attributes from the model to retrieve
     *
     * @return string[]
     */
    public function getVisibleAttributes(string $from = null): array
    {
        if (! $from || ! array_key_exists($from, $this->visibleAttributes)) {
            return $this->visibleAttributes;
        }

        return $this->visibleAttributes[ $from ];
    }

    /**
     * @param  RelationInterface&Model  $model  The model to get the attributes from
     * @param  string                   $name   The name of the primary resource or the relation.
     *
     * @return string[] The attributes that should be visible. Always includes the primary key. Defaults to ['*']
     */
    public function get(RelationInterface&Model $model, string $name): array
    {
        return $this->resolve($model, $name);
    }

    /**
     * Queries the database to retrieve a list of columns and verifies the attribute is listed.
     *
     * @param  Model&RelationInterface  $model      The model to verify the attributes on.
     * @param  string                   $attribute  The attribute to filter for.
     *
     * @return bool
     */
    public static function verify(RelationInterface&Model $model, string $attribute): bool
    {
        return in_array($attribute, Schema::getColumnListing($model->getTable()));
    }

    /**
     * @param  RelationInterface&Model  $model  The model to get the attributes from
     * @param  string                   $name   The name of the primary resource or the relation.
     *
     * @return array<int, string> The attributes that should be visible, prefixed with the name. Always includes the primary key. Defaults to ['*']
     */
    public function getQualified(RelationInterface&Model $model, string $name): array
    {
        $attributes = $this->resolve($model, $name);

        // Prefix every value with the name of the table.
        return preg_filter('/^/', "$name.", $attributes);
    }

    /** @return array{}|array<string[]> */
    public function getForced(?string $name = null): array
    {
        return Arr::wrap(Arr::get($this->force, $name, []));
    }

    /**
     * @param  string           $name
     * @param  string[]|string  $attributes
     *
     * @return self
     */
    public function setForced(string $name, array|string $attributes): self
    {
        $visible = $this->getVisibleAttributes($name);

        foreach (Arr::wrap($attributes) as $attribute) {
            if (! in_array($attribute, $visible)) {
                $this->force[ $name ][] = $attribute;
            }
        }

        return $this;
    }

    /**
     * @param  Model              $owner      The model the foreign key exists on.
     * @param  RelationInterface  $parent     The parent of the relationship.
     * @param  string             $parentKey  The key of the parent in the attributes array.
     * @param  string             $relation   The name of the relation.
     * @param  array|string       $attribute  The foreign key that should be saved to the forced attributes.
     * @param  null|string        $nestedKey  A custom name as the attribute key.
     *
     * @return void
     */
    public function forceDependentKeys(
        Model $owner,
        RelationInterface $parent,
        string $parentKey,
        string $relation,
        array|string $attribute,
        ?string $nestedKey = null
    ): void {
        // Check if the owner of the foreign key is the child.
        if ($parent->{$relation}()->getRelated() instanceof $owner) {
            $this->setForced($nestedKey ?? $relation, $attribute);

            return;
        }

        // If the owner is not the child, it is assumed it is the parent.
        $this->setForced($nestedKey ?? $parentKey, $attribute);
    }

    /**
     * Retrieve the (forced) attributes of the given name. Always includes the primary key
     *
     * @return array<int, string>
     */
    protected function resolve(RelationInterface&Model $model, string $name): array
    {
        if (! $this->hasHiddenAttributes || ! array_key_exists($name, $this->visibleAttributes)) {
            return [$name];
        }

        return array_merge($this->visibleAttributes[ $name ], [$model->getKeyName()], $this->getForced($name));
    }
}
