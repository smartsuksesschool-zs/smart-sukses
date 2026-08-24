@php
    /**
     * Tata letak Parent Portal.
     *
     * Arsitektur 3.2.3 — White-Label Theming Flow: warna cabang disuntikkan
     * sebagai CSS variables. Berbeda dari halaman PPDB yang publik, di sini
     * cabangnya berasal dari pengguna yang sedang login, jadi SchoolBranding
     * yang menentukan — bukan kode cabang di URL.
     *
     * PORTAL-01 poin 3: "Responsive untuk tampilan mobile". Tata letaknya
     * mobile-first: satu kolom sebagai bawaan, dan baru melebar pada layar
     * besar.
     */
    $branding = app(\App\Support\SchoolBranding::class);
    $school = $branding->currentSchool();
    $bare = $bare ?? false;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Portal Orang Tua' }} — {{ $branding->brandName() }}</title>

    <style>
        :root {
            --color-primary: {{ $branding->primaryColor($school) }};
            --color-secondary: {{ $branding->secondaryColor($school) }};
            --color-border: #d8dce3;
            --color-muted: #5c6474;
            --color-surface: #ffffff;
            --color-canvas: #f4f6fa;
            --color-danger: #b3261e;
        }

        * { box-sizing: border-box; }

        html { -webkit-text-size-adjust: 100%; }

        body {
            margin: 0;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background: var(--color-canvas);
            color: #1b2130;
            line-height: 1.55;
            /* Tidak ada satu pun elemen yang boleh mendorong halaman melebar
               ke samping pada layar kecil. */
            overflow-x: hidden;
        }

        img { max-width: 100%; height: auto; }

        .portal-header {
            background: var(--color-primary);
            color: #fff;
            padding: 1rem;
        }

        .portal-header__inner {
            max-width: 60rem;
            margin: 0 auto;
            display: flex;
            flex-wrap: wrap;
            gap: .75rem;
            align-items: center;
            justify-content: space-between;
        }

        .portal-brand {
            display: flex;
            align-items: center;
            gap: .625rem;
            min-width: 0;
        }

        .portal-brand__logo { height: 2rem; width: auto; }

        .portal-brand__name {
            font-weight: 600;
            font-size: 1rem;
            overflow-wrap: anywhere;
        }

        .portal-user {
            display: flex;
            align-items: center;
            gap: .75rem;
            font-size: .875rem;
        }

        .portal-logout {
            background: rgba(255, 255, 255, .18);
            color: #fff;
            border: 0;
            border-radius: .5rem;
            /* Sasaran sentuh yang cukup besar untuk jari, bukan kursor. */
            min-height: 2.75rem;
            padding: 0 1rem;
            font: inherit;
            cursor: pointer;
        }

        .portal-main {
            max-width: 60rem;
            margin: 0 auto;
            padding: 1rem;
        }

        .portal-card {
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: .75rem;
            padding: 1rem;
        }

        /* Mobile-first: satu kolom, menumpuk. Baru menjadi tiga kolom ketika
           layarnya memang cukup lebar. */
        .portal-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        @media (min-width: 48rem) {
            .portal-grid--3 { grid-template-columns: repeat(3, 1fr); }
            .portal-grid--2 { grid-template-columns: repeat(2, 1fr); }
        }

        .portal-muted { color: var(--color-muted); font-size: .875rem; }

        .portal-metric {
            font-size: 1.5rem;
            font-weight: 700;
            overflow-wrap: anywhere;
        }

        .portal-label {
            font-size: .8125rem;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: var(--color-muted);
        }

        .portal-child-switcher {
            display: flex;
            flex-wrap: wrap;
            gap: .5rem;
            margin-bottom: 1rem;
        }

        .portal-child-button {
            min-height: 2.75rem;
            padding: .5rem 1rem;
            border-radius: 999px;
            border: 1px solid var(--color-border);
            background: var(--color-surface);
            font: inherit;
            cursor: pointer;
        }

        .portal-child-button[aria-pressed="true"] {
            background: var(--color-primary);
            border-color: var(--color-primary);
            color: #fff;
        }

        .portal-list { list-style: none; margin: 0; padding: 0; }

        .portal-list li {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            padding: .625rem 0;
            border-bottom: 1px solid var(--color-border);
        }

        .portal-list li:last-child { border-bottom: 0; }

        .portal-field { margin-bottom: 1rem; }

        .portal-field label { display: block; font-size: .875rem; margin-bottom: .375rem; }

        .portal-field input {
            width: 100%;
            min-height: 2.75rem;
            padding: .5rem .75rem;
            border: 1px solid var(--color-border);
            border-radius: .5rem;
            font: inherit;
            /* 16px mencegah iOS memperbesar halaman saat input difokuskan. */
            font-size: 1rem;
        }

        .portal-button {
            width: 100%;
            min-height: 2.75rem;
            border: 0;
            border-radius: .5rem;
            background: var(--color-primary);
            color: #fff;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
        }

        .portal-error { color: var(--color-danger); font-size: .875rem; margin-top: .375rem; }


        /* Navigasi portal. Menggulung ke samping hanya bila memang tidak muat,
           dan tidak pernah mendorong badan halaman melebar. */
        .portal-nav {
            background: var(--color-surface);
            border-bottom: 1px solid var(--color-border);
        }

        .portal-nav__inner {
            max-width: 60rem;
            margin: 0 auto;
            display: flex;
            gap: .25rem;
            padding: 0 .5rem;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .portal-nav a {
            display: inline-flex;
            align-items: center;
            min-height: 2.75rem;
            padding: 0 .875rem;
            white-space: nowrap;
            text-decoration: none;
            color: var(--color-muted);
            border-bottom: 2px solid transparent;
        }

        .portal-nav a[aria-current="page"] {
            color: var(--color-primary);
            border-bottom-color: var(--color-primary);
            font-weight: 600;
        }

        .portal-table { width: 100%; border-collapse: collapse; }

        .portal-table th,
        .portal-table td {
            text-align: left;
            padding: .5rem 0;
            border-bottom: 1px solid var(--color-border);
            font-size: .9375rem;
        }

        .portal-badge {
            display: inline-block;
            padding: .125rem .5rem;
            border-radius: 999px;
            font-size: .75rem;
            font-weight: 600;
            border: 1px solid var(--color-border);
        }

        .portal-badge--danger { background: #fdecea; border-color: #f5c2bd; color: #8c1d18; }
        .portal-badge--warning { background: #fff4e5; border-color: #ffd8a8; color: #8a5300; }
        .portal-badge--success { background: #e7f6ec; border-color: #b7e0c4; color: #1b5e2f; }
        .portal-badge--muted { background: #eef0f4; color: var(--color-muted); }

        .portal-scroll { overflow-x: auto; }

        .portal-centered {
            max-width: 24rem;
            margin: 3rem auto;
            padding: 1rem;
        }
    </style>

    @livewireStyles
</head>
<body>
    @unless ($bare)
        <header class="portal-header">
            <div class="portal-header__inner">
                <div class="portal-brand">
                    @if ($branding->logoUrl($school))
                        <img src="{{ $branding->logoUrl($school) }}" alt="" class="portal-brand__logo">
                    @endif
                    <span class="portal-brand__name">{{ $branding->brandName() }}</span>
                </div>

                @auth
                    <div class="portal-user">
                        <span>{{ auth()->user()->name }}</span>
                        {{--
                            Guru berbagi sesi `web` dengan panel, jadi keluarnya
                            lewat rute keluar panel — satu login, satu keluar
                            (butir 179).
                        --}}
                        <form method="POST" action="{{ request()->routeIs('teacher.*')
                            ? route('filament.admin.auth.logout')
                            : route('portal.logout') }}">
                            @csrf
                            <button type="submit" class="portal-logout">Keluar</button>
                        </form>
                    </div>
                @endauth
            </div>
        </header>
    @endunless

    @unless ($bare)
        @auth
            {{--
                Navigasi portal. Hanya halaman yang benar-benar ada; tidak ada
                tautan mati. Notifikasi milik Sprint 8 dan karena itu belum
                muncul di sini (butir 168, 175).

                Dua portal berbagi tata letak ini, dan menunya mengikuti yang
                sedang dibuka — orang tua tidak pernah melihat menu guru, dan
                sebaliknya.
            --}}
            <nav class="portal-nav">
                <div class="portal-nav__inner">
                    @if (request()->routeIs('teacher.*'))
                        <a href="{{ route('teacher.dashboard') }}"
                           @if (request()->routeIs('teacher.dashboard')) aria-current="page" @endif>Dasbor</a>
                        <a href="{{ route('teacher.classes') }}"
                           @if (request()->routeIs('teacher.classes') || request()->routeIs('teacher.class-students')) aria-current="page" @endif>Kelas Ajar</a>
                        <a href="{{ route('teacher.schedule') }}"
                           @if (request()->routeIs('teacher.schedule')) aria-current="page" @endif>Jadwal</a>
                        {{-- Input Nilai memang menyeberang ke panel: alur
                             penilaiannya sudah ada di sana (butir 178). --}}
                        <a href="{{ \App\Filament\Pages\InputNilai::getUrl() }}">Input Nilai</a>
                    @else
                        <a href="{{ route('portal.dashboard') }}"
                           @if (request()->routeIs('portal.dashboard')) aria-current="page" @endif>Ringkasan</a>
                        <a href="{{ route('portal.grades') }}"
                           @if (request()->routeIs('portal.grades')) aria-current="page" @endif>Nilai</a>
                        <a href="{{ route('portal.fees') }}"
                           @if (request()->routeIs('portal.fees')) aria-current="page" @endif>Tagihan</a>
                        <a href="{{ route('portal.schedule') }}"
                           @if (request()->routeIs('portal.schedule')) aria-current="page" @endif>Jadwal</a>
                    @endif
                </div>
            </nav>
        @endauth
    @endunless

    <main class="portal-main">
        {{ $slot }}
    </main>

    @livewireScripts
</body>
</html>
