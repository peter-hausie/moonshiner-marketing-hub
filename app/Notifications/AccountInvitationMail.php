<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountInvitationMail extends Notification
{
    public function __construct(private string $url) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Deine Einladung zum Content Hub')->greeting('Hallo '.$notifiable->name.',')
            ->line('Du wurdest als '.$notifiable->accessLabel().' zum Content Hub eingeladen.')
            ->action('Account aktivieren', $this->url)->line('Lege dein eigenes Passwort fest. Diese Einladung ist 48 Stunden gültig und nur einmal verwendbar.')
            ->line('Falls du diese Einladung nicht erwartet hast, kannst du sie ignorieren.');
    }
}
