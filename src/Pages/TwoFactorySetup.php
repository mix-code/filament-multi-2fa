<?php

namespace MixCode\FilamentMulti2fa\Pages;

use Closure;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Filament\Support\Exceptions\Halt;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Locked;
use MixCode\FilamentMulti2fa\Enums\TwoFactorAuthType;
use MixCode\FilamentMulti2fa\FilamentMulti2faPlugin;
use PragmaRX\Google2FA\Google2FA;
use PragmaRX\Google2FAQRCode\Google2FA as Google2FAQRCode;
use PragmaRX\Google2FAQRCode\QRCode\Chillerlan;

class TwoFactorySetup extends Page implements HasForms
{
    use InteractsWithForms;

    #[Locked]
    public $user;

    protected static string $layout = 'filament-panels::components.layout.simple';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationIcon = 'heroicon-o-lock-closed';

    protected static string $view = 'filament-multi-2fa::pages.two-factory-setup';

    public ?array $data = ['trust_device' => true];

    public function mount()
    {
        $this->user = auth()->user();

        $this->data['two_factor_type'] = $this->user->two_factor_type?->value ?? TwoFactorAuthType::None->value;
    }

    public bool $showSetupForm = true;

    public bool $showVerifyOTPForm = false;

    public bool $showVerifyTOTPForm = false;

    public static function getModelLabel(): string
    {
        return trans('filament-multi-2fa::filament-multi-2fa.two_factor_setup_intro2');
    }

    public static function getNavigationLabel(): string
    {
        return trans('filament-multi-2fa::filament-multi-2fa.two_factor_setup_intro2');
    }

    public function getTitle(): string
    {
        return trans('filament-multi-2fa::filament-multi-2fa.2fa_setup');
    }

    public function getHeading(): string | Htmlable
    {
        return trans('filament-multi-2fa::filament-multi-2fa.two_factor_setup_intro');
    }

    public function getSubheading(): string | Htmlable | null
    {
        return trans('filament-multi-2fa::filament-multi-2fa.two_factor_setup_intro2');
    }

    protected function getForms(): array
    {
        return [
            'setupForm',
            'verifyOTPForm',
            'verifyTOTPForm',
        ];
    }

    public function setupForm(Form $form): Form
    {
        return $form
            ->schema([
                ToggleButtons::make('two_factor_type')
                    ->label(trans('filament-multi-2fa::filament-multi-2fa.two_factor_type'))
                    ->options(function () {
                        $forceSetup = FilamentMulti2faPlugin::get()->getForceSetup2fa();

                        return collect(TwoFactorAuthType::cases())
                            ->filter(function ($type) use ($forceSetup) {
                                if ($type === TwoFactorAuthType::None) {
                                    return ! $forceSetup; // Only show None when force setup is off
                                }

                                return true;
                            })
                            ->mapWithKeys(function ($type) {
                                return [$type->value => $type->getLabel()];
                            })
                            ->toArray();
                    })
                    ->colors(
                        fn () => collect(TwoFactorAuthType::cases())
                            ->mapWithKeys(fn ($type) => [$type->value => $type->getColor()])
                            ->toArray()
                    )
                    ->icons(
                        fn () => collect(TwoFactorAuthType::cases())
                            ->mapWithKeys(fn ($type) => [$type->value => $type->getIcon()])
                            ->toArray()
                    )
                    ->disableOptionWhen(function (string $value) {
                        $forceSetup = FilamentMulti2faPlugin::get()->getForceSetup2fa();
                        $currentValue = $this->user->two_factor_type?->value;

                        // Disable 'None' if 2FA is forced
                        if ($value === TwoFactorAuthType::None->value) {
                            return $forceSetup;
                        }

                        // Disable already active 2FA type
                        return $value === $currentValue;
                    })
                    ->rule(function (?string $state) {
                        $forceSetup = FilamentMulti2faPlugin::get()->getForceSetup2fa();
                        $currentValue = $this->user->two_factor_type?->value;

                        return function (string $attribute, mixed $value, Closure $fail) use ($state, $forceSetup, $currentValue) {
                            if ($state === TwoFactorAuthType::None->value && $forceSetup) {
                                $fail(trans('filament-multi-2fa::filament-multi-2fa.must_setup_2fa'));
                            }

                            if ($state !== TwoFactorAuthType::None->value && $state === $currentValue) {
                                $fail(trans('filament-multi-2fa::filament-multi-2fa.verified_before'));
                            }
                        };
                    })
                    ->inline()
                    ->required(),

            ])
            ->statePath('data');
    }

