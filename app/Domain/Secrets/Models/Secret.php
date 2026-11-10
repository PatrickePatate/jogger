<?php

declare(strict_types=1);

namespace App\Domain\Secrets\Models;

use App\Domain\Secrets\Enums\SecretKind;
use App\Domain\Secrets\Enums\SecretScope;
use App\Support\Concerns\HasUuid7;
use Illuminate\Database\Eloquent\Model;

/** A value injected into runner containers (or used to pull their image). Stored encrypted; never shown again. */
class Secret extends Model
{
    use HasUuid7;

    protected $guarded = [];

    protected $hidden = ['value', 'previous_value'];

    protected $casts = [
        'scope_type' => SecretScope::class,
        'kind' => SecretKind::class,
        'value' => 'encrypted',
        'previous_value' => 'encrypted',
        'rotated_at' => 'datetime',
    ];

    /**
     * Every string that must not appear in a log because of this secret.
     *
     * @return list<string>
     */
    public function sensitiveValues(): array
    {
        $values = [];
        foreach ([$this->value, $this->previous_value] as $raw) {
            if (! is_string($raw) || $raw === '') {
                continue;
            }
            $values[] = $this->kind === SecretKind::Registry
                ? (string) (json_decode($raw, true)['password'] ?? '')
                : $raw;
        }

        return array_values(array_filter($values, fn (string $v) => $v !== ''));
    }
}
