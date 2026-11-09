<?php

declare(strict_types=1);

namespace App\Domain\Github\Actions;

use App\Domain\Access\Audit;
use App\Domain\Github\Gateway\GithubHttp;
use App\Domain\Github\Models\GithubApp;
use App\Models\User;
use App\Support\Exceptions\GithubApiError;
use App\Support\Exceptions\GithubAppAlreadyConnected;
use Illuminate\Http\Client\ConnectionException;

/**
 * Last step of the manifest flow: exchanges the one-time `code` GitHub put on the redirect for the
 * new app's id, private key, client secret and webhook secret, and stores them (encrypted).
 */
final class CreateGithubAppFromManifest
{
    public function __construct(private readonly GithubHttp $http) {}

    /**
     * @throws GithubAppAlreadyConnected
     * @throws GithubApiError when the code is invalid, expired (they last an hour) or GitHub is unreachable
     */
    public function __invoke(User $admin, string $code): GithubApp
    {
        if (GithubApp::query()->exists()) {
            throw new GithubAppAlreadyConnected;
        }

        // The code goes into a URL path: refuse anything that is not a plain token.
        if (preg_match('/^[A-Za-z0-9_-]{8,128}$/', $code) !== 1) {
            throw new GithubApiError('That setup code is not valid. Start the connection again.', 422);
        }

        $apiUrl = (string) config('jogger.github.api_url');

        try {
            $response = $this->http->unauthenticated($apiUrl)->post("/app-manifests/{$code}/conversions");
        } catch (ConnectionException $e) {
            throw $this->http->unreachable($e);
        }

        if (in_array($response->status(), [404, 422], true)) {
            throw new GithubApiError('That setup code is invalid or has expired (codes last one hour). Start the connection again.', $response->status());
        }

        if (! $response->successful()) {
            $this->http->failure($response);
        }

        $body = $response->json();
        foreach (['id', 'slug', 'client_id', 'client_secret', 'webhook_secret', 'pem'] as $field) {
            if (empty($body[$field])) {
                throw new GithubApiError("GitHub's response was missing “{$field}”. Nothing was saved; start again.", $response->status());
            }
        }

        $app = GithubApp::create([
            'app_id' => $body['id'],
            'slug' => $body['slug'],
            'client_id' => $body['client_id'],
            'client_secret' => $body['client_secret'],
            'private_key' => $body['pem'],
            'webhook_secret' => $body['webhook_secret'],
            'api_base_url' => $apiUrl,
        ]);

        Audit::record('github.app_connected', $app, ['app_id' => $app->app_id, 'slug' => $app->slug, 'method' => 'manifest'], $admin);

        return $app;
    }
}
