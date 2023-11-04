<?php

namespace VisionAura\LaravelCore\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class CoreCollection extends ResourceCollection
{
    /** @inheritDoc */
    public function toArray(Request $request): array
    {
        if ($this->collection->isEmpty()) {
            return [];
        }

        return [
            'data' => $this->collection,
            'meta' => [
                'count' => $this->collection->count()
            ]
        ];
    }
}
