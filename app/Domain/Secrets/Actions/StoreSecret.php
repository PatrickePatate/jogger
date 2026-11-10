<?php

declare(strict_types=1);

namespace App\Domain\Secrets\Actions;

use App\Domain\Access\Audit;
use App\Domain\Secrets\Enums\SecretKind;
use App\Domain\Secrets\Enums\SecretScope;
use App\Domain\Secrets\Models\Secret;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/** Creates a secret, or rotates the one that already has this scope, kind and name. New runners get the new value. */
final class StoreSecret
{
    public const int MAX_BYTES = 65536;

    /** Set by the runner itself; overriding them breaks it. */
    private const array RESERVED_PREFIXES = ['GITHUB_', 'RUNNER_', 'ACTIONS_'];

    public function __invoke(SecretScope $scope, ?string $scopeId, SecretKind $kind, string $name, string $value): Secret
    {
        $name = trim($name);
        $this->validate($kind, $name, $value);

        $secret = Secret::query()->firstOrNew([
            'scope_type' => $scope->value,
            'scope_id' => $scope === SecretScope::Global ? null : $scopeId,
            'kind' => $kind->value,
            'name' => $name,
        ]);
        $rotating = $secret->exists;
        if ($rotating) {
            $secret->previous_value = $secret->value;
            $secret->rotated_at = now();
        }
        $secret->value = $value;
        $secret->updated_by = Auth::id();
        $secret->save();

        Cache::forget(ResolveSecretsForPool::MASK_VERSION);
        // The value is deliberately absent from the audit trail.
        Audit::record($rotating ? 'secret.rotated' : 'secret.created', $secret, ['name' => $name, 'scope' => $scope->value, 'kind' => $kind->value]);

        return $secret;
    }

    private function validate(SecretKind $kind, string $name, string $value): void
    {
        $errors = [];
        if ($value === '') {
            $errors['value'] = 'Enter a value.';
        } elseif (strlen($value) > self::MAX_BYTES) {
            $errors['value'] = 'The value may not be larger than 64 KB.';
        }

        if ($kind === SecretKind::Env) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,99}$/', $name) !== 1) {
                $errors['name'] = 'Use letters, digits and underscores, not starting with a digit.';
            } elseif ($name === 'JIT_CONFIG' || array_any(self::RESERVED_PREFIXES, fn (string $p) => str_starts_with(strtoupper($name), $p))) {
                $errors['name'] = 'This name is reserved for the runner.';
            }
        } elseif (preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:\d{1,5})?$/i', $name) !== 1 || strlen($name) > 100) {
            $errors['name'] = 'Enter the registry host, for example ghcr.io.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
