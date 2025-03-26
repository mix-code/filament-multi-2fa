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

    public function generateTwoFactorOTPCode(): static
    {
        // If an OTP exists and is still valid, return the same instance without regenerating
        if ($this->two_factor_sent_at && now()->lt($this->two_factor_expires_at)) {

            $this->two_factor_sent_at = now();
            $this->save();

            $this->sendTwoFactorOTPCodeNotification();

            return $this;
        }

        // Generate a new OTP code if expired, not sent before, or forced
        $this->two_factor_secret = encrypt(random_int(100000, 999999));
        $this->two_factor_sent_at = now();
        $this->two_factor_expires_at = now()->addSeconds(config('filament-multi-2fa.otp_expiration_in_seconds'));
        $this->two_factor_confirmed_at = null;
        $this->save();

        // Automatically send the new OTP
        $this->sendTwoFactorOTPCodeNotification();

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
            'expires_at' => now()->addMinutes(config('filament-multi-2fa.trusted_device_db_expiration') ?? 60 * 24 * 30),
        ]);

        cookie()->queue(config('filament-multi-2fa.trusted_device_cookie_name'), $signature, config('filament-multi-2fa.cookie_lifespan') ?? 60 * 24 * 30);
    }
}
