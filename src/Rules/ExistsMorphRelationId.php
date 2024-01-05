<?php

namespace VisionAura\LaravelCore\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;
use Ramsey\Collection\Exception\InvalidPropertyOrMethod;
use VisionAura\LaravelCore\Interfaces\RelationInterface;
use VisionAura\LaravelCore\Support\Facades\RequestController;
use VisionAura\LaravelCore\Support\Relations\MorphTarget;

class ExistsMorphRelationId implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  \Closure(string): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $typeAttribute = str_replace('id', 'type', $attribute);

        if (! request()->has($typeAttribute)) {
            return;
        }

        $type = request()->all($typeAttribute);
        $relation = Arr::get($type, $typeAttribute);

        $model = RequestController::getModel();

        if (! $model->verifyRelation($relation)) {
            return;
        }

        if (! $model->{$relation}() instanceof MorphTarget) {
            return;
        }

        $target = $model->{$relation}()->getTarget();

        if (! $target->query()->where($target->getKeyName(), $value)->exists()) {
            $fail('validation.exists')->translate([
                'attribute' => $attribute,
            ]);
        }
    }
}
