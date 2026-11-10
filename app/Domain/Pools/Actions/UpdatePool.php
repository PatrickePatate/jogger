<?php

declare(strict_types=1);

namespace App\Domain\Pools\Actions;

use App\Domain\Access\Audit;
use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Pools\PoolRules;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class UpdatePool
{
    /**
     * Partial update. Where the pool registers (installation, scope) cannot change: create a new pool instead.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function __invoke(RunnerPool $pool, array $input): RunnerPool
    {
        $validator = Validator::make($input, PoolRules::rules($pool, partial: true));
        $validator->after(fn ($v) => PoolRules::after($v, $input, $pool));
        $data = Arr::except($validator->validate(), ['confirm_public']);

        $pool->fill($data);
        $changed = array_keys($pool->getDirty());
        if ($changed !== []) {
            $pool->save();
            Audit::record('pool.updated', $pool, ['name' => $pool->name, 'changed' => $changed]);
        }

        return $pool;
    }
}
