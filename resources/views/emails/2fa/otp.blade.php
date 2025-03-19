<x-mail::message>

# @lang('filament-multi-2fa::filament-multi-2fa.your_verification_code')

@lang('filament-multi-2fa::filament-multi-2fa.your_otp', ['code' => decrypt($user->two_factor_secret)])
    
@lang('filament-multi-2fa::filament-multi-2fa.expires_in', ['minutes' => 10])
</x-mail::message>