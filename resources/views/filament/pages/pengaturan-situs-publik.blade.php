{{-- Isi halaman muka publik; berlaku tanpa deployment ulang. --}}
<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6 flex flex-wrap items-center justify-between gap-4">
            <a
                href="{{ route('landing') }}"
                target="_blank"
                rel="noopener"
                class="text-sm text-primary-600 underline dark:text-primary-400"
            >
                {{ __('Lihat halaman muka') }}
            </a>

            <x-filament::button type="submit" wire:loading.attr="disabled">
                {{ __('Simpan Perubahan') }}
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
