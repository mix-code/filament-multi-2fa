<x-mail::message>

# @lang('filament-multi-2fa::filament-multi-2fa.your_verification_code')

@lang('filament-multi-2fa::filament-multi-2fa.your_otp', ['code' => decrypt($user->two_factor_secret)])
    
@lang('filament-multi-2fa::filament-multi-2fa.expires_in', ['minutes' => config('filament-multi-2fa.otp_expire_and_resend_allowed_after_in_minutes')])
</x-mail::message>