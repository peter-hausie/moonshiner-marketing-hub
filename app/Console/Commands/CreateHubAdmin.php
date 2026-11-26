<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/** First-install provisioning without a seeded shared password or emailed secret. */
class CreateHubAdmin extends Command
{
    protected $signature = 'hub:create-admin';

    protected $description = 'Create an administrator using interactive, hidden password entry';

    public function handle(): int
    {
        $name = $this->ask('Name');
        $email = $this->ask('Email');
        $password = $this->secret('Password (at least 12 characters)');
        $confirmation = $this->secret('Repeat password');
        $validator = Validator::make([
            'name' => $name, 'email' => $email, 'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:12|confirmed',
        ]);
        if ($validator->fails()) {
            // Field errors only; never print submitted input or the password.
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        $user = new User;
        $user->forceFill([
            'name' => $name, 'email' => $email, 'password' => $password,
            'role' => UserRole::Curator, 'active' => true, 'is_admin' => true,
            'email_verified_at' => now(),
        ])->save();
        $this->info('Administrator created. Open /admin to sign in.');

        return self::SUCCESS;
    }
}
