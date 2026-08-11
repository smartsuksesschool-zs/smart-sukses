{{-- Keputusan Sprint 4 butir 3 — skala predikat sikap yang dapat diubah Admin. --}}
<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6 flex justify-end">
            <x-filament::button type="submit" wire:loading.attr="disabled">
                {{ __('Simpan Pengaturan') }}
            </x-filament::button>
        </div>
    </form>

    <x-filament::section class="mt-6">
        <x-slot name="heading">{{ __('Rentang yang Berlaku Sekarang') }}</x-slot>

        <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach ($this->describedScale() as $predicate => $range)
                <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <dt class="text-lg font-semibold">{{ $predicate }}</dt>
                    <dd class="text-sm text-gray-500 dark:text-gray-400">{{ $range }}</dd>
                </div>
            @endforeach
        </dl>
    </x-filament::section>
</x-filament-panels::page>
