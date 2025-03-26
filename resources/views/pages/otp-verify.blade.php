<div wire:poll.1s>
    <x-filament-panels::page.simple>

        <x-filament-panels::form id="form" wire:submit="save">
            {{ $this->form }}

            <x-filament-panels::form.actions :actions="$this->getFormActions()" :full-width="$this->hasFullWidthFormActions()" />
        </x-filament-panels::form>

    </x-filament-panels::page.simple>
</div>
