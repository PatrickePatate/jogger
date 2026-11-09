<?php

declare(strict_types=1);

namespace App\Support\Pg;

use InvalidArgumentException;

/**
 * Encode/decode one-dimensional PostgreSQL text[] literals.
 */
final class PgArray
{
    /**
     * @param  array<int, string|null>  $values
     */
    public static function encode(array $values): string
    {
        $items = array_map(
            static fn (?string $v): string => $v === null
                ? 'NULL'
                : '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $v).'"',
            array_values($values),
        );

        return '{'.implode(',', $items).'}';
    }

    /**
     * @return array<int, string|null>
     */
    public static function decode(string $literal): array
    {
        $literal = trim($literal);

        if ($literal === '' || $literal[0] !== '{' || ! str_ends_with($literal, '}')) {
            throw new InvalidArgumentException("Not a PostgreSQL array literal: {$literal}");
        }

        $body = substr($literal, 1, -1);
        $out = [];
        $length = strlen($body);
        $i = 0;

        while ($i < $length) {
            if ($body[$i] === '"') {
                $i++;
                $value = '';
                while ($i < $length && $body[$i] !== '"') {
                    if ($body[$i] === '\\' && $i + 1 < $length) {
                        $i++;
                    }
                    $value .= $body[$i++];
                }
                $i++; // closing quote
                $out[] = $value;
            } else {
                $start = $i;
                while ($i < $length && $body[$i] !== ',') {
                    $i++;
                }
                $token = substr($body, $start, $i - $start);
                $out[] = strcasecmp($token, 'NULL') === 0 ? null : $token;
            }

            if ($i < $length && $body[$i] === ',') {
                $i++;
            }
        }

        return $out;
    }
}
