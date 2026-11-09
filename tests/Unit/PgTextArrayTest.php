<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Pg\PgArray;
use App\Support\Pg\PgTextArray;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PgTextArrayTest extends TestCase
{
    /**
     * @return array<string, array{0: array<int, string|null>}>
     */
    public static function samples(): array
    {
        return [
            'simple' => [['self-hosted', 'linux']],
            'empty list' => [[]],
            'comma' => [['a,b', 'c']],
            'quotes' => [['say "hi"', 'x']],
            'backslash' => [['C:\\temp', 'y']],
            'braces and spaces' => [['{ weird }', ' padded ']],
            'empty string' => [['', 'z']],
            'null element' => [['a', null, 'b']],
            'literal NULL text' => [['NULL']],
        ];
    }

    /**
     * @param  array<int, string|null>  $values
     */
    #[DataProvider('samples')]
    public function test_encode_decode_round_trip(array $values): void
    {
        $this->assertSame($values, PgArray::decode(PgArray::encode($values)));
    }

    public function test_decodes_postgres_style_unquoted_literal(): void
    {
        $this->assertSame(['self-hosted', 'linux', 'x64'], PgArray::decode('{self-hosted,linux,x64}'));
        $this->assertSame([], PgArray::decode('{}'));
    }

    public function test_cast_round_trip_and_null(): void
    {
        $cast = new PgTextArray;
        $model = new class extends Model {};

        $stored = $cast->set($model, 'labels', ['self-hosted', 'a,b'], []);
        $this->assertSame('{"self-hosted","a,b"}', $stored);
        $this->assertSame(['self-hosted', 'a,b'], $cast->get($model, 'labels', $stored, []));
        $this->assertNull($cast->set($model, 'labels', null, []));
        $this->assertNull($cast->get($model, 'labels', null, []));
    }
}
