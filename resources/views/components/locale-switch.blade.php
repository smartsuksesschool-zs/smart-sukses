{{--
    Pemilih bahasa ID / EN.

    Dipakai bersama halaman muka, PPDB, ketiga portal, dan panel Filament —
    satu tampilan untuk satu mekanisme. Menyalinnya per tata letak akan
    melahirkan empat tombol yang perlahan berbeda perilakunya (butir 381).

    **Form POST, bukan tautan.** Menekan tombol ini menulis — sesi bagi tamu,
    `users.locale` bagi pengguna yang login — dan penulisan tidak boleh
    dilakukan lewat GET: tautan GET dapat dipicu prefetch peramban, crawler,
    atau `<img src>` di situs lain, dan tidak dilindungi CSRF sama sekali
    (butir 388).

    Tetap tanpa satu baris JavaScript pun. Satu `<form>` dengan dua tombol
    submit yang masing-masing membawa `formaction` sendiri — HTML biasa, satu
    token CSRF, dan tetap dapat dicapai papan ketik maupun pembaca layar.
    Halaman muka dan PPDB sengaja tidak menuntut skrip (butir 345).

    Bahasa yang sedang aktif dirender `<span>`, bukan tombol ke dirinya
    sendiri. Keterangan pembaca layar memakai gaya inline karena keempat tata
    letak menamai kelas "hanya untuk pembaca layar" dengan nama berbeda, dan
    panel Filament tidak memuat satu pun di antaranya (butir 382).
--}}
@props(['class' => null])

@php
    $current = app()->getLocale();
    $srOnly = 'position:absolute;width:1px;height:1px;margin:-1px;padding:0;'
        .'overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0;';
@endphp

<form
    method="POST"
    action="{{ route('locale.switch', ['locale' => $current]) }}"
    {{ $attributes->merge(['class' => trim('locale-switch '.($class ?? ''))]) }}
    role="group"
    aria-label="{{ __('Bahasa') }}"
>
    @csrf

    @foreach (\App\Support\Locale::options() as $code => $name)
        @if ($code === $current)
            <span class="locale-switch__item locale-switch__item--active" aria-current="true">
                {{ \App\Support\Locale::shortLabel($code) }}
                <span style="{{ $srOnly }}">{{ $name }}</span>
            </span>
        @else
            <button
                type="submit"
                class="locale-switch__item"
                formaction="{{ route('locale.switch', ['locale' => $code]) }}"
                lang="{{ $code }}"
            >
                {{ \App\Support\Locale::shortLabel($code) }}
                <span style="{{ $srOnly }}">{{ $name }}</span>
            </button>
        @endif
    @endforeach
</form>
