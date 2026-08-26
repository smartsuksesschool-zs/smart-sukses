{{--
    NOTIF-02 — daftar wa.me siap kirim per penerima.

    Pengirimannya manual: halaman ini hanya membuat tautan. Tidak ada tombol
    "kirim semua", dan tidak ada tab yang dibuka otomatis — membuka puluhan tab
    WhatsApp sekaligus bukan bantuan (butir 232).

    Nama dan nomor ditulis manusia dan diperlakukan sebagai data yang tidak
    dipercaya: seluruhnya dirender `{{ }}` yang ter-escape.
--}}
@php
    // Satu panggilan: daftar, ringkasan, dan penyaringnya berasal dari satu
    // service yang sama dengan endpoint API-nya.
    $links = $this->links();
@endphp

<x-filament-panels::page>
    @if ($links === null)
        {{-- Draf belum menjadi komunikasi kepada siapa pun, jadi belum ada yang
             "siap kirim" — dan tidak satu pun nomor telepon dibaca (butir 224). --}}
        <x-filament::section>
            <div class="font-medium text-gray-950 dark:text-white">Pengumuman ini masih draf</div>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Tautan wa.me baru tersedia setelah pengumuman dikirim. Isi dan targetnya masih dapat diubah
                sampai saat itu.
            </p>
        </x-filament::section>
    @else
        <x-filament::section :compact="true">
            <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm" aria-live="polite">
                <span class="text-gray-950 dark:text-white">
                    <span class="font-semibold">{{ $links['summary']['recipient_count'] }}</span> penerima
                </span>

                <span class="text-gray-500 dark:text-gray-400">
                    <span class="font-semibold text-success-600 dark:text-success-400">{{ $links['summary']['available_count'] }}</span>
                    dapat dikirimi WhatsApp
                </span>

                <span class="text-gray-500 dark:text-gray-400">
                    <span class="font-semibold text-danger-600 dark:text-danger-400">{{ $links['summary']['unavailable_count'] }}</span>
                    tanpa nomor yang dapat dipakai
                </span>

                <span class="text-gray-500 dark:text-gray-400">Target: {{ $this->targetSummary() }}</span>
            </div>

            {{-- Angka di atas selalu menghitung seluruh penerima, bukan hanya
                 yang tampil setelah disaring (butir 226). --}}
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                Ringkasan menghitung seluruh penerima pengumuman ini, termasuk yang tidak tampil karena penyaringan.
            </p>
        </x-filament::section>

        <x-filament::section :compact="true">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <div class="flex-1">
                    <label for="wa-links-search" class="mb-1 block text-sm font-medium text-gray-950 dark:text-white">
                        Cari nama atau nomor
                    </label>
                    <x-filament::input.wrapper>
                        <x-filament::input
                            type="search"
                            id="wa-links-search"
                            wire:model.live.debounce.400ms="search"
                            placeholder="Nama penerima atau nomor HP"
                        />
                    </x-filament::input.wrapper>
                </div>

                <div class="sm:w-64">
                    <label for="wa-links-availability" class="mb-1 block text-sm font-medium text-gray-950 dark:text-white">
                        Ketersediaan
                    </label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select id="wa-links-availability" wire:model.live="availability">
                            <option value="">Semua penerima</option>
                            <option value="available">Dapat dikirimi WhatsApp</option>
                            <option value="unavailable">Tidak dapat dikirimi</option>
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
            </div>
        </x-filament::section>

        <div class="space-y-3">
            @forelse ($links['recipients'] as $recipient)
                <x-filament::section
                    :compact="true"
                    @class([
                        'border-l-4',
                        'border-l-success-600' => $recipient['wa_available'],
                        'border-l-danger-600' => ! $recipient['wa_available'],
                    ])
                >
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <div class="font-medium text-gray-950 dark:text-white">{{ $recipient['name'] }}</div>

                            <div class="mt-1 flex flex-wrap items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                                @if ($recipient['phone'])
                                    <span>{{ $recipient['phone'] }}</span>
                                @endif

                                @if ($recipient['wa_available'])
                                    <x-filament::badge color="success">
                                        wa.me/{{ $recipient['normalized_phone'] }}
                                    </x-filament::badge>
                                @else
                                    {{-- Alasannya selalu tertulis: penerima tanpa
                                         nomor tidak dibuang diam-diam dari daftar. --}}
                                    <x-filament::badge color="danger">{{ $recipient['reason_label'] }}</x-filament::badge>
                                @endif
                            </div>
                        </div>

                        @if ($recipient['wa_available'])
                            <div
                                class="flex shrink-0 flex-col items-stretch gap-2 sm:flex-row sm:items-center"
                                x-data="{
                                    copied: false,
                                    manual: false,
                                    copy(url) {
                                        if (! navigator.clipboard || ! window.isSecureContext) {
                                            this.manual = true

                                            return
                                        }

                                        navigator.clipboard.writeText(url).then(() => {
                                            this.copied = true
                                            this.manual = false
                                            setTimeout(() => this.copied = false, 2000)
                                        }).catch(() => this.manual = true)
                                    },
                                }"
                            >
                                <div class="flex items-center gap-2">
                                    {{-- NOTIF-02 poin 2 — "disalin satu per satu".
                                         Satu tombol per penerima; tidak ada salin
                                         massal, karena kirimnya pun satu per satu. --}}
                                    <x-filament::button
                                        size="xs"
                                        color="gray"
                                        icon="heroicon-m-clipboard"
                                        x-on:click="copy(@js($recipient['wa_url']))"
                                    >
                                        <span x-show="! copied">Salin link</span>
                                        <span x-show="copied" x-cloak>Tersalin</span>
                                    </x-filament::button>

                                    {{-- NOTIF-02 poin 3 — "Tombol 'Buka WA' membuka
                                         WhatsApp dengan pesan terisi". Satu tab, dan
                                         hanya bila diklik. --}}
                                    <x-filament::button
                                        size="xs"
                                        color="success"
                                        icon="heroicon-m-chat-bubble-left-right"
                                        tag="a"
                                        :href="$recipient['wa_url']"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        Buka WA
                                    </x-filament::button>
                                </div>

                                {{-- Clipboard API tidak selalu ada (HTTP, peramban
                                     lama). Tautannya lalu ditampilkan supaya tetap
                                     dapat disalin manual, bukan gagal diam-diam. --}}
                                <div x-show="manual" x-cloak class="sm:w-80">
                                    <input
                                        type="text"
                                        readonly
                                        value="{{ $recipient['wa_url'] }}"
                                        x-on:focus="$el.select()"
                                        class="w-full rounded-lg border border-gray-300 bg-white px-2 py-1 text-xs text-gray-950 dark:border-white/20 dark:bg-white/5 dark:text-white"
                                        aria-label="Link WhatsApp untuk {{ $recipient['name'] }}"
                                    />
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        Salin otomatis tidak tersedia. Pilih teks di atas lalu salin manual.
                                    </p>
                                </div>
                            </div>
                        @endif
                    </div>
                </x-filament::section>
            @empty
                <x-filament::section>
                    <div class="font-medium text-gray-950 dark:text-white">Tidak ada penerima yang cocok</div>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        @if ($links['summary']['recipient_count'] === 0)
                            Pengumuman ini tidak memiliki penerima.
                        @else
                            Ubah kata pencarian atau penyaring ketersediaan untuk melihat penerima lainnya.
                        @endif
                    </p>
                </x-filament::section>
            @endforelse
        </div>
    @endif
</x-filament-panels::page>
