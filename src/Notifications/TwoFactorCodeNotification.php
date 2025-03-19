<?php

namespace MixCode\FilamentMulti2fa\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TwoFactorCodeNotification extends Notification
{
    use Queueable;

    public function __construct(public $user) {}

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject(trans('filament-multi-2fa::filament-multi-2fa.your_verification_code'))
            ->markdown(config('filament-multi-2fa.otp_view'), ['user' => $this->user]);
    }
}
