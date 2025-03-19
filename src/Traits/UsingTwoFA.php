<?php

namespace MixCode\FilamentMulti2fa\Traits;

use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FA\Google2FA;

trait UsingTwoFA
{
    public function trustedDevices()
    {
        return $this->hasMany(config('filament-multi-2fa.trust_device_model'), 'user_id')->withoutGlobalScopes();
    }

    public function redirectAfterVerifyUrl(): ?string
    {
        return null;
    }

    public function generateTwoFactorOTPCode(bool $force = false): static
    {
        if (! $this->two_factor_expires_at || now()->gt($this->two_factor_expires_at) || $force) {
            // Only generate new OTP if expired
            $this->two_factor_secret = encrypt(random_int(100000, 999999));
            $this->two_factor_expires_at = now()->addMinutes(10);
            $this->two_factor_confirmed_at = null;
            $this->save();

            // Automatically send new OTP
            $this->sendTwoFactorOTPCodeNotification();
        }

        return $this;
    }

    public function generateTwoFactorAuthenticatorAppOTPCode(): static
    {
        $this->two_factor_secret = (new Google2FA)->generateSecretKey();
        $this->two_factor_expires_at = null; // not needed for this type
        $this->two_factor_confirmed_at = null;
        $this->save();

        return $this;
    }

    public function sendTwoFactorOTPCodeNotification(): static
    {
        $otpNotification = config('filament-multi-2fa.otp_notification_class');

        Notification::route('mail', $this->email)->notify(new $otpNotification($this));

        return $this;
    }

    public function verifyOTP($code): bool
    {
        return decrypt($this->two_factor_secret) == $code && now()->lt($this->two_factor_expires_at);
    }

    public function addTrustedDevice()
    {
        $request = request();

        $signature = hash('sha256', $request->userAgent() . $request->ip() . str()->random(10));

        $this->trustedDevices()->create([
            'device_name' => $request->header('User-Agent'),
            'device_signature' => $signature,
            'expires_at' => now()->addDays(30),
        ]);

        cookie()->queue(config('filament-multi-2fa.trusted_device_cookie_name'), $signature, 60 * 24 * 30);
    }
}
