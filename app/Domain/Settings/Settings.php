<?php

declare(strict_types=1);

namespace App\Domain\Settings;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Instance settings an administrator may change in the UI. Each one overrides a config key, so the rest of the
 * code keeps reading `config()`; a setting that was never saved leaves the config (and its env variable) in charge.
 */
final class Settings
{
    private const string CACHE_KEY = 'jogger:settings';

    /** setting => [config key, validation rules] */
    public const array KEYS = [
        'instance_name' => ['app.name', ['required', 'string', 'max:60']],
        'log_retention_days' => ['jogger.log_retention_days', ['required', 'integer', 'min:1', 'max:3650']],
        'job_retention_days' => ['jogger.job_retention_days', ['required', 'integer', 'min:1', 'max:3650']],
        'delivery_retention_days' => ['jogger.delivery_retention_days', ['required', 'integer', 'min:1', 'max:365']],
        'default_runner_image' => ['jogger.default_runner_image', ['required', 'string', 'max:300', 'regex:/^[\w.\-\/:@]+$/']],
        'build_cache_max_gb' => ['jogger.build_cache_max_gb', ['required', 'numeric', 'min:0', 'max:10000']],
    ];

    /** Lays the saved values over the config. Called once per request / command at boot. */
    public function apply(): void
    {
        foreach ($this->stored() as $key => $value) {
            if (isset(self::KEYS[$key])) {
                config([self::KEYS[$key][0] => $value]);
            }
        }
    }

    public function get(string $key): mixed
    {
        return config(self::KEYS[$key][0]);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, array{mixed, mixed}> what changed: key => [old, new]
     */
    public function set(array $values): array
    {
        $changed = [];
        foreach (array_intersect_key($values, self::KEYS) as $key => $value) {
            if ($this->get($key) == $value) {
                continue;
            }
            $changed[$key] = [$this->get($key), $value];
            DB::table('settings')->upsert(['key' => $key, 'value' => json_encode($value), 'created_at' => now(), 'updated_at' => now()], ['key'], ['value', 'updated_at']);
        }
        Cache::forget(self::CACHE_KEY);
        $this->apply();

        return $changed;
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        try {
            return Cache::rememberForever(self::CACHE_KEY, fn () => DB::table('settings')->pluck('value', 'key')
                ->map(fn ($v) => json_decode((string) $v, true))->all());
        } catch (Throwable) {
            return []; // before the first migration, or with the database down: config alone
        }
    }
}
