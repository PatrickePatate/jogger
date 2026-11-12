<?php

declare(strict_types=1);

namespace App\Domain\Pools;

use App\Domain\Github\Enums\ScopeType;
use App\Domain\Github\Models\Installation;
use App\Domain\Github\Models\Repository;
use App\Domain\Pools\Enums\DockerMode;
use App\Domain\Pools\Models\RunnerPool;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** One definition of a valid pool, shared by the form and the API. Keys are the database column names. */
final class PoolRules
{
    public const LABEL = '/^[A-Za-z0-9._-]{1,64}$/';

    /** registry/path:tag or @sha256 digest; no spaces, no shell characters. */
    public const IMAGE = '/^[a-z0-9]([a-z0-9._\/-]*[a-z0-9])?(:[0-9]+\/[a-z0-9._\/-]+)?(:[A-Za-z0-9_][A-Za-z0-9._-]{0,127})?(@sha256:[a-f0-9]{64})?$/';

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(?RunnerPool $pool = null, bool $partial = false): array
    {
        $need = $partial ? 'sometimes' : 'required';

        return [
            'installation_id' => $pool ? ['prohibited'] : ['required', 'uuid', Rule::exists('installations', 'id')],
            'name' => [$need, 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9 ._-]*$/'],
            'scope' => $pool ? ['prohibited'] : ['required', Rule::enum(ScopeType::class)],
            'scope_target' => $pool ? ['prohibited'] : ['required', 'string', 'max:200', 'regex:#^[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)?$#'],
            'runner_group_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'labels' => [$need, 'array', 'min:1', 'max:20'],
            'labels.*' => ['string', 'distinct', 'regex:'.self::LABEL],
            'image' => [$need, 'string', 'max:300', 'regex:'.self::IMAGE],
            'cpu_limit' => ['sometimes', 'nullable', 'numeric', 'min:0.1', 'max:256'],
            'memory_limit_mb' => ['sometimes', 'nullable', 'integer', 'min:128', 'max:1048576'],
            'min_idle' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'max_runners' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'idle_ttl_seconds' => ['sometimes', 'integer', 'min:60', 'max:86400'],
            'docker_mode' => ['sometimes', Rule::enum(DockerMode::class)],
            'persist_tool_cache' => ['sometimes', 'boolean'],
            'allow_sudo' => ['sometimes', 'boolean'],
            'allowed_host_labels' => ['sometimes', 'array', 'max:20'],
            'allowed_host_labels.*' => ['string', 'distinct', 'regex:'.self::LABEL],
            'status' => ['sometimes', Rule::in(['active', 'paused'])],
            'confirm_public' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Cross-field checks: capacity, scope vs installation, name uniqueness, and the public-repository confirmation.
     *
     * @param  array<string, mixed>  $data
     */
    public static function after(Validator $validator, array $data, ?RunnerPool $pool = null): void
    {
        $min = (int) ($data['min_idle'] ?? $pool->min_idle ?? 0);
        $max = (int) ($data['max_runners'] ?? $pool->max_runners ?? 5);
        if ($max < $min) {
            $validator->errors()->add('max_runners', 'The maximum must be at least the number of idle runners kept warm.');
        }

        $installationId = $pool->installation_id ?? ($data['installation_id'] ?? null);
        if (isset($data['name']) && is_string($installationId)) {
            $taken = RunnerPool::query()->where('installation_id', $installationId)->where('name', $data['name'])
                ->when($pool, fn ($q) => $q->whereKeyNot($pool->id))->exists();
            if ($taken) {
                $validator->errors()->add('name', 'This installation already has a pool with that name.');
            }
        }

        if ($pool !== null || $validator->errors()->isNotEmpty()) {
            return;
        }

        $installation = Installation::query()->find($installationId);
        $target = (string) $data['scope_target'];
        if ($data['scope'] === ScopeType::Organization->value) {
            if ($installation === null || strcasecmp($installation->account_login, $target) !== 0) {
                $validator->errors()->add('scope_target', 'An organization pool must target the account the app is installed on.');
            }

            return;
        }

        $repository = Repository::query()->where('installation_id', $installationId)->where('full_name', $target)->first();
        if ($repository === null) {
            $validator->errors()->add('scope_target', 'That repository is not available to this installation.');
        } elseif (! $repository->private && ! ($data['confirm_public'] ?? false)) {
            $validator->errors()->add('confirm_public', self::publicWarning($target));
        }
    }

    public static function publicWarning(string $repository): string
    {
        return "{$repository} is public: anyone who can open a pull request could run code on your hosts. Confirm that you accept this risk.";
    }
}
