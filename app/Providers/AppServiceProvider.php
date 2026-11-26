<?php

namespace App\Providers;

use App\Notifications\ResetPassword;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(\Filament\Auth\Notifications\ResetPassword::class, ResetPassword::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Staging must never load production-like SMTP credentials or log reset tokens.
        if ($this->app->environment('staging')) {
            config(['mail.default' => 'array', 'mail.mailers.array' => ['transport' => 'array']]);

            return;
        }

        $path = storage_path('app/private/transactional-mail.enc');
        if (is_file($path)) {
            $settings = json_decode(Crypt::decryptString(file_get_contents($path)), true, flags: JSON_THROW_ON_ERROR);
            config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp' => [
                    'transport' => 'smtp',
                    'scheme' => ((int) $settings['port'] === 465) ? 'smtps' : 'smtp',
                    'host' => $settings['host'],
                    'port' => (int) $settings['port'],
                    'username' => $settings['username'],
                    'password' => $settings['password'],
                    'timeout' => 15,
                    'require_tls' => true,
                ],
                'mail.from.address' => $settings['from_email'],
                'mail.from.name' => config('app.name'),
            ]);
        }
    }
}
