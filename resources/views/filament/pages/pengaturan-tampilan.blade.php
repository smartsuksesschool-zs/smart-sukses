{{-- AUTH-03 — logo & warna cabang, berlaku tanpa deployment ulang. --}}
<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6 flex justify-end">
            <x-filament::button type="submit" wire:loading.attr="disabled">
                {{ __('Simpan Tampilan') }}
            </x-filament::button>
        </div>
    </form>

    @php($previewedSchool = $this->previewedSchool())

    @if ($previewedSchool)
        <x-filament::section class="mt-6">
            <x-slot name="heading">{{ __('Pratinjau') }}</x-slot>

            <div class="flex flex-wrap items-center gap-4">
                <span
                    class="inline-block h-10 w-10 rounded-lg border border-gray-200 dark:border-gray-700"
                    style="background-color: {{ $previewedSchool->primary_color }}"
                    title="{{ __('Warna Utama') }} {{ $previewedSchool->primary_color }}"
                ></span>

                <span
                    class="inline-block h-10 w-10 rounded-lg border border-gray-200 dark:border-gray-700"
                    style="background-color: {{ $previewedSchool->secondary_color }}"
                    title="{{ __('Warna Aksen') }} {{ $previewedSchool->secondary_color }}"
                ></span>

                <div class="text-sm text-gray-500 dark:text-gray-400">
                    {{ $previewedSchool->name }} — {{ $previewedSchool->primary_color }} /
                    {{ $previewedSchool->secondary_color }}
                </div>
            </div>

            <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                {{ __('Warna tersimpan berlaku bagi seluruh pengguna cabang ini pada muat ulang halaman berikutnya. Tidak ada deployment ulang yang diperlukan.') }}
            </p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
