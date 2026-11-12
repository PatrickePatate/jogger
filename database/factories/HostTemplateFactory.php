<?php

namespace Database\Factories;

use App\Domain\Cloud\Models\CloudProvider;
use App\Domain\Cloud\Models\HostTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HostTemplate>
 */
class HostTemplateFactory extends Factory
{
    protected $model = HostTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cloud_provider_id' => CloudProvider::factory(),
            'name' => 'template-'.fake()->unique()->lexify('??????'),
            'server_type' => 'cx22',
            'location' => 'fsn1',
            'image' => 'ubuntu-24.04',
            'labels' => ['self-hosted', 'linux', 'x64'],
            'max_runners_per_host' => 1,
            'warm_hosts' => 0,
            'max_hosts' => 1,
            'idle_seconds' => 300,
            'max_lifetime_seconds' => 86400,
            'ipv4' => true,
            'hourly_billing' => true,
            'hourly_price' => null,
            'options' => [],
            'status' => 'active',
        ];
    }
}
