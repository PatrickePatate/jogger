<?php

declare(strict_types=1);

namespace App\Domain\Pools\Actions;

use App\Domain\Access\Audit;
use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Pools\PoolRules;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class CreatePool
{
    /**
     * @param  array<string, mixed>  $input  column => value, see PoolRules
     *
     * @throws ValidationException
     */
    public function __invoke(array $input): RunnerPool
    {
        $validator = Validator::make($input, PoolRules::rules());
        $validator->after(fn ($v) => PoolRules::after($v, $input));
        $data = $validator->validate();

        $pool = RunnerPool::query()->create(Arr::except($data, ['confirm_public']));
        Audit::record('pool.created', $pool, ['name' => $pool->name, 'public_confirmed' => (bool) ($data['confirm_public'] ?? false)]);

        return $pool->refresh();
    }
}
