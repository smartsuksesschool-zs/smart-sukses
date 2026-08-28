{{--
    Pemilih bahasa ID / EN pada topbar panel Filament.

    Tampilannya ditulis terpisah dari `<x-locale-switch />` karena panel tidak
    memuat CSS ketiga tata letak publik, dan menambahkan lembar gaya baru hanya
    untuk dua tautan berarti menambah langkah build yang belum pernah
    dijalankan siapa pun. Gaya inline di sini tidak menuntut apa pun dari
    pipeline aset (butir 383).

    Perilakunya sama persis: tautan biasa ke `locale.switch`, tanpa JavaScript,
    dengan sasaran sentuh 2.75rem supaya tetap dapat ditekan jari pada tablet.
--}}
@php
    $current = app()->getLocale();
    $base = 'display:inline-flex;align-items:center;justify-content:center;'
        .'min-width:2.75rem;min-height:2.75rem;padding:0 .5rem;border-radius:.5rem;'
        .'font-size:.8125rem;font-weight:600;text-decoration:none;';
    $srOnly = 'position:absolute;width:1px;height:1px;margin:-1px;padding:0;'
        .'overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0;';
@endphp

<div
    class="fi-locale-switch"
    style="display:inline-flex;align-items:center;gap:.125rem;margin-inline-end:.5rem;"
    role="group"
    aria-label="{{ __('Bahasa') }}"
>
    @foreach (\App\Support\Locale::options() as $code => $name)
        @if ($code === $current)
            <span
                class="fi-color-primary"
                style="{{ $base }}background:rgba(var(--primary-500), .12);color:rgb(var(--primary-600));"
                aria-current="true"
            >
                {{ \App\Support\Locale::shortLabel($code) }}
                <span style="{{ $srOnly }}">{{ $name }}</span>
            </span>
        @else
            <a
                href="{{ route('locale.switch', ['locale' => $code]) }}"
                lang="{{ $code }}"
                hreflang="{{ $code }}"
                class="fi-link"
                style="{{ $base }}color:rgb(107, 114, 128);"
            >
                {{ \App\Support\Locale::shortLabel($code) }}
                <span style="{{ $srOnly }}">{{ $name }}</span>
            </a>
        @endif
    @endforeach
</div>
