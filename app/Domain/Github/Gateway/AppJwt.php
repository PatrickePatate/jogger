<?php

declare(strict_types=1);

namespace App\Domain\Github\Gateway;

use App\Domain\Github\Models\GithubApp;
use App\Support\Exceptions\GithubApiError;
use Carbon\CarbonImmutable;
use Firebase\JWT\JWT;
use Throwable;

/**
 * Short-lived RS256 token proving we are the GitHub App (used only to mint installation tokens).
 *
 * @throws GithubApiError when the stored private key cannot sign
 */
final class AppJwt
{
    /** GitHub caps JWT lifetime at 10 minutes; stay a little under it. */
    private const LIFETIME_SECONDS = 540;

    /** GitHub recommends back-dating `iat` by 60 s to absorb clock drift. */
    private const BACKDATE_SECONDS = 60;

    public function for(GithubApp $app): string
    {
        $now = CarbonImmutable::now()->timestamp;

        try {
            return JWT::encode([
                'iat' => $now - self::BACKDATE_SECONDS,
                'exp' => $now + self::LIFETIME_SECONDS,
                'iss' => $app->client_id, // the client ID is what GitHub recommends (the app ID also works)
            ], $app->private_key, 'RS256');
        } catch (Throwable) {
            // Corrupt or truncated key material surfaces as DomainException/TypeError deep inside OpenSSL.
            throw new GithubApiError('Jogger cannot sign requests with the stored GitHub App private key. Check the key, or reconnect the app.');
        }
    }
}
