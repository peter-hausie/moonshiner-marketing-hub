<?php

namespace App\Services\Accounts;

use App\Models\AccountInvitation;
use App\Models\User;
use App\Notifications\AccountInvitationMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ManageAccounts
{
    public function invite(User $actor, string $name, string $email, string $access): User
    {
        abort_unless($actor->canManageAccounts(), 403);
        $email = strtolower(trim($email));
        Validator::make(compact('name', 'email', 'access'), ['name' => 'required|string|max:255', 'email' => 'required|email|max:255|unique:users,email', 'access' => 'required|in:admin,curator,contributor'])->validate();
        $user = new User;
        $user->forceFill(['name' => trim($name), 'email' => $email, 'password' => Str::random(64), 'role' => $access === 'contributor' ? 'contributor' : 'curator', 'is_admin' => $access === 'admin', 'active' => false])->save();
        $this->sendInvitation($actor, $user->id);

        return $user;
    }

    public function sendInvitation(User $actor, int $id): void
    {
        abort_unless($actor->canManageAccounts(), 403);
        $plain = Str::random(64);
        [$user,$invitation] = DB::transaction(function () use ($actor, $id, $plain) {
            $user = User::lockForUpdate()->findOrFail($id);
            abort_if($user->active || $user->email_verified_at, 422, 'Nur noch nicht aktivierte Accounts können eingeladen werden.');
            AccountInvitation::where('user_id', $id)->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $invitation = new AccountInvitation;
            $invitation->forceFill(['user_id' => $id, 'invited_by' => $actor->id, 'token_hash' => hash('sha256', $plain), 'expires_at' => now()->addHours(48), 'delivery_status' => 'pending'])->save();

            return [$user, $invitation];
        });
        // Never leak invitation URLs through log mailers or exception reporting.
        $mailer = config('mail.default');
        if (! app()->environment('testing') && ($mailer !== 'smtp' || config('mail.mailers.smtp.transport') !== 'smtp')) {
            $invitation->forceFill(['delivery_status' => 'unavailable'])->save();

            return;
        }
        try {
            $user->notify(new AccountInvitationMail(route('accounts.accept', ['token' => $plain])));
            $invitation->forceFill(['delivery_status' => 'sent'])->save();
        } catch (\Throwable) {
            $invitation->forceFill(['delivery_status' => 'failed'])->save();
        }
    }

    public function update(User $actor, int $id, string $access, bool $active): void
    {
        abort_unless($actor->canManageAccounts(), 403);
        Validator::make(compact('access'), ['access' => 'required|in:admin,curator,contributor'])->validate();
        DB::transaction(function () use ($actor, $id, $access, $active): void {
            // Stable lock order protects the last administrator from concurrent demotions.
            $users = User::orderBy('id')->lockForUpdate()->get();
            $user = $users->firstWhere('id', $id);
            abort_unless($user, 404);
            abort_if($user->id === $actor->id && (! $active || $access !== 'admin'), 422, 'Du kannst dir den eigenen Administratorzugang nicht entziehen.');
            abort_if($user->is_admin && $user->active && (! $active || $access !== 'admin') && $users->where('is_admin', true)->where('active', true)->count() <= 1, 422, 'Mindestens ein aktiver Administrator muss erhalten bleiben.');
            abort_if($active && ! $user->active && AccountInvitation::where('user_id', $id)->exists() && ! $user->email_verified_at, 422, 'Der Account muss zuerst seine Einladung annehmen.');
            $user->forceFill(['role' => $access === 'contributor' ? 'contributor' : 'curator', 'is_admin' => $access === 'admin', 'active' => $active])->save();
            if (! $active) {
                $user->forceFill(['remember_token' => Str::random(60)])->save();
                AccountInvitation::where('user_id', $id)->whereNull('accepted_at')->update(['revoked_at' => now()]);
                if (Schema::hasTable('sessions')) {
                    DB::table('sessions')->where('user_id', $id)->delete();
                }
            }
        });
    }
}
