<?php

namespace MixCode\FilamentMulti2fa\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use MixCode\FilamentMulti2fa\Enums\TwoFactorAuthType;
use MixCode\FilamentMulti2fa\FilamentMulti2faPlugin;
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
        $is2FAForced = FilamentMulti2faPlugin::get()->getForceSetup2fa();

        $user = auth()->user();

        if (! $user) {
            return $next($request);
        }

        $requires2FA = $user->two_factor_type?->value !== TwoFactorAuthType::None->value;

        // If 2FA is enforced globally (e.g., admin setting)
        if ($is2FAForced) {
            if (! $requires2FA && ! $request->routeIs(TwoFactorySetup::getRouteName())) {
                return redirect()->route(TwoFactorySetup::getRouteName());
            }

            if ($requires2FA && ! $user->two_factor_confirmed_at) {
                if (! $this->checkTrusted($request, $trustDeviceModel, $user)) {
                    return $this->handleOtpRedirect($request, $next);
                }
            }
        }

        // Optional mode: if user enabled 2FA on their own
        if (! $is2FAForced && $requires2FA && ! $user->two_factor_confirmed_at) {
            if (! $this->checkTrusted($request, $trustDeviceModel, $user)) {
                return $this->handleOtpRedirect($request, $next);
            }
        }

        return $next($request);
    }

    protected function checkTrusted(Request $request, $trustDeviceModel, $user): bool
    {
        // First check session to avoid repeated checks and when user logout session is deleted by Laravel
        if (session('trusted_device_validated') === true) {
            return true;
        }

        $signature = $request->cookie('trusted_device');

        if (! $signature) {
            return false;
        }

        $cacheKey = "trusted_device:{$user->id}:{$signature}";

        $trusted = Cache::remember($cacheKey, now()->addMinutes(config('filament-multi-2fa.trust_device_check_cache_lifespan') ?? 60 * 24 * 30), function () use ($trustDeviceModel, $user, $signature) {
            return $trustDeviceModel::where('device_signature', $signature)
                ->where('user_id', $user->id)
                ->where('expires_at', '>', now())
                ->exists();
        });

        if ($trusted) {
            session(['trusted_device_validated' => true]);
        }

        return $trusted;
    }

    protected function handleOtpRedirect(Request $request, $next)
    {
        if (! $request->routeIs(OTPVerify::getRouteName())) {
            return redirect()->route(OTPVerify::getRouteName());
        }

        // Already on OTPVerify route, allow request to proceed
        return $next($request);
    }
}
