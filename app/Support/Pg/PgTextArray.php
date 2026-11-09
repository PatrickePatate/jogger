<?php

declare(strict_types=1);

namespace App\Support\Pg;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Casts a PostgreSQL text[] column to a PHP list of strings.
 *
 * @implements CastsAttributes<array<int, string|null>, array<int, string|null>>
 */
final class PgTextArray implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        return is_array($value) ? array_values($value) : PgArray::decode((string) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        /** @var array<int, string|null> $value */
        return PgArray::encode(array_map(
            static fn (mixed $v): ?string => $v === null ? null : (string) $v,
            (array) $value,
        ));
    }
}
