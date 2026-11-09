<?php

namespace Database\Factories;

use App\Domain\Github\Models\GithubApp;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GithubApp>
 */
class GithubAppFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'app_id' => fake()->unique()->numberBetween(100_000, 9_999_999),
            'slug' => 'jogger-'.Str::lower(Str::random(6)),
            'client_id' => 'Iv1.'.Str::lower(Str::random(16)),
            'client_secret' => Str::random(40),
            'private_key' => "-----BEGIN RSA PRIVATE KEY-----\nnot-a-real-key-".Str::random(24)."\n-----END RSA PRIVATE KEY-----",
            'webhook_secret' => Str::random(32),
            'api_base_url' => GithubApp::DEFAULT_API_URL,
        ];
    }

    /** A genuinely usable RSA key (for tests that sign a JWT). Slower: generates a 2048-bit key. */
    public function withRealKey(): static
    {
        return $this->state(function () {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $pem);

            return ['private_key' => $pem];
        });
    }
}
