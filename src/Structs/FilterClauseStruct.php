<?php

namespace VisionAura\LaravelCore\Structs;

use VisionAura\LaravelCore\Http\Enums\FilterOperatorsEnum;
use VisionAura\LaravelCore\Http\Enums\QueryTypeEnum;

final readonly class FilterClauseStruct
{
    public function __construct(
        public QueryTypeEnum $type,
        public mixed $value,
        public ?string $relation = null,
        public ?string $attribute = null,
        public FilterOperatorsEnum $operator = FilterOperatorsEnum::EQUALS,
    ) {
        //
    }

    public function resolveValue(): mixed
    {
        return match ($this->operator) {
            FilterOperatorsEnum::SEARCH => "%{$this->value}%",
            FilterOperatorsEnum::STARTS_WITH => "{$this->value}%",
            FilterOperatorsEnum::ENDS_WITH => "%{$this->value}",
            default => $this->value
        };
    }
}