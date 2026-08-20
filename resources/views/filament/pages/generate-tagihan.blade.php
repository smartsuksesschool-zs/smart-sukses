{{-- SPP-02 — generate tagihan massal dengan pratinjau wajib sebelum konfirmasi. --}}
<x-filament-panels::page>
    <form wire:submit="preview">
        {{ $this->form }}

        <div class="mt-6 flex justify-end gap-3">
            <x-filament::button type="submit" color="gray" icon="heroicon-o-eye" wire:loading.attr="disabled">
                {{ __('Pratinjau') }}
            </x-filament::button>

            <x-filament::button
                type="button"
                icon="heroicon-o-paper-airplane"
                wire:click="generate"
                wire:loading.attr="disabled"
                :disabled="! $this->hasFreshPreview()"
            >
                {{ __('Terbitkan Tagihan') }}
            </x-filament::button>
        </div>
    </form>

    @if ($this->hasFreshPreview())
        <x-filament::section class="mt-6">
            <x-slot name="heading">{{ __('Pratinjau Penerbitan') }}</x-slot>
            <x-slot name="description">
                {{ __('Belum ada tagihan yang dibuat. Periksa daftar di bawah, lalu tekan Terbitkan Tagihan.') }}
            </x-slot>

            <dl class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                @if ($preview['school'])
                    <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                        <dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('Cabang') }}</dt>
                        <dd class="font-semibold">{{ $preview['school'] }}</dd>
                    </div>
                @endif

                <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('Jenis Tagihan') }}</dt>
                    <dd class="font-semibold">{{ $preview['fee_type'] }} ({{ $preview['frequency'] }})</dd>
                </div>

                <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('Nominal per Siswa') }}</dt>
                    <dd class="font-semibold">{{ \Illuminate\Support\Number::currency((float) $preview['amount'], 'IDR') }}</dd>
                </div>

                <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('Periode') }}</dt>
                    <dd class="font-semibold">{{ $preview['period'] }}</dd>
                </div>

                <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('Jatuh Tempo') }}</dt>
                    <dd class="font-semibold">{{ $preview['due_date'] }}</dd>
                </div>

                <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('Siswa Aktif') }}</dt>
                    <dd class="font-semibold">{{ $preview['active_count'] }}</dd>
                </div>

                <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('Akan Dibuatkan Tagihan') }}</dt>
                    <dd class="text-lg font-semibold text-primary-600 dark:text-primary-400">{{ $preview['target_count'] }}</dd>
                </div>

                <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('Dilewati (Sudah Ada)') }}</dt>
                    <dd class="text-lg font-semibold">{{ $preview['skipped_count'] }}</dd>
                </div>
            </dl>

            <div class="mt-6 grid gap-6 lg:grid-cols-2">
                <div>
                    <h3 class="mb-2 font-semibold">{{ __('Akan dibuatkan tagihan') }}</h3>

                    @if ($preview['target_count'] === 0)
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ __('Tidak ada siswa aktif yang belum memiliki tagihan untuk kombinasi ini.') }}
                        </p>
                    @else
                        <ul class="max-h-80 list-disc overflow-y-auto pl-5 text-sm">
                            @foreach ($preview['targets'] as $name)
                                <li>{{ $name }}</li>
                            @endforeach
                        </ul>

                        @if ($preview['target_count'] > count($preview['targets']))
                            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                {{ __('… dan :count siswa lainnya.', ['count' => $preview['target_count'] - count($preview['targets'])]) }}
                            </p>
                        @endif
                    @endif
                </div>

                <div>
                    <h3 class="mb-2 font-semibold">{{ __('Dilewati karena sudah memiliki tagihan') }}</h3>

                    @if ($preview['skipped_count'] === 0)
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ __('Tidak ada.') }}
                        </p>
                    @else
                        <ul class="max-h-80 list-disc overflow-y-auto pl-5 text-sm">
                            @foreach ($preview['skipped'] as $name)
                                <li>{{ $name }}</li>
                            @endforeach
                        </ul>

                        @if ($preview['skipped_count'] > count($preview['skipped']))
                            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                {{ __('… dan :count siswa lainnya.', ['count' => $preview['skipped_count'] - count($preview['skipped'])]) }}
                            </p>
                        @endif
                    @endif
                </div>
            </div>
        </x-filament::section>
    @elseif ($preview !== null)
        <x-filament::section class="mt-6">
            <x-slot name="heading">{{ __('Pratinjau sudah tidak sesuai') }}</x-slot>

            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('Isian berubah setelah pratinjau terakhir dibuat. Jalankan Pratinjau lagi sebelum menerbitkan tagihan.') }}
            </p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
