<?php

namespace App\Http\Controllers;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The link in an invitation e-mail. New people choose a name and password here (the panel then asks them to set up
 * 2FA); existing members sign in with the invited address and accept.
 */
class InvitationController extends Controller
{
    public function show(Request $request, string $token): View|RedirectResponse
    {
        $invitation = Invitation::findOpen($token);
        if ($invitation === null) {
            return view('invitations.show', ['state' => 'invalid']);
        }

        $existing = $invitation->existingUser();
        if ($existing === null) {
            return view('invitations.show', ['state' => 'register', 'invitation' => $invitation, 'token' => $token]);
        }

        if (! Auth::check()) {
            $request->session()->put('url.intended', $request->fullUrl());

            return view('invitations.show', ['state' => 'login', 'invitation' => $invitation]);
        }

        return view('invitations.show', [
            'state' => Auth::user()->is($existing) ? 'accept' : 'wrong-user',
            'invitation' => $invitation,
            'token' => $token,
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = Invitation::findOpen($token);
        abort_if($invitation === null, 404);

        $user = $invitation->existingUser();
        if ($user === null) {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', 'string', 'min:10', 'confirmed'],
            ], [], ['name' => 'Ad soyad', 'password' => 'Şifre']);

            $user = DB::transaction(function () use ($invitation, $data) {
                $user = User::create([
                    'name' => $data['name'],
                    'email' => $invitation->email,
                    'password' => $data['password'],
                    'role' => 'specialist',
                ]);
                $user->forceFill(['email_verified_at' => now()])->save();

                return $user;
            });
            Auth::login($user);
            $request->session()->regenerate();
        } else {
            abort_unless(Auth::user()?->is($user), 403);
        }

        $invitation->accept($user);

        return redirect()->route('filament.admin.resources.projects.index');
    }
}
