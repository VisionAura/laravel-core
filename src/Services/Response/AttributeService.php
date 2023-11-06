<?php

namespace VisionAura\LaravelCore\Services\Response;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use VisionAura\LaravelCore\Exceptions\CoreException;
use VisionAura\LaravelCore\Exceptions\ErrorBag;

final class AttributeService
{
    public bool $hasHiddenAttributes = false;

    protected array $visibleAttributes = ['*'];

    protected array $hiddenAttributes = [];

    public function __construct()
    {
        $fields = request()->query->all('fields') ?? [];

        $this->hasHiddenAttributes = (bool) $fields;

        if (! $this->hasHiddenAttributes) {
            return;
        }

        $this->visibleAttributes = [];

        foreach ($fields as $key => $attribute) {
            $this->visibleAttributes[ $key ] = explode(',', $attribute);
        }
    }

    /**
     * @param  Model   $model  The model to get the attributes from
     * @param  string  $name   The name of the primary resource or the relation.
     *
     * @return string[] The attributes that should be visible. Always includes the primary key. Defaults to ['*']
     */
    public function get(Model $model, string $name): array
    {
        if (! $this->hasHiddenAttributes || ! array_key_exists($name, $this->visibleAttributes)) {
            return ['*'];
        }

        return array_merge($this->visibleAttributes[ $name ], [$model->getKeyName()]);
    }
}