{{--
    NOTIF-04 — kotak masuk penerima di panel admin.

    Bukan halaman Pengumuman: yang di sini hanya notifikasi yang ditujukan kepada
    pengguna yang sedang masuk, tanpa draf dan tanpa milik orang lain
    (butir 218).

    Isi notifikasi ditulis manusia dan tetap diperlakukan sebagai data tampilan
    yang tidak dipercaya: seluruhnya dirender `{{ }}` yang ter-escape, dan baris
    barunya diatur `whitespace-pre-line` — tidak ada `{!! !!}` di halaman ini
    (butir 210).
--}}
@php
    // Dipanggil sekali masing-masing: satu query untuk daftarnya (ditambah satu
    // untuk nama pengirim) dan satu agregat untuk hitungannya (butir 219).
    $items = $this->items();
    $unreadCount = $this->unreadCount();
@endphp

<x-filament-panels::page>
    <div class="flex flex-wrap items-center justify-between gap-3">
        {{-- `aria-live` supaya angkanya terdengar berubah setelah ditandai,
             bukan hanya terlihat. --}}
        <p class="text-sm text-gray-500 dark:text-gray-400" aria-live="polite">
            @if ($unreadCount > 0)
                {{ $unreadCount }} belum dibaca dari {{ count($items) }} notifikasi
            @elseif (count($items) > 0)
                Semua notifikasi sudah dibaca
            @else
                Belum ada notifikasi
            @endif
        </p>

        @if ($unreadCount > 0)
            <x-filament::button
                color="gray"
                wire:click="markAllRead"
                wire:loading.attr="disabled"
            >
                Tandai semua dibaca
            </x-filament::button>
        @endif
    </div>

    <div class="space-y-3">
        @forelse ($items as $item)
            {{-- Yang belum dibaca ditandai garis tepi dan judul tebal, bukan
                 warna saja. --}}
            <x-filament::section
                :compact="true"
                @class([
                    'border-l-4',
                    'border-l-primary-600' => ! $item['is_read'],
                    'border-l-transparent' => $item['is_read'],
                ])
            >
                <button
                    type="button"
                    class="flex w-full flex-col gap-1 text-start"
                    wire:click="open({{ $item['id'] }})"
                    aria-expanded="{{ $this->openId === $item['id'] ? 'true' : 'false' }}"
                >
                    <span class="flex flex-wrap items-baseline gap-2">
                        <span @class([
                            'text-gray-950 dark:text-white',
                            'font-bold' => ! $item['is_read'],
                            'font-medium' => $item['is_read'],
                        ])>{{ $item['title'] }}</span>

                        @if ($item['type_label'])
                            <x-filament::badge color="gray">{{ $item['type_label'] }}</x-filament::badge>
                        @endif
                    </span>

                    <span class="flex flex-wrap items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                        @if ($item['sent_at_label'])
                            <time datetime="{{ $item['sent_at'] }}">{{ $item['sent_at_label'] }}</time>
                        @endif

                        {{-- Keadaan terbaca selalu punya teksnya sendiri. --}}
                        @if ($item['is_read'])
                            <x-filament::badge color="gray">Sudah dibaca</x-filament::badge>
                        @else
                            <x-filament::badge color="warning">Belum dibaca</x-filament::badge>
                        @endif
                    </span>
                </button>

                @if ($this->openId === $item['id'])
                    <div class="mt-3 border-t border-gray-200 pt-3 dark:border-white/10">
                        <p class="whitespace-pre-line text-sm text-gray-950 dark:text-white">{{ $item['message'] }}</p>

                        <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                            @if ($item['sender_name'])
                                Dari {{ $item['sender_name'] }}
                            @else
                                Notifikasi sistem
                            @endif

                            @if ($item['read_at_label'])
                                &middot; Dibaca {{ $item['read_at_label'] }}
                            @endif
                        </p>
                    </div>
                @endif
            </x-filament::section>
        @empty
            <x-filament::section>
                <div class="font-medium text-gray-950 dark:text-white">Belum ada notifikasi</div>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Pengumuman yang ditujukan kepada Anda akan muncul di halaman ini.
                </p>
            </x-filament::section>
        @endforelse
    </div>
</x-filament-panels::page>
