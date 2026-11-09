<?php

declare(strict_types=1);

namespace App\Domain\Github\Actions;

use App\Domain\Access\Audit;
use App\Domain\Github\Gateway\AppJwt;
use App\Domain\Github\Gateway\GithubHttp;
use App\Domain\Github\Models\GithubApp;
use App\Models\User;
use App\Support\Exceptions\GithubApiError;
use App\Support\Exceptions\GithubAppAlreadyConnected;
use Illuminate\Http\Client\ConnectionException;

/**
 * Connects an app the admin created by hand. The credentials are proven before anything is stored:
 * we sign a JWT with the private key and ask GitHub "who am I?" (GET /app), which must return the same app id.
 */
final class ConnectGithubAppManually
{
    public function __construct(private readonly AppJwt $jwt, private readonly GithubHttp $http) {}

    /**
     * @param  array{app_id: int|string, client_id: string, client_secret: string, webhook_secret: string, private_key: string}  $data
     *
     * @throws GithubAppAlreadyConnected
     * @throws GithubApiError when GitHub rejects the credentials
     */
    public function __invoke(User $admin, array $data): GithubApp
    {
        if (GithubApp::query()->exists()) {
            throw new GithubAppAlreadyConnected;
        }

        $privateKey = $this->normalizePem($data['private_key']);
        if (@openssl_pkey_get_private($privateKey) === false) {
            throw new GithubApiError('That private key is not a valid PEM key. Paste the whole .pem file, including the BEGIN and END lines.', 422);
        }

        $candidate = new GithubApp([
            'app_id' => (int) $data['app_id'],
            'client_id' => trim($data['client_id']),
            'client_secret' => trim($data['client_secret']),
            'private_key' => $privateKey,
            'webhook_secret' => trim($data['webhook_secret']),
            'api_base_url' => (string) config('jogger.github.api_url'),
        ]);

        try {
            $response = $this->http->pending($candidate, $this->jwt->for($candidate))->get('/app');
        } catch (ConnectionException $e) {
            throw $this->http->unreachable($e);
        }

        if ($response->status() === 401) {
            throw new GithubApiError('GitHub rejected these credentials. Check that the private key belongs to this app and the Client ID is correct.', 401);
        }

        if (! $response->successful()) {
            $this->http->failure($response);
        }

        if ((int) $response->json('id') !== $candidate->app_id) {
            throw new GithubApiError('GitHub says this key belongs to a different app than the App ID you entered.', 422);
        }

        $candidate->slug = (string) $response->json('slug');
        $candidate->save();

        Audit::record('github.app_connected', $candidate, ['app_id' => $candidate->app_id, 'slug' => $candidate->slug, 'method' => 'manual'], $admin);

        return $candidate;
    }

    /** Tolerates pasted keys with Windows line endings, surrounding spaces or escaped "\n" sequences. */
    private function normalizePem(string $pem): string
    {
        $pem = str_replace(['\\n', "\r\n", "\r"], "\n", trim($pem));

        return $pem."\n";
    }
}
