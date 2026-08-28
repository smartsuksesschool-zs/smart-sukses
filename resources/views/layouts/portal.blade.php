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

    /**
     * NOTIF-04 poin 1 — "Jumlah notifikasi belum baca tampil di badge (bell
     * icon)".
     *
     * Dihitung **sekali** di sini, lalu dipakai lencana lonceng maupun entri
     * navigasi. Menghitungnya di setiap komponen akan berarti satu query per
     * elemen untuk angka yang sama (butir 211).
     *
     * Di halaman Notifikasi sendiri lencananya tidak ditampilkan, jadi tidak
     * dihitung: tata letak tidak ikut dirender ulang ketika aksi Livewire
     * berjalan, sehingga angka di lonceng akan tertinggal basi tepat di halaman
     * tempat pengguna menandai bacaannya. Halaman itu sudah menyebut jumlahnya
     * sendiri, hidup, di judulnya (butir 211).
     */
    $onNotificationPage = request()->routeIs('*.notifications');

    $unreadNotifications = (! $bare && ! $onNotificationPage && auth()->check())
        ? app(\App\Services\Notification\NotificationCenter::class)->unreadCount(auth()->user())
        : 0;

    /* Rute kotak masuk mengikuti portal yang sedang dibuka, pola yang sama
       dengan rute keluar di bawah. */
    $notificationRoute = match (true) {
        request()->routeIs('student.*') => route('student.notifications'),
        request()->routeIs('teacher.*') => route('teacher.notifications'),
        default => route('portal.notifications'),
    };
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? __('Portal Orang Tua') }} — {{ $branding->brandName() }}</title>

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

        /* Menu yang belum dapat dibuka: terlihat, tetapi bukan tautan. */
        .portal-nav__disabled {
            display: inline-flex;
            align-items: center;
            min-height: 2.75rem;
            padding: 0 .875rem;
            white-space: nowrap;
            color: var(--color-muted);
            opacity: .55;
            cursor: not-allowed;
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

        /* Hanya untuk pembaca layar: keterangan yang tidak perlu terlihat,
           tetapi juga tidak boleh hilang dari pohon aksesibilitas. */
        .portal-sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            margin: -1px;
            padding: 0;
            overflow: hidden;
            clip: rect(0 0 0 0);
            white-space: nowrap;
            border: 0;
        }

        /* ------------------------------------------- pemilih bahasa (AUTH-05) */

        .locale-switch {
            display: inline-flex;
            align-items: center;
            gap: .125rem;
        }

        .locale-switch__item {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            /* Sasaran sentuh yang sama dengan tombol portal lain. */
            min-width: 2.75rem;
            min-height: 2.75rem;
            padding: 0 .5rem;
            border-radius: .5rem;
            font-size: .8125rem;
            font-weight: 600;
            text-decoration: none;
            color: var(--color-muted);
        }

        .locale-switch__item--active { background: #eef0f4; color: #1b2130; }

        a.locale-switch__item:hover { background: #eef0f4; }

        /* Di dalam header berwarna cabang, teksnya putih. */
        .locale-switch--onbrand .locale-switch__item { color: #fff; }
        .locale-switch--onbrand .locale-switch__item--active { background: rgba(255, 255, 255, .28); }
        .locale-switch--onbrand a.locale-switch__item:hover { background: rgba(255, 255, 255, .18); }

        /* Halaman masuk tidak punya header, jadi pemilihnya berdiri sendiri. */
        .locale-switch-bar {
            display: flex;
            justify-content: center;
            padding: 1rem 1rem 0;
        }

        /* ---------------------------------------------- notifikasi (NOTIF-04) */

        .portal-bell {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 2.75rem;
            min-height: 2.75rem;
            border-radius: .5rem;
            color: #fff;
            background: rgba(255, 255, 255, .18);
        }

        .portal-bell__count {
            position: absolute;
            top: .25rem;
            right: .125rem;
            min-width: 1.15rem;
            padding: 0 .25rem;
            border-radius: 999px;
            background: var(--color-danger);
            color: #fff;
            font-size: .6875rem;
            font-weight: 700;
            line-height: 1.15rem;
            text-align: center;
        }

        .portal-nav__badge {
            display: inline-block;
            min-width: 1.15rem;
            margin-left: .375rem;
            padding: 0 .25rem;
            border-radius: 999px;
            background: var(--color-danger);
            color: #fff;
            font-size: .6875rem;
            font-weight: 700;
            line-height: 1.15rem;
            text-align: center;
        }

        .notif-head {
            display: flex;
            flex-wrap: wrap;
            gap: .75rem;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
        }

        .notif-head__title { font-size: 1.25rem; margin: 0; }

        .notif-markall {
            min-height: 2.75rem;
            padding: 0 1rem;
            border: 1px solid var(--color-border);
            border-radius: .5rem;
            background: var(--color-surface);
            font: inherit;
            cursor: pointer;
        }

        .notif-list { display: grid; gap: .75rem; }

        .notif { padding: 0; }

        /* Yang belum dibaca ditandai tebal dan bergaris tepi — bukan warna saja,
           supaya tetap terbaca tanpa persepsi warna. */
        .notif--unread { border-left: 4px solid var(--color-primary); }

        .notif--unread .notif__title { font-weight: 700; }

        .notif__toggle {
            display: block;
            width: 100%;
            /* Sasaran sentuh selebar kartu, bukan hanya judulnya. */
            min-height: 2.75rem;
            padding: 1rem;
            border: 0;
            background: none;
            font: inherit;
            color: inherit;
            text-align: left;
            cursor: pointer;
        }

        .notif__top {
            display: flex;
            flex-wrap: wrap;
            gap: .5rem;
            align-items: baseline;
        }

        .notif__title { overflow-wrap: anywhere; }

        .notif__meta {
            display: flex;
            flex-wrap: wrap;
            gap: .5rem;
            align-items: center;
            margin-top: .375rem;
            font-size: .8125rem;
            color: var(--color-muted);
        }

        .notif__body {
            padding: 0 1rem 1rem;
            border-top: 1px solid var(--color-border);
        }

        .notif__message {
            margin: .75rem 0 0;
            /* Baris baru pesan dihormati lewat CSS, bukan dengan menyisipkan
               markup ke dalam isi yang ditulis pengguna. */
            white-space: pre-line;
            overflow-wrap: anywhere;
        }

        .notif__from { margin: .75rem 0 0; }

        .portal-centered {
            max-width: 24rem;
            margin: 3rem auto;
            padding: 1rem;
        }
    </style>

    @livewireStyles
</head>
<body>
    @if ($bare)
        <div class="locale-switch-bar"><x-locale-switch /></div>
    @endif

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
                        <x-locale-switch class="locale-switch--onbrand" />

                        {{--
                            NOTIF-04 poin 1 menyebut lencana pada ikon lonceng.
                            Lonceng ini tautan ke kotak masuk portal yang sedang
                            dibuka; ketika tidak ada yang belum dibaca, lencananya
                            tidak muncul sama sekali — bukan nol yang dicetak.
                        --}}
                        <a
                            href="{{ $notificationRoute }}"
                            class="portal-bell"
                            @if (request()->routeIs('*.notifications')) aria-current="page" @endif
                        >
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                 stroke-linejoin="round" aria-hidden="true" focusable="false">
                                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                                <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                            </svg>
                            <span class="portal-sr-only">{{ __('Notifikasi') }}</span>
                            @if ($unreadNotifications > 0)
                                <span class="portal-bell__count">{{ $unreadNotifications }}</span>
                                <span class="portal-sr-only">{{ __(':count belum dibaca', ['count' => $unreadNotifications]) }}</span>
                            @endif
                        </a>

                        <span>{{ auth()->user()->name }}</span>
                        {{--
                            Guru berbagi sesi `web` dengan panel, jadi keluarnya
                            lewat rute keluar panel — satu login, satu keluar
                            (butir 179).
                        --}}
                        @php
                            $logoutRoute = match (true) {
                                request()->routeIs('student.*') => route('student.logout'),
                                request()->routeIs('teacher.*') => route('filament.admin.auth.logout'),
                                default => route('portal.logout'),
                            };
                        @endphp
                        <form method="POST" action="{{ $logoutRoute }}">
                            @csrf
                            <button type="submit" class="portal-logout">{{ __('Keluar') }}</button>
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
                tautan mati. Sejak Batch 8.2 Notifikasi termasuk di antaranya di
                ketiga portal — halamannya nyata, jadi menunya tautan sungguhan
                dan bukan lagi penanda mati (butir 208).

                Ketiga portal berbagi tata letak ini, dan menunya mengikuti yang
                sedang dibuka — orang tua tidak pernah melihat menu guru, dan
                sebaliknya.
            --}}
            <nav class="portal-nav">
                <div class="portal-nav__inner">
                    @if (request()->routeIs('student.*'))
                        {{--
                            PORTAL-03 poin 1 — keempat menu, dan keempatnya kini
                            benar-benar dapat dibuka: Notifikasi tidak lagi
                            penanda mati (butir 208).
                        --}}
                        <a href="{{ route('student.schedule') }}"
                           @if (request()->routeIs('student.schedule')) aria-current="page" @endif>{{ __('Jadwal') }}</a>
                        <a href="{{ route('student.grades') }}"
                           @if (request()->routeIs('student.grades')) aria-current="page" @endif>{{ __('Nilai') }}</a>
                        {{-- Ujian online: tambahan scope atas permintaan pemilik,
                             di luar keempat menu PORTAL-03. --}}
                        <a href="{{ route('student.exams') }}"
                           @if (request()->routeIs('student.exam*')) aria-current="page" @endif>{{ __('Ujian') }}</a>
                        <x-portal.notification-nav
                            :route="route('student.notifications')"
                            :active="request()->routeIs('student.notifications')"
                            :count="$unreadNotifications"
                        />
                        <a href="{{ route('student.profile') }}"
                           @if (request()->routeIs('student.profile')) aria-current="page" @endif>{{ __('Profil') }}</a>
                    @elseif (request()->routeIs('teacher.*'))
                        <a href="{{ route('teacher.dashboard') }}"
                           @if (request()->routeIs('teacher.dashboard')) aria-current="page" @endif>{{ __('Dasbor') }}</a>
                        <a href="{{ route('teacher.classes') }}"
                           @if (request()->routeIs('teacher.classes') || request()->routeIs('teacher.class-students')) aria-current="page" @endif>{{ __('Kelas Ajar') }}</a>
                        <a href="{{ route('teacher.schedule') }}"
                           @if (request()->routeIs('teacher.schedule')) aria-current="page" @endif>{{ __('Jadwal') }}</a>
                        <x-portal.notification-nav
                            :route="route('teacher.notifications')"
                            :active="request()->routeIs('teacher.notifications')"
                            :count="$unreadNotifications"
                        />
                        {{-- Input Nilai memang menyeberang ke panel: alur
                             penilaiannya sudah ada di sana (butir 178). --}}
                        <a href="{{ \App\Filament\Pages\InputNilai::getUrl() }}">{{ __('Input Nilai') }}</a>
                    @else
                        <a href="{{ route('portal.dashboard') }}"
                           @if (request()->routeIs('portal.dashboard')) aria-current="page" @endif>{{ __('Ringkasan') }}</a>
                        <a href="{{ route('portal.grades') }}"
                           @if (request()->routeIs('portal.grades')) aria-current="page" @endif>{{ __('Nilai') }}</a>
                        <a href="{{ route('portal.fees') }}"
                           @if (request()->routeIs('portal.fees')) aria-current="page" @endif>{{ __('Tagihan') }}</a>
                        <a href="{{ route('portal.schedule') }}"
                           @if (request()->routeIs('portal.schedule')) aria-current="page" @endif>{{ __('Jadwal') }}</a>
                        <x-portal.notification-nav
                            :route="route('portal.notifications')"
                            :active="request()->routeIs('portal.notifications')"
                            :count="$unreadNotifications"
                        />
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
