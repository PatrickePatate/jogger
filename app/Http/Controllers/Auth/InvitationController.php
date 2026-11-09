<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Access\Actions\AcceptInvitation;
use App\Domain\Access\Models\Invitation;
use App\Support\Exceptions\InvitationInvalid;
use App\Support\Exceptions\UserAlreadyExists;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/** Both routes are protected by the `signed` middleware (the POST reuses the signed URL). */
final class InvitationController
{
    public function show(Invitation $invitation): View
    {
        abort_unless($invitation->isPending(), 410, 'This invitation is no longer valid.');

        return view('auth.accept-invitation', ['invitation' => $invitation]);
    }

    public function store(Request $request, Invitation $invitation, AcceptInvitation $accept): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string', Password::default(), 'confirmed'],
        ]);

        try {
            $user = $accept($invitation, $data['name'], $data['password']);
        } catch (InvitationInvalid|UserAlreadyExists $e) {
            return redirect()->route('login')->with('status', $e->getMessage().' Try signing in or ask an administrator for a new invitation.');
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('overview');
    }
}
