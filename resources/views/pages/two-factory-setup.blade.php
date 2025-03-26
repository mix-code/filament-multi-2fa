<x-filament-panels::page.simple>

    @if ($showSetupForm)
        <x-filament-panels::form id="setup-form" wire:submit="setup">
            {{ $this->setupForm }}

            <x-filament-panels::form.actions :actions="$this->getSetupFormActions()" :full-width="$this->hasFullWidthSetupFormActions()" />
        </x-filament-panels::form>
    @endif

    @if ($showVerifyOTPForm)
        <div wire:poll.1s>
            <x-filament-panels::form id="verify-otp-form" wire:submit="verifyOTP">
                {{ $this->verifyOTPForm }}

                <x-filament-panels::form.actions :actions="$this->getVerifyOTPFormActions()" :full-width="$this->hasFullWidthVerifyOTPFormActions()" />
            </x-filament-panels::form>
        </div>
    @endif

    @if ($showVerifyTOTPForm)
        <x-filament-panels::form id="verify-totp-form" wire:submit="verifyTOTP">
            {{ $this->verifyTOTPForm }}

            <x-filament-panels::form.actions :actions="$this->getVerifyTOTPFormActions()" :full-width="$this->hasFullWidthVerifyTOTPFormActions()" />
        </x-filament-panels::form>
    @endif

</x-filament-panels::page.simple>
