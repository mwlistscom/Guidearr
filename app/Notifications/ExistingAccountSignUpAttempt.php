<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent instead of a verification code when someone signs up with an address that already has an
 * account. The sign-up form answers exactly as for a new address, so this email is the only place
 * the difference shows — and only the address owner can read it.
 */
class ExistingAccountSignUpAttempt extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $app = config('app.name', 'Guidearr');

        return (new MailMessage)
            ->subject("You already have a {$app} account")
            ->greeting('You already have an account')
            ->line("Someone just tried to create a {$app} account with this email address. An account with this address already exists, so nothing was created and no verification code was sent.")
            ->line('If that was you, sign in instead. If you have forgotten your password, you can reset it from the sign-in page.')
            ->action('Sign in', route('login'))
            ->line('If it was not you, you can ignore this email. Your account has not been changed.');
    }
}
