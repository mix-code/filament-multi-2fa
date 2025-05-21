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
use Livewire\Attributes\Locked;
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

    #[Locked]
    public $user;

    public ?array $data = [
        'two_factor_type' => TwoFactorAuthType::None,
        'trust_device' => true,
    ];

    public function mount()
    {
        $this->user = auth()->user();

        if ($this->user->two_factor_type->value == TwoFactorAuthType::Email->value) {
            $this->user->generateTwoFactorOTPCode();
        }

        if ($this->user->two_factor_type->value == TwoFactorAuthType::None->value) {
            redirect($this->getRedirectUrl());
        }
    }

    public function getTitle(): string | Htmlable
    {
        return trans('filament-multi-2fa::filament-multi-2fa.verify');
    }

    public function getSubheading(): string | Htmlable | null
    {
        if ($this->user->two_factor_type?->value === TwoFactorAuthType::Email->value) {
            return trans('filament-multi-2fa::filament-multi-2fa.otp_has_sent_to_your_email');
        }

        if ($this->user->two_factor_type?->value === TwoFactorAuthType::Totp->value) {
            return trans('filament-multi-2fa::filament-multi-2fa.get_otp_from_authenticator_app');
        }

        return null;
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('otp')
                    ->label(trans('filament-multi-2fa::filament-multi-2fa.otp'))
                    ->placeholder(trans('filament-multi-2fa::filament-multi-2fa.otp'))
                    ->required()
                    ->maxLength(191)
                    ->default(null)
                    ->rule(function (?string $state): Closure {
                        return function (string $attribute, mixed $value, Closure $fail) use ($state) {
                            if ($this->user->two_factor_type->value === TwoFactorAuthType::Totp->value) {
                                if (! (new Google2FA)->verifyKey($this->user->two_factor_secret, $state)) {
                                    $fail(trans('filament-multi-2fa::filament-multi-2fa.wrong_otp'));
                                }
                            }

                            if ($this->user->two_factor_type->value === TwoFactorAuthType::Email->value) {
                                if (! $this->user->verifyOTP($state)) {
                                    $fail(trans('filament-multi-2fa::filament-multi-2fa.wrong_otp'));
                                }
                            }
                        };
                    })
                    ->hint(function () {
                        if ((bool) $this->user->two_factor_sent_at) {
                            $sentAtDateTime = $this->user->two_factor_sent_at->addSeconds(config('filament-multi-2fa.otp_resend_allowed_after_in_seconds'));

                            if ($sentAtDateTime->greaterThan(now())) {
                                $remainsDiff = $sentAtDateTime->diff(now());
                                $remains = $remainsDiff->format(config('filament-multi-2fa.otp_resend_time_format'));

                                if ($remains < 1) {
                                    return trans('filament-multi-2fa::filament-multi-2fa.resend_available_in_seconds', [
                                        'seconds' => $remains,
                                    ]);
                                }

                                return trans('filament-multi-2fa::filament-multi-2fa.resend_available_in', [
                                    'minutes' => $remains,
                                ]);
                            }
                        }
                    })
                    ->hintAction(
                        Action::make('resendOtp')
                            ->label(fn () => trans('filament-multi-2fa::filament-multi-2fa.resend_otp'))
                            ->hidden($this->canResendOTP())
                            ->icon('heroicon-o-arrow-path')
                            ->disabled($this->canResendOTP())
                            ->action(fn () => $this->user->generateTwoFactorOTPCode())
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

            if ($this->user->two_factor_type->value === TwoFactorAuthType::Email->value) {
                if (! $this->user->verifyOTP($data['otp'])) {
                    Notification::make()
                        ->danger()
                        ->title(trans('filament-multi-2fa::filament-multi-2fa.wrong_otp'))
                        ->send();

                    $this->halt();
                }

                $this->user->two_factor_confirmed_at = now();
                $this->user->two_factor_secret = null; // Must not be deleted in Totp
            }

            if ($this->user->two_factor_type->value === TwoFactorAuthType::Totp->value) {
                if (! (new Google2FA)->verifyKey($this->user->two_factor_secret, $data['otp'])) {
                    Notification::make()
                        ->danger()
                        ->title(trans('filament-multi-2fa::filament-multi-2fa.wrong_otp'))
                        ->send();

                    $this->halt();
                }

                $this->user->two_factor_confirmed_at = now();
            }

            $this->user->two_factor_sent_at = null;
            $this->user->two_factor_expires_at = null;
            $this->user->save();

            session(['2fa_passed' => true]);

            if ($this->shouldTrustDevice()) {
                $this->user->addTrustedDevice();
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

    protected function shouldTrustDevice(): bool
    {
        return ($this->data['trust_device'] ?? false) && $this->user->two_factor_type->value !== TwoFactorAuthType::None->value;
    }

    protected function canResendOTP(): bool
    {
        return is_null($this->user->two_factor_sent_at) || $this->user->two_factor_sent_at
            ->addSeconds(config('filament-multi-2fa.otp_resend_allowed_after_in_seconds'))
            ->greaterThan(now());
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