    public function verifyOTPForm(Form $form): Form
    {
        return $form
            ->schema([
                Placeholder::make('otp_sent')
                    ->hiddenLabel()
                    ->content(trans('filament-multi-2fa::filament-multi-2fa.otp_has_sent_to_your_email')),

                TextInput::make('otp')
                    ->label(trans('filament-multi-2fa::filament-multi-2fa.otp'))
                    ->placeholder(trans('filament-multi-2fa::filament-multi-2fa.otp'))
                    ->required()
                    ->maxLength(191)
                    ->default(null)
                    ->rule(function (?string $state, Get $get): Closure {
                        return function (string $attribute, mixed $value, Closure $fail) use ($state) {
                            if (! $this->user->verifyOTP($state)) {
                                $fail(trans('filament-multi-2fa::filament-multi-2fa.wrong_otp'));
                            }
                        };
                    })
                    ->hint(function () {
                        if ($this->user->two_factor_expires_at && now()->lt($this->user->two_factor_expires_at)) {
                            $remainsDiff = now()->diff($this->user->two_factor_expires_at);
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
                            ->hidden($this->canResendOTP())
                            ->icon('heroicon-o-arrow-path')
                            ->disabled($this->canResendOTP())
                            ->action(fn () => $this->user->generateTwoFactorOTPCode(force: true))
                    ),

                Radio::make('trust_device')
                    ->label(trans('filament-multi-2fa::filament-multi-2fa.trust_device'))
                    ->boolean()
                    ->required(),
            ])
            ->statePath('data');
    }

    public function verifyTOTPForm(Form $form): Form
    {
        return $form
            ->schema([
                Placeholder::make('qr_code')
                    ->hiddenLabel()
                    ->content(function (): ?Htmlable {
                        if ($this->data['two_factor_type'] === TwoFactorAuthType::Totp->value) {
                            $qrCodeBackendService = config('filament-multi-2fa.qr_code_backend_service');

                            $qrCodeService = new $qrCodeBackendService;

                            $QRImage = (new Google2FAQRCode)
                                ->setQrCodeService($qrCodeService)
                                ->getQRCodeInline(
                                    config('app.name'),
                                    $this->user->email,
                                    $this->user->two_factor_secret,
                                );

                            if ($qrCodeService instanceof Chillerlan) {
                                $QRImage = '<img src="' . $QRImage . '" />';
                            }

                            return new HtmlString('<div class="flex justify-center">' . $QRImage . '</div>');
                        }

                        return null;
                    }),

                TextInput::make('otp')
                    ->label(trans('filament-multi-2fa::filament-multi-2fa.otp'))
                    ->placeholder(trans('filament-multi-2fa::filament-multi-2fa.otp'))
                    ->required()
                    ->maxLength(191)
                    ->default(null)
                    ->rule(function (?string $state, Get $get): Closure {
                        return function (string $attribute, mixed $value, Closure $fail) use ($state) {
                            if (! (new Google2FA)->verifyKey($this->user->two_factor_secret, $state)) {
                                $fail(trans('filament-multi-2fa::filament-multi-2fa.wrong_otp'));
                            }
                        };
                    }),

                Radio::make('trust_device')
                    ->label(trans('filament-multi-2fa::filament-multi-2fa.trust_device'))
                    ->boolean()
                    ->required(),
            ])
            ->statePath('data');
    }

    protected function getSetupFormActions(): array
    {
        return [
            Action::make('setup')
                ->label(trans('filament-multi-2fa::filament-multi-2fa.setup'))
                ->submit('setup'),

            Action::make('cancel')
                ->label(trans('filament-multi-2fa::filament-multi-2fa.cancel'))
                ->color('danger')
                ->action('goAwaySetup')
                ->hidden(function () {
                    return FilamentMulti2faPlugin::get()->getForceSetup2fa() && $this->user->two_factor_type?->value === TwoFactorAuthType::None->value;
                }),
        ];
    }

    protected function getVerifyOTPFormActions(): array
    {
        return [
            Action::make('verify')
                ->label(trans('filament-multi-2fa::filament-multi-2fa.verify'))
                ->submit('verifyOTP'),

            Action::make('cancel')
                ->label(trans('filament-multi-2fa::filament-multi-2fa.cancel'))
                ->color('danger')
                ->action('cancel'),
        ];
    }

    protected function getVerifyTOTPFormActions(): array
    {
        return [
            Action::make('verify')
                ->label(trans('filament-multi-2fa::filament-multi-2fa.verify'))
                ->submit('verifyTOTP'),

            Action::make('cancel')
                ->label(trans('filament-multi-2fa::filament-multi-2fa.cancel'))
                ->color('danger')
                ->action('cancel'),
        ];
    }

    public function setup(): void
    {
        $this->setupForm->validate();

        if ($this->data['two_factor_type'] !== TwoFactorAuthType::None->value && $this->data['two_factor_type'] === $this->user->two_factor_type?->value) {
            $this->notifyError(trans('filament-multi-2fa::filament-multi-2fa.verified_before'));

            return;
        }

        if ($this->data['two_factor_type'] === TwoFactorAuthType::None->value && FilamentMulti2faPlugin::get()->getForceSetup2fa()) {
            $this->notifyError(trans('filament-multi-2fa::filament-multi-2fa.must_setup_2fa'));

            return;
        }

        if ($this->data['two_factor_type'] == TwoFactorAuthType::Email->value) {
            $this->user->generateTwoFactorOTPCode();
            $this->showSetupForm = false;
            $this->showVerifyOTPForm = true;
            $this->showVerifyTOTPForm = false;
        } elseif ($this->data['two_factor_type'] == TwoFactorAuthType::Totp->value) {
            $this->user->generateTwoFactorAuthenticatorAppOTPCode();
            $this->showSetupForm = false;
            $this->showVerifyOTPForm = false;
            $this->showVerifyTOTPForm = true;
        } else {
            $this->cancel();

            $this->user->two_factor_type = TwoFactorAuthType::None->value;
            $this->user->two_factor_secret = null;
            $this->user->two_factor_confirmed_at = null;
            $this->user->two_factor_expires_at = null;
            $this->user->save();

            $this->user->trustedDevices()->delete();

            session()->forget('trusted_device_validated');

            redirect($this->getRedirectUrl());
        }
    }

    public function verifyOTP(): void
    {
        try {
            $this->verifyOTPForm->validate();

            if (! $this->user->verifyOTP($this->data['otp'])) {
                $this->notifyError(trans('filament-multi-2fa::filament-multi-2fa.wrong_otp'));

                return;
            }

            DB::transaction(function () {
                $this->user->two_factor_secret = null;
                $this->user->two_factor_type = TwoFactorAuthType::Email->value;
                $this->user->two_factor_expires_at = null;
                $this->user->two_factor_confirmed_at = now();
                $this->user->save();

                if ($this->shouldTrustDevice()) {
                    $this->user->addTrustedDevice();
                }
            });
        } catch (Halt $exception) {
            return;
        }

        $this->notifySuccess(trans('filament-panels::resources/pages/edit-record.notifications.saved.title'));

        redirect($this->getRedirectUrl());
    }

    public function verifyTOTP(): void
    {
        try {
            $this->verifyTOTPForm->validate();
            if (! (new Google2FA)->verifyKey($this->user->two_factor_secret, $this->data['otp'])) {
                $this->notifyError(trans('filament-multi-2fa::filament-multi-2fa.wrong_otp'));

                return;
            }

            DB::transaction(function () {
                // Never reset $this->user->two_factor_secret

                $this->user->two_factor_type = TwoFactorAuthType::Totp->value;
                $this->user->two_factor_expires_at = null;
                $this->user->two_factor_confirmed_at = now();
                $this->user->save();

                if ($this->shouldTrustDevice()) {
                    $this->user->addTrustedDevice();
                }
            });
        } catch (Halt $exception) {
            return;
        }

        $this->notifySuccess(trans('filament-panels::resources/pages/edit-record.notifications.saved.title'));

        redirect($this->getRedirectUrl());
    }

    public function goAwaySetup()
    {
        redirect($this->getRedirectUrl());
    }

    public function cancel()
    {
        $this->showSetupForm = true;
        $this->showVerifyOTPForm = false;
        $this->showVerifyTOTPForm = false;

        // Optionally clear data if needed
        $this->data['otp'] = null;
        $this->data['trust_device'] = true;
    }

    protected function getRedirectUrl(): string
    {

        return $this->user->redirectAfterVerifyUrl() ?? FilamentMulti2faPlugin::get()->redirectAfterVerifyUrl();
    }

    protected function shouldTrustDevice(): bool
    {
        return ($this->data['trust_device'] ?? false) && $this->data['two_factor_type'] !== TwoFactorAuthType::None->value;
    }

    protected function notifyError(string $message): void
    {
        Notification::make()
            ->danger()
            ->title($message)
            ->send();
    }

    protected function notifySuccess(string $message): void
    {
        Notification::make()
            ->success()
            ->title($message)
            ->send();
    }

    protected function canResendOTP(): bool
    {
        return is_null($this->user->two_factor_expires_at) || now()->lt($this->user->two_factor_expires_at);
    }

    protected function getLayoutData(): array
    {
        return [
            'hasTopbar' => $this->hasTopbar(),
            'maxWidth' => $this->getMaxWidth(),
        ];
    }

    protected function hasFullWidthSetupFormActions(): bool
    {
        return true;
    }

    protected function hasFullWidthVerifyOTPFormActions(): bool
    {
        return true;
    }

    protected function hasFullWidthVerifyTOTPFormActions(): bool
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
