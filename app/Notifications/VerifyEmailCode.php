<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

class VerifyEmailCode extends Notification
{
    public function __construct(public string $code) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your '.config('app.name', 'Guidearr').' verification code')
            ->greeting('Verify your email')
            ->line('Use this code to finish creating your account:')
            ->line(new HtmlString(
                '<div style="font-size:30px;letter-spacing:8px;font-weight:700;text-align:center;margin:12px 0">'
                .e($this->code).'</div>'
            ))
            ->line('This code expires in 15 minutes. If you did not request it, you can ignore this email.');
    }
}
