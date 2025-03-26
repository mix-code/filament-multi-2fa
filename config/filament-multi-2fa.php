<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Models Configuration
    |--------------------------------------------------------------------------
    |
    | Define the models used by the package here. These models will be used
    | for users and trusted device storage.
    |
    */

    'user_model' => \App\Models\User::class,

    'trust_device_model' => \MixCode\FilamentMulti2fa\Models\TrustDevice::class,

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Specify the notification class responsible for sending the OTP code
    | to the user.
    |
    */

    'otp_notification_class' => \MixCode\FilamentMulti2fa\Notifications\TwoFactorCodeNotification::class,

    /*
    |--------------------------------------------------------------------------
    | QR Code Rendering Backend
    |--------------------------------------------------------------------------
    |
    | Choose the QR code rendering service to generate the QR Code for TOTP.
    |
    | Supported Services:
    |
    | 1. BaconQrCode (default):
    |    - Renders PNG (inline or file)
    |    - Requires the Imagick PHP extension (depending on backend)
    |    - Can render SVG with custom setup, but PNG is common default
    |
    | 2. chillerlan/php-qrcode:
    |    - Outputs base64-encoded PNG by default
    |    - Supports SVG rendering via OUTPUT_MARKUP_SVG
    |    - Does NOT require Imagick or GD by default for PNG base64
    |    - Other formats (PNG file, SVG file) may require GD/Imagick if saved to disk
    |
    */

    // Default to BaconQrCode
    'qr_code_backend_service' => \PragmaRX\Google2FAQRCode\QRCode\Bacon::class,

    // To use chillerlan/php-qrcode (SVG by default), uncomment below:
    // 'qr_code_backend_service' => \PragmaRX\Google2FAQRCode\QRCode\Chillerlan::class,

    /*
    |--------------------------------------------------------------------------
    | OTP Settings
    |--------------------------------------------------------------------------
    |
    | OTP Specific Settings From the view of the notification to the resend remains.
    |
    */

    'otp_view' => 'filament-multi-2fa::emails.2fa.otp',

    'otp_resend_time_format' => '%i:%S', // 1:15

    'otp_resend_allowed_after_in_seconds' => 60 * 1, // 1 Minute

    'otp_expiration_in_seconds' => 60 * 10,   // 10 Minute

    /*
    |--------------------------------------------------------------------------
    | Trusted Device Settings
    |--------------------------------------------------------------------------
    |
    | Configure trusted device cookie settings, including cookie name,
    | lifespan in minutes, and cache settings to minimize DB hits.
    |
    */

    'trusted_device_cookie_name' => 'trusted_device',

    // Duration (in minutes) before a trusted device expires in the database
    'trusted_device_db_expiration' => 60 * 24 * 30, // 30 days

    // Duration (in minutes) before a trusted device cookie expires
    'trusted_device_cookie_lifespan' => 60 * 24 * 30, // 30 days

    // Duration (in minutes) to cache trusted device checks to reduce DB queries
    'trust_device_check_cache_lifespan' => 60 * 24 * 30, // 30 days

];
