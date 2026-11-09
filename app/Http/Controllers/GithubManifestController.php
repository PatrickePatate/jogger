<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Github\Actions\CreateGithubAppFromManifest;
use App\Domain\Github\GithubAppManifest;
use App\Models\User;
use App\Support\Exceptions\JoggerException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * GitHub App manifest flow. Both routes are admin-only.
 *   1. redirect(): hands the browser to GitHub with the manifest (an auto-submitting POST form).
 *   2. callback(): GitHub sends the browser back with a one-time `code`; we verify our `state`, exchange the code, store the app.
 */
final class GithubManifestController
{
    private const SESSION_KEY = 'github.manifest';

    /** GitHub gives up on the whole flow after an hour; we expire our state a little earlier. */
    private const STATE_LIFETIME_MINUTES = 55;

    public function redirect(Request $request): View
    {
        $data = $request->validate([
            'account_type' => ['required', 'in:personal,organization'],
            'organization' => ['required_if:account_type,organization', 'nullable', 'string', 'max:39', 'regex:/^[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?$/'],
            'app_name' => ['nullable', 'string', 'max:'.GithubAppManifest::MAX_NAME_LENGTH],
        ]);

        $state = Str::random(40);
        $request->session()->put(self::SESSION_KEY, ['state' => $state, 'expires_at' => now()->addMinutes(self::STATE_LIFETIME_MINUTES)->timestamp]);

        $web = rtrim((string) config('jogger.github.web_url'), '/');
        $target = $data['account_type'] === 'organization'
            ? "{$web}/organizations/{$data['organization']}/settings/apps/new"
            : "{$web}/settings/apps/new";

        return view('github.manifest-redirect', [
            'action' => $target.'?state='.$state,
            'manifest' => json_encode(GithubAppManifest::build($data['app_name'] ?? null), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
    }

    public function callback(Request $request, CreateGithubAppFromManifest $create): RedirectResponse
    {
        $stored = $request->session()->pull(self::SESSION_KEY);
        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');

        $stateOk = is_array($stored)
            && $state !== ''
            && hash_equals((string) $stored['state'], $state)
            && ($stored['expires_at'] ?? 0) >= now()->timestamp;

        if (! $stateOk) {
            return $this->back('error', 'The GitHub connection could not be verified (it may have expired, or was started in another browser session). Please start again.');
        }

        if ($code === '') {
            return $this->back('error', 'GitHub did not send a setup code. If you cancelled on GitHub, you can start again.');
        }

        try {
            /** @var User $admin */
            $admin = $request->user();
            $app = $create($admin, $code);
        } catch (JoggerException $e) {
            return $this->back('error', $e->getMessage());
        }

        return $this->back('status', "GitHub App “{$app->slug}” connected. Now install it on your account.");
    }

    private function back(string $type, string $message): RedirectResponse
    {
        return redirect()->route('github.connect')->with($type, $message);
    }
}
