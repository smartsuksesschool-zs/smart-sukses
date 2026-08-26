{{--
    NOTIF-03 poin 2 — template teks notifikasi yang dapat diedit Admin Sekolah.

    Template adalah teks biasa dengan placeholder `[…]`, bukan markup: isinya
    tidak pernah dirender sebagai HTML di mana pun, dan halaman ini pun
    menampilkannya lewat textarea biasa.
--}}
<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6 flex justify-end">
            <x-filament::button type="submit" wire:loading.attr="disabled">
                {{ __('Simpan Template') }}
            </x-filament::button>
        </div>
    </form>

    <x-filament::section class="mt-6" :collapsible="true" :collapsed="true">
        <x-slot name="heading">{{ __('Cara template ini dipakai') }}</x-slot>

        <div class="space-y-3 text-sm text-gray-500 dark:text-gray-400">
            <p>
                {{ __('Teks disalin apa adanya ke pesan WhatsApp; placeholder di dalam kurung siku diganti nilai sebenarnya saat notifikasi terbit. Token selain yang tercantum pada masing-masing kolom tidak dikenali dan akan ikut terkirim sebagaimana ditulis.') }}
            </p>

            <p>
                {{ __('Notifikasi yang sudah terbit menyimpan salinan teksnya sendiri. Mengubah template di halaman ini tidak mengubah pesan yang sudah terlanjur dibuat — perubahannya berlaku bagi kejadian berikutnya.') }}
            </p>

            <p>
                {{ __('Pengiriman WhatsApp tetap manual. Sistem hanya menyiapkan tautan wa.me yang dapat dibuka Admin dari daftar penerima; tidak ada pesan yang dikirim sendiri oleh sistem.') }}
            </p>
        </div>
    </x-filament::section>
</x-filament-panels::page>
