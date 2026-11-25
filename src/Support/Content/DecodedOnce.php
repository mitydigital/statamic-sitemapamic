<?php

namespace MityDigital\Sitemapamic\Support\Content;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Casts\Json;
use Illuminate\Database\Eloquent\Model;
use WeakMap;

/**
 * Laravel's json cast, decoded once per model instead of on every read.
 * The Eloquent driver reads an entry's data several times as it builds the entry, decoding all of its content each time.
 */
class DecodedOnce implements CastsAttributes
{
    /** @var WeakMap<Model, array<string, array{json: string, value: mixed}>>|null freed with each model */
    private static ?WeakMap $decoded = null;

    /**
     * Use this cast for the column, on this model only. A model that casts the column its own way is left alone.
     */
    public static function apply(Model $model, string $column): void
    {
        if (($model->getCasts()[$column] ?? null) === 'json') {
            $model->mergeCasts([$column => self::class]);
        }
    }

    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        self::$decoded ??= new WeakMap;

        // the JSON is compared, in case the column has been set since it was decoded
        $decoded = (self::$decoded[$model] ?? [])[$key] ?? null;
        if ($decoded === null || $decoded['json'] !== $value) {
            $decoded = ['json' => $value, 'value' => $model->fromJson($value)];
            self::$decoded[$model] = [...self::$decoded[$model] ?? [], $key => $decoded];
        }

        return $decoded['value'];
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : Json::encode($value);
    }
}
