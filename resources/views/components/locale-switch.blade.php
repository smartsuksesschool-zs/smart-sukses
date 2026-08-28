{{--
    Pemilih bahasa ID / EN.

    Dipakai bersama halaman muka, PPDB, ketiga portal, dan panel Filament —
    satu tampilan untuk satu mekanisme. Menyalinnya per tata letak akan
    melahirkan empat tombol yang perlahan berbeda perilakunya (butir 381).

    Bentuknya tautan biasa, bukan `<select>` dengan JavaScript: halaman muka dan
    PPDB sengaja tidak menuntut satu baris skrip pun, dan tautan tetap berfungsi
    bagi pembaca layar maupun navigasi papan ketik tanpa penanganan tambahan.
    Bahasa yang sedang aktif dirender sebagai `<span>`, bukan tautan ke dirinya
    sendiri.

    Keterangan pembaca layar memakai gaya inline, bukan kelas: keempat tata
    letak yang memuat komponen ini menamai kelas "hanya untuk pembaca layar"
    dengan nama yang berbeda-beda, dan panel Filament tidak memuat satu pun di
    antaranya (butir 382).
--}}
@props(['class' => null])

@php
    $current = app()->getLocale();
    $srOnly = 'position:absolute;width:1px;height:1px;margin:-1px;padding:0;'
        .'overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0;';
@endphp

<div
    {{ $attributes->merge(['class' => trim('locale-switch '.($class ?? ''))]) }}
    role="group"
    aria-label="{{ __('Bahasa') }}"
>
    @foreach (\App\Support\Locale::options() as $code => $name)
        @if ($code === $current)
            <span class="locale-switch__item locale-switch__item--active" aria-current="true">
                {{ \App\Support\Locale::shortLabel($code) }}
                <span style="{{ $srOnly }}">{{ $name }}</span>
            </span>
        @else
            <a
                class="locale-switch__item"
                href="{{ route('locale.switch', ['locale' => $code]) }}"
                lang="{{ $code }}"
                hreflang="{{ $code }}"
            >
                {{ \App\Support\Locale::shortLabel($code) }}
                <span style="{{ $srOnly }}">{{ $name }}</span>
            </a>
        @endif
    @endforeach
</div>
