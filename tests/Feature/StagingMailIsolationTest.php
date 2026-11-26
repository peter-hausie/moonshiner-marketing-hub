<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Mail\Transport\ArrayTransport;
use Tests\TestCase;

class StagingMailIsolationTest extends TestCase
{
    public function test_staging_discards_mail_before_reading_encrypted_credentials(): void
    {
        $originalStorage = $this->app->storagePath();
        $temporary = sys_get_temp_dir().'/chub-staging-'.bin2hex(random_bytes(8));
        mkdir($temporary.'/app/private', 0700, true);
        file_put_contents($temporary.'/app/private/transactional-mail.enc', 'invalid-ciphertext-must-not-be-read');
        $this->app->useStoragePath($temporary);
        $this->app->instance('env', 'staging');
        config(['mail.default' => 'smtp', 'mail.mailers.array.transport' => 'smtp']);

        try {
            (new AppServiceProvider($this->app))->boot();
            $this->assertSame('array', config('mail.default'));
            $this->assertSame('array', config('mail.mailers.array.transport'));
            $this->assertInstanceOf(ArrayTransport::class, app('mailer')->getSymfonyTransport());
        } finally {
            $this->app->useStoragePath($originalStorage);
            unlink($temporary.'/app/private/transactional-mail.enc');
            rmdir($temporary.'/app/private');
            rmdir($temporary.'/app');
            rmdir($temporary);
        }
    }
}
