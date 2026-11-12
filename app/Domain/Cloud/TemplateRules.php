<?php

declare(strict_types=1);

namespace App\Domain\Cloud;

use App\Domain\Cloud\Models\HostTemplate;
use App\Domain\Pools\PoolRules;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** One definition of a valid host template. Keys are the database column names; pool_ids is the pivot sync. */
final class TemplateRules
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(?HostTemplate $template = null): array
    {
        return [
            'name' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9 ._-]*$/', Rule::unique('host_templates', 'name')->ignore($template?->getKey())],
            'cloud_provider_id' => ['required', 'uuid', Rule::exists('cloud_providers', 'id')],
            'server_type' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9._-]+$/'],
            'location' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9._-]+$/'],
            'image' => ['required', 'string', 'max:200', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:\/-]*$/'],
            'labels' => ['required', 'array', 'min:1', 'max:20'],
            'labels.*' => ['string', 'distinct', 'regex:'.PoolRules::LABEL],
            'max_runners_per_host' => ['required', 'integer', 'min:1', 'max:500'],
            'warm_hosts' => ['required', 'integer', 'min:0', 'max:100'],
            'max_hosts' => ['required', 'integer', 'min:1', 'max:100'],
            'idle_seconds' => ['required', 'integer', 'min:60', 'max:86400'],
            'max_lifetime_seconds' => ['required', 'integer', 'min:600', 'max:604800'],
            'ipv4' => ['required', 'boolean'],
            'hourly_billing' => ['required', 'boolean'],
            'hourly_price' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'pool_ids' => ['present', 'array', 'max:200'],
            'pool_ids.*' => ['uuid', 'distinct', Rule::exists('runner_pools', 'id')],
        ];
    }

    /**
     * Cross-field checks: the maximum must cover the warm servers.
     *
     * @param  array<string, mixed>  $data
     */
    public static function after(Validator $validator, array $data, ?HostTemplate $template = null): void
    {
        if ($validator->errors()->has('max_hosts') || $validator->errors()->has('warm_hosts')) {
            return;
        }

        $warm = (int) ($data['warm_hosts'] ?? ($template === null ? 0 : $template->warm_hosts));
        $max = (int) ($data['max_hosts'] ?? ($template === null ? 1 : $template->max_hosts));
        if ($max < $warm) {
            $validator->errors()->add('max_hosts', 'The maximum must be at least the number of warm servers.');
        }
    }
}
