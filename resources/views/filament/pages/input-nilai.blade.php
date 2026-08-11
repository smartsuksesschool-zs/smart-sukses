{{-- NILAI-01 / API 4.8 — input nilai massal per class_subject. --}}
<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6 flex justify-end">
            <x-filament::button type="submit" wire:loading.attr="disabled">
                {{ __('Simpan Nilai') }}
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
