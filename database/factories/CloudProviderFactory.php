<?php

namespace Database\Factories;

use App\Domain\Cloud\Models\CloudProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CloudProvider>
 */
class CloudProviderFactory extends Factory
{
    protected $model = CloudProvider::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'provider-'.fake()->unique()->lexify('??????'),
            'driver' => 'fake',
            'credentials' => ['token' => 'test-token-'.fake()->lexify('????????')],
            'status' => 'active',
        ];
    }
}
