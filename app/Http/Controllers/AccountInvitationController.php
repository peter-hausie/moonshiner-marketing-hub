<?php

namespace App\Http\Controllers;

use App\Models\AccountInvitation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

class AccountInvitationController extends Controller
{
    public function show(string $token)
    {
        $invite = $this->usable($token);

        return response()->view('accounts.accept', ['token' => $token, 'name' => User::findOrFail($invite->user_id)->name])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function accept(Request $request, string $token)
    {
        $data = $request->validate(['password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()]]);
        DB::transaction(function () use ($token, $data): void {
            $candidate = AccountInvitation::where('token_hash', hash('sha256', $token))->firstOrFail();
            $user = User::lockForUpdate()->findOrFail($candidate->user_id);
            $invite = $this->usable($token, true);
            abort_if($user->active, 403);
            $user->forceFill(['password' => $data['password'], 'active' => true, 'email_verified_at' => now()])->save();
            $invite->forceFill(['accepted_at' => now()])->save();
        });

        return redirect('/admin/login')->with('status', 'Account aktiviert. Du kannst dich jetzt anmelden.');
    }

    private function usable(string $token, bool $lock = false): AccountInvitation
    {
        $query = AccountInvitation::where('token_hash', hash('sha256', $token));
        if ($lock) {
            $query->lockForUpdate();
        }
        $invite = $query->first();
        abort_unless($invite && ! $invite->accepted_at && ! $invite->revoked_at && $invite->expires_at->isFuture(), 403, 'Diese Einladung ist ungültig oder abgelaufen.');

        return $invite;
    }
}
