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
use Filament\Pages\Concerns\HasMaxWidth;
use Filament\Pages\Concerns\HasTopbar;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Filament\Support\Exceptions\Halt;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use MixCode\FilamentMulti2fa\Enums\TwoFactorAuthType;
use MixCode\FilamentMulti2fa\FilamentMulti2faPlugin;
use PragmaRX\Google2FA\Google2FA;
use PragmaRX\Google2FAQRCode\Google2FA as Google2FAQRCode;

class TwoFactorySetup extends Page implements HasForms
{
    use HasMaxWidth;
    use HasTopbar;
    use InteractsWithForms;

    protected static string $layout = 'filament-panels::components.layout.simple';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationIcon = 'heroicon-o-lock-closed';

    protected static string $view = 'filament-multi-2fa::pages.two-factory-setup';

    public ?array $data = [
        'two_factor_type' => TwoFactorAuthType::None->value,
        'trust_device' => true,
    ];

    public bool $showSetupForm = true;

    public bool $showVerifyOTPForm = false;

    public bool $showVerifyTOTPForm = false;

    public static function getModelLabel(): string
    {
        return trans('filament-multi-2fa::filament-multi-2fa.setup');
    }

    public static function getNavigationLabel(): string
    {
        return trans('filament-multi-2fa::filament-multi-2fa.setup');
    }

    public function getTitle(): string
    {
        return trans('filament-multi-2fa::filament-multi-2fa.setup');
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
                    ->options(TwoFactorAuthType::class)
                    ->inline()
                    ->required(),
            ])
            ->statePath('data');
    }

    public function verifyOTPForm(Form $form): Form
    {
        $user = auth()->user();

        return $form
            ->schema([
                Placeholder::make('otp_sent')
                    ->hiddenLabel()
                    ->content(trans('filament-multi-2fa::filament-multi-2fa.otp_has_sent_to_your_email')),

                TextInput::make('otp')
                    ->label(trans('filament-multi-2fa::filament-multi-2fa.otp'))
                    ->placeholder(trans('filament-multi-2fa::filament-multi-2fa.otp'))
                    ->required()
                    ->live()
                    ->maxLength(191)
                    ->default(null)
                    ->rule(static function (?string $state, Get $get): Closure {
                        return static function (string $attribute, mixed $value, Closure $fail) use ($state) {
                            if (! auth()->user()->verifyOTP($state)) {
                                $fail(trans('filament-multi-2fa::filament-multi-2fa.wrong_otp'));
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

    public function verifyTOTPForm(Form $form): Form
    {
        $user = auth()->user();

        return $form
            ->schema([
                Placeholder::make('qr_code')
                    ->hiddenLabel()
                    ->content(function () use ($user): ?Htmlable {
                        if ($this->data['two_factor_type'] === TwoFactorAuthType::Totp->value) {
                            $QRImage = (new Google2FAQRCode)->getQRCodeInline(
                                config('app.name'),
                                $user->email,
                                $user->two_factor_secret,
                            );

                            return new HtmlString('<div class="flex justify-center">' . $QRImage . '</div>');
                        }

                        return null;
                    }),

                TextInput::make('otp')
                    ->label(trans('filament-multi-2fa::filament-multi-2fa.otp'))
                    ->placeholder(trans('filament-multi-2fa::filament-multi-2fa.otp'))
                    ->required()
                    ->live()
                    ->maxLength(191)
                    ->default(null)
                    ->rule(static function (?string $state, Get $get): Closure {
                        return static function (string $attribute, mixed $value, Closure $fail) use ($state) {
                            if (! (new Google2FA)->verifyKey(auth()->user()->two_factor_secret, $state)) {
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

        $user = auth()->user();

        if ($this->data['two_factor_type'] == TwoFactorAuthType::Email->value) {
            $user->generateTwoFactorOTPCode();
            $this->showSetupForm = false;
            $this->showVerifyOTPForm = true;
            $this->showVerifyTOTPForm = false;
        } elseif ($this->data['two_factor_type'] == TwoFactorAuthType::Totp->value) {
            $user->generateTwoFactorAuthenticatorAppOTPCode();
            $this->showSetupForm = false;
            $this->showVerifyOTPForm = false;
            $this->showVerifyTOTPForm = true;
        } else {
            $this->cancel();

            $user->two_factor_type = TwoFactorAuthType::None->value;
            $user->two_factor_secret = null;
            $user->two_factor_confirmed_at = null;
            $user->two_factor_expires_at = null;
            $user->save();

            $user->trustedDevices()->delete();
        }
    }

    public function verifyOTP(): void
    {
        try {
            $this->verifyOTPForm->validate();

            $user = auth()->user();

            if (! $user->verifyOTP($this->data['otp'])) {
                Notification::make()
                    ->danger()
                    ->title(trans('filament-multi-2fa::filament-multi-2fa.wrong_otp'))
                    ->send();

                $this->halt();
            }

            DB::transaction(function () use ($user) {
                $user->two_factor_secret = null;
                $user->two_factor_type = TwoFactorAuthType::Email->value;
                $user->two_factor_expires_at = null;
                $user->two_factor_confirmed_at = now();
                $user->save();

                if (isset($this->data['trust_device']) && $this->data['trust_device'] && $this->data['two_factor_type'] !== TwoFactorAuthType::None->value) {
                    $user->addTrustedDevice();
                }
            });
        } catch (Halt $exception) {
            return;
        }

        Notification::make()
            ->success()
            ->title(__('filament-panels::resources/pages/edit-record.notifications.saved.title'))
            ->send();

        redirect($this->getRedirectUrl());
    }

    public function verifyTOTP(): void
    {
        try {
            $this->verifyTOTPForm->validate();

            $user = auth()->user();

            if (! (new Google2FA)->verifyKey($user->two_factor_secret, $this->data['otp'])) {
                Notification::make()
                    ->danger()
                    ->title(trans('filament-multi-2fa::filament-multi-2fa.wrong_otp'))
                    ->send();

                $this->halt();
            }

            DB::transaction(function () use ($user) {
                $user->two_factor_secret = null;
                $user->two_factor_type = TwoFactorAuthType::Totp->value;
                $user->two_factor_expires_at = null;
                $user->two_factor_confirmed_at = now();
                $user->save();

                if (isset($this->data['trust_device']) && $this->data['trust_device'] && $this->data['two_factor_type'] !== TwoFactorAuthType::None->value) {
                    $user->addTrustedDevice();
                }
            });
        } catch (Halt $exception) {
            return;
        }

        Notification::make()
            ->success()
            ->title(__('filament-panels::resources/pages/edit-record.notifications.saved.title'))
            ->send();

        redirect($this->getRedirectUrl());
    }

    public function cancel()
    {
        $this->showSetupForm = true;
        $this->showVerifyOTPForm = false;
        $this->showVerifyTOTPForm = false;
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

    protected function hasFullWidthSetupFormActions(): bool
    {
        return true;
    }

    protected function hasFullWidthVerifyOTPFormActions(): bool
    {
        return false;
    }

    protected function hasFullWidthVerifyTOTPFormActions(): bool
    {
        return false;
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
