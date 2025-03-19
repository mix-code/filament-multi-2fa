<?php

namespace MixCode\FilamentMulti2fa;

use Filament\Contracts\Plugin;
use Filament\Panel;
use MixCode\FilamentMulti2fa\Middleware\CheckTrustedDevice;
use MixCode\FilamentMulti2fa\Pages\OTPVerify;
use MixCode\FilamentMulti2fa\Pages\TwoFactorySetup;

class FilamentMulti2faPlugin implements Plugin
{
    protected bool $isForcedToSetup = false;

    public function getId(): string
    {
        return 'filament-multi-2fa';
    }

    public function register(Panel $panel): void
    {
        $panel
            ->pages([
                OTPVerify::class,
                TwoFactorySetup::class,
            ])
            ->authMiddleware([
                CheckTrustedDevice::class,
            ]);
    }

    public function boot(Panel $panel): void
    {
        //
    }

    public function forceSetup2fa(bool $state = true): static
    {
        $this->isForcedToSetup = $state;

        return $this;
    }

    public function getForceSetup2fa(): bool
    {
        return $this->isForcedToSetup;
    }

    public function redirectAfterVerifyUrl(): string
    {
        return filament()->getCurrentPanel()->getUrl();
    }

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        return filament(static::make()->getId());
    }
}
