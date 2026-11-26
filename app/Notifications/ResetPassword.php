<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use LogicException;
use SensitiveParameter;

class ResetPassword extends \Filament\Auth\Notifications\ResetPassword
{
    public function __construct(#[SensitiveParameter] $token)
    {
        parent::__construct($token);
        // Account recovery must not depend on the publishing queue worker.
        $this->onConnection('sync');
    }

    public function toMail($notifiable): MailMessage
    {
        // Never write a usable recovery URL into a production log transport.
        if (app()->environment('production') && ! in_array(config('mail.default'), ['smtp', 'sendmail', 'ses', 'postmark', 'resend'], true)) {
            throw new LogicException('A real transactional mail transport must be configured for account recovery.');
        }

        return parent::toMail($notifiable);
    }
}
