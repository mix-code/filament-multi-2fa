<?php

namespace MixCode\FilamentMulti2fa\Middleware;

use Closure;
use Illuminate\Http\Request;
use MixCode\FilamentMulti2fa\Enums\TwoFactorAuthType;
use MixCode\FilamentMulti2fa\Pages\OTPVerify;
use MixCode\FilamentMulti2fa\Pages\TwoFactorySetup;
use Symfony\Component\HttpFoundation\Response;

class CheckTrustedDevice
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $trustDeviceModel = config('filament-multi-2fa.trust_device_model');

        $user = auth()->user();

        if ((bool) $user) {
            $requires2FA = $user->two_factor_type?->value !== TwoFactorAuthType::None->value;

            if ($requires2FA && ! $user->two_factor_confirmed_at) {
                $signature = $request->cookie('trusted_device');
                $trusted = $signature
                    ? $trustDeviceModel::where('device_signature', $signature)
                        ->where('user_id', $user->id)
                        ->where('expires_at', '>', now())
                        ->first()
                    : null;

                if (! $trusted && ! $request->routeIs(OTPVerify::getRouteName())) {
                    return redirect()->route(OTPVerify::getRouteName());
                }

                // allow OTPVerify page if already redirected
                if (! $trusted && $request->routeIs(OTPVerify::getRouteName())) {
                    return $next($request);
                }
            }

            // Force setup if no 2FA type at all
            if (! $requires2FA && ! $request->routeIs(TwoFactorySetup::getRouteName())) {
                return redirect()->route(TwoFactorySetup::getRouteName());
            }
        }

        return $next($request);
    }
}
