<?php

namespace VisionAura\LaravelCore\Enums;

enum FilterOperatorsEnum: string
{
    case EQUALS = 'equals';
    case NOT_EQUALS = 'not_equals';
    case LT = 'lt';
    case GT = 'gt';
    case LE = 'lq';
    case GE = 'ge';
    case STARTS_WITH = 'starts_with';
    case ENDS_WITH = 'ends_with';
    case SEARCH = 'search';

    public function toOperator(): string
    {
        return match ($this) {
            self::EQUALS => '=',
            self::NOT_EQUALS => '!=',
            self::LT => '<',
            self::GT => '>',
            self::LE => '<=',
            self::GE => '>=',
            self::STARTS_WITH, self::ENDS_WITH, self::SEARCH => 'LIKE',
        };
    }
}
