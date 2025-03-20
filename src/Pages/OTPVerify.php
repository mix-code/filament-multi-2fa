<?php

namespace MixCode\FilamentMulti2fa\Pages;

use Closure;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Filament\Support\Exceptions\Halt;
use Illuminate\Contracts\Support\Htmlable;
use MixCode\FilamentMulti2fa\Enums\TwoFactorAuthType;
use MixCode\FilamentMulti2fa\FilamentMulti2faPlugin;
use PragmaRX\Google2FA\Google2FA;

class OTPVerify extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $layout = 'filament-panels::components.layout.simple';

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament-multi-2fa::pages.otp-verify';

    public ?array $data = [
        'two_factor_type' => TwoFactorAuthType::None,
        'trust_device' => true,
    ];

    public function getTitle(): string | Htmlable
    {
        return trans('filament-multi-2fa::filament-multi-2fa.verify');
    }

    public function getSubheading(): string | Htmlable | null
    {
        $user = auth()->user();

        if ($user->two_factor_type?->value === TwoFactorAuthType::Email->value) {
            return trans('filament-multi-2fa::filament-multi-2fa.otp_has_sent_to_your_email');
        }

        if ($user->two_factor_type?->value === TwoFactorAuthType::Totp->value) {
            return trans('filament-multi-2fa::filament-multi-2fa.get_otp_from_authenticator_app');
        }

        return null;
    }

    public function mount()
    {
        $user = auth()->user();

        if ($user->two_factor_type->value == TwoFactorAuthType::Email->value) {
            $user->generateTwoFactorOTPCode();
        }

        if ($user->two_factor_type->value == TwoFactorAuthType::None->value) {
            redirect($this->getRedirectUrl());
        }
    }

    public function form(Form $form): Form
    {

        $user = auth()->user();

        return $form
            ->schema([
                TextInput::make('otp')
                    ->label(trans('filament-multi-2fa::filament-multi-2fa.otp'))
                    ->placeholder(trans('filament-multi-2fa::filament-multi-2fa.otp'))
                    ->required()
                    ->maxLength(191)
                    ->default(null)
                    ->rule(static function (?string $state) use ($user): Closure {
                        return static function (string $attribute, mixed $value, Closure $fail) use ($state, $user) {
                            if ($user->two_factor_type->value === TwoFactorAuthType::Totp->value) {
                                if (! (new Google2FA)->verifyKey($user->two_factor_secret, $state)) {
                                    $fail(trans('filament-multi-2fa::filament-multi-2fa.wrong_otp'));
                                }
                            }

                            if ($user->two_factor_type->value === TwoFactorAuthType::Email->value) {
                                if (! $user->verifyOTP($state)) {
                                    $fail(trans('filament-multi-2fa::filament-multi-2fa.wrong_otp'));
                                }
                            }
                        };
                    })
                    ->hint(static function () use ($user) {
                        if ($user->two_factor_expires_at && now()->lt($user->two_factor_expires_at)) {
                            $remainsDiff = now()->diff($user->two_factor_expires_at);
                            $remains = $remainsDiff->format('%i');

                            if ($remains < 1) {
                                return trans('filament-multi-2fa::filament-multi-2fa.resend_available_in_less_than_minute');
                            }

                            return trans('filament-multi-2fa::filament-multi-2fa.resend_available_in', [
                                'minutes' => $remains,
                            ]);
                        }
                    })
                    ->hintAction(
                        Action::make('resendOtp')
                            ->label(fn () => trans('filament-multi-2fa::filament-multi-2fa.resend_otp'))
                            ->hidden(
                                fn () => is_null($user->two_factor_expires_at) || now()->lt($user->two_factor_expires_at)
                            )
                            ->icon('heroicon-o-arrow-path')
                            ->disabled(
                                fn () => is_null($user->two_factor_expires_at) || now()->lt($user->two_factor_expires_at)
                            )
                            ->action(fn () => $user->generateTwoFactorOTPCode(force: true))
                    ),

                Radio::make('trust_device')
                    ->label(trans('filament-multi-2fa::filament-multi-2fa.trust_device'))
                    ->boolean()
                    ->required(),
            ])
            ->statePath('data');
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label(trans('filament-multi-2fa::filament-multi-2fa.verify'))
                ->submit('save')
                ->keyBindings(['mod+s']),
        ];
    }

    public function save(): void
    {
        try {
            $data = $this->form->getState();

            $user = auth()->user();

            if ($user->two_factor_type->value === TwoFactorAuthType::Email->value) {
                if (! $user->verifyOTP($data['otp'])) {
                    Notification::make()
                        ->danger()
                        ->title(trans('filament-multi-2fa::filament-multi-2fa.wrong_otp'))
                        ->send();

                    $this->halt();
                }

                $user->two_factor_confirmed_at = now();
                $user->two_factor_secret = null; // Must not be deleted in Totp
            }

            if ($user->two_factor_type->value === TwoFactorAuthType::Totp->value) {
                if (! (new Google2FA)->verifyKey($user->two_factor_secret, $data['otp'])) {
                    Notification::make()
                        ->danger()
                        ->title(trans('filament-multi-2fa::filament-multi-2fa.wrong_otp'))
                        ->send();

                    $this->halt();
                }

                $user->two_factor_confirmed_at = now();
            }

            $user->two_factor_expires_at = null;
            $user->save();

            if (isset($data['trust_device']) && $data['trust_device'] && $user->two_factor_type->value !== TwoFactorAuthType::None->value) {
                $user->addTrustedDevice();
            }
        } catch (Halt $exception) {
            return;
        }

        Notification::make()
            ->success()
            ->title(__('filament-panels::resources/pages/edit-record.notifications.saved.title'))
            ->send();

        redirect($this->getRedirectUrl());
    }

    protected function getRedirectUrl(): string
    {

        return auth()->user()->redirectAfterVerifyUrl() ?? FilamentMulti2faPlugin::get()->redirectAfterVerifyUrl();
    }

    protected function getLayoutData(): array
    {
        return [
            'hasTopbar' => $this->hasTopbar(),
            'maxWidth' => $this->getMaxWidth(),
        ];
    }

    protected function hasFullWidthFormActions(): bool
    {
        return true;
    }

    public function hasLogo(): bool
    {
        return false;
    }

    public function hasTopbar()
    {
        return false;
    }

    public function getMaxWidth(): MaxWidth
    {
        return MaxWidth::Large;
    }
}
