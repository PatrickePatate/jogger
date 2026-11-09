<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Pg\PgTextArray;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PgTextArrayDatabaseTest extends TestCase
{
    public function test_values_survive_a_real_postgres_text_array_column(): void
    {
        DB::statement('CREATE TEMPORARY TABLE pg_array_samples (id serial primary key, labels text[])');

        $model = new class extends Model
        {
            protected $table = 'pg_array_samples';

            public $timestamps = false;

            protected $guarded = [];

            protected function casts(): array
            {
                return ['labels' => PgTextArray::class];
            }
        };

        $labels = ['self-hosted', 'a,b', 'say "hi"', 'C:\\temp', '{x}', ''];
        $row = $model->newQuery()->create(['labels' => $labels]);

        $this->assertSame($labels, $model->newQuery()->findOrFail($row->id)->labels);

        $match = $model->newQuery()->whereRaw('labels @> ?::text[]', ['{"a,b","self-hosted"}'])->count();
        $this->assertSame(1, $match);
    }
}
