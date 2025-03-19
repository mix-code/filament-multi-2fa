<?php

return [
    'user_model' => \App\Models\User::class,
    'trust_device_model' => \MixCode\FilamentMulti2fa\Models\TrustDevice::class,
    'otp_notification_class' => \MixCode\FilamentMulti2fa\Notifications\TwoFactorCodeNotification::class,
    'otp_view' => 'filament-multi-2fa::emails.2fa.otp',
    'trusted_device_cookie_name' => 'trusted_device',
];
