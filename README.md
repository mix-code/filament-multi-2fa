# Filament Multi 2FA

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mix-code/filament-multi-2fa?style=flat-square)](https://packagist.org/packages/mix-code/filament-multi-2fa)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/mix-code/filament-multi-2fa/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/mix-code/filament-multi-2fa/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/mix-code/filament-multi-2fa/fix-php-code-style-issues.yml?branch=main&style=flat-square)](https://github.com/mix-code/filament-multi-2fa/actions/workflows/fix-php-code-style-issues.yml?query=workflow%3A%22Fix+PHP+code+styling+issues%22+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/mix-code/filament-multi-2fa.svg?style=flat-square)](https://packagist.org/packages/mix-code/filament-multi-2fa)

Implementing Email OTP and Authenticator App 2FA logic with Trusted Devices support.

## Features

## Installation

You can install the package via composer:

```bash
composer require mix-code/filament-multi-2fa
```

Install plugin configs and migrations:

```bash
php artisan filament-multi-2fa:install
```

Optionally, you can publish the lang files with:

```bash
php artisan vendor:publish --tag="filament-multi-2fa-translations"
```

Optionally, you can publish the views using:

```bash
php artisan vendor:publish --tag="filament-multi-2fa-views"
```

## Usage

### 1️⃣ Register the plugin in your Filament Panel

In your PanelProvider (e.g., AdminPanelProvider):

```php
public function panel(Panel $panel): Panel
{
    return $panel
        // other panel setup...
        ->plugins([
            // ...
            \MixCode\FilamentMulti2fa\FilamentMulti2faPlugin::make(),
        ]);
}
```

Force 2FA setup:

```php
public function panel(Panel $panel): Panel
{
    return $panel
        ->plugins([
            \MixCode\FilamentMulti2fa\FilamentMulti2faPlugin::make()
                ->forceSetup2fa(),
        ]);
}
```

### 2️⃣ Configure User Model

In your `User` model, use the `UsingTwoFA` trait, guarded attributes and casts:

```php
use MixCode\FilamentMulti2fa\Traits\UsingTwoFA;
use MixCode\FilamentMulti2fa\Enums\TwoFactorAuthType;

class User extends Authenticatable
{
    use UsingTwoFA;

    protected $guarded = [
        'two_factor_type',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_sent_at',
        'two_factor_expires_at',
        'two_factor_confirmed_at',
    ];

    protected $casts = [
        'two_factor_type' => TwoFactorAuthType::class,
        'two_factor_sent_at' => 'datetime',
        'two_factor_expires_at' => 'datetime',
        'two_factor_confirmed_at' => 'datetime',
    ];

    // ...
}
```

You can also configure redirection after OTP verification by overriding `redirectAfterVerifyUrl`:

```php
public function redirectAfterVerifyUrl(): ?string
{
    return route('home');
}
```

To list user trusted devices:

```php
$user->trustedDevices();
```

### 3️⃣ Features Automatically Handled by the Plugin:

-   🛡️ 2FA Setup Page (users can select Email OTP or Authenticator App)
-   🔑 OTP Verification Page to protect access based on trusted device and 2FA status
-   🖥 Trusted Device Middleware to check trusted device cookies and enforce OTP verification
-   🔐 Adds a shortcut in the user menu to access the 2FA setup page

### 4️⃣ Automatic Logout Handling

When users log out, the plugin clears their `two_factor_confirmed_at` column automatically:

```php
Event::listen(Logout::class, function ($event) {
    $user = $event->user;

    if ($user) {
        $user->two_factor_confirmed_at = null;
        $user->save();
    }
});
```

### 5️⃣ Customize settings from the config file

```php
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
```

📝 **Notes:**

-   The plugin automatically injects the `CheckTrustedDevice` middleware to protect your Filament admin routes.
-   After OTP verification, users are redirected to the current panel’s dashboard.

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](.github/CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

-   [Mohamed Alaa El-Din Mohamed](https://github.com/mix-code)
-   [Hamza Omar Mohamed](https://github.com/mix-code)
-   [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
