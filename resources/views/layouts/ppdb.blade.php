<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? __('PPDB Online') }} — {{ $school?->name ?? config('app.name') }}</title>

    {{--
        Arsitektur 3.2.3 — White-Label Theming Flow: warna cabang di-inject
        sebagai CSS variables, seluruh komponen memakai var(--color-primary).
        Halaman PPDB bersifat publik sehingga warna diambil dari cabang yang
        sedang dibuka, bukan dari sesi pengguna.
    --}}
    <style>
        :root {
            --color-primary: {{ $school?->primary_color ?? '#1B3A6B' }};
            --color-secondary: {{ $school?->secondary_color ?? '#E07020' }};
            --color-border: #d8dce3;
            --color-muted: #5c6474;
            --color-surface: #ffffff;
            --color-canvas: #f4f6fa;
        }

        * { box-sizing: border-box; }

        html { -webkit-text-size-adjust: 100%; }

        body {
            margin: 0;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background: var(--color-canvas);
            color: #1b2130;
            line-height: 1.55;
            /* Tidak ada elemen yang boleh mendorong halaman melebar — aturan
               yang sudah dipakai portal dan halaman muka, tetapi belum pernah
               ada di sini. PPDB justru permukaan yang paling banyak dibuka dari
               ponsel (butir 392). */
            overflow-x: hidden;
        }

        img { max-width: 100%; height: auto; }

        .ppdb-header {
            background: var(--color-primary);
            color: #fff;
            padding: 1.5rem 1rem;
        }

        .ppdb-header a { color: #fff; }

        .ppdb-shell { max-width: 44rem; margin: 0 auto; padding: 0 1rem; }

        .ppdb-header h1 { font-size: 1.35rem; margin: 0 0 .25rem; }

        .ppdb-header p { margin: 0; opacity: .85; font-size: .9rem; }

        main.ppdb-shell { padding-top: 1.75rem; padding-bottom: 3rem; }

        .card {
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: .75rem;
            padding: 1.5rem;
            margin-bottom: 1.25rem;
        }

        .card h2 { margin-top: 0; font-size: 1.05rem; }

        .field { margin-bottom: 1rem; }

        .field label { display: block; font-weight: 600; font-size: .875rem; margin-bottom: .35rem; }

        .field input, .field select, .field textarea {
            width: 100%;
            /* Sasaran sentuh yang sama dengan seluruh sistem: 2,75rem.
               Sebelumnya hanya setinggi padding, sekitar 2,3rem (butir 393). */
            min-height: 2.75rem;
            padding: .55rem .7rem;
            border: 1px solid var(--color-border);
            border-radius: .5rem;
            background: #fff;
            font: inherit;
        }

        .field input:focus, .field select:focus, .field textarea:focus {
            outline: 2px solid var(--color-primary);
            outline-offset: 1px;
        }

        .hint { color: var(--color-muted); font-size: .8rem; margin-top: .3rem; }

        .error { color: #b42318; font-size: .8rem; margin-top: .3rem; }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--color-primary);
            color: #fff;
            border: 0;
            border-radius: .5rem;
            /* Sasaran sentuh 2,75rem, sama dengan tombol portal dan halaman
               muka. Formulir PPDB dikirim dari ponsel (butir 393). */
            min-height: 2.75rem;
            padding: .65rem 1.2rem;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
        }

        .btn--secondary { background: var(--color-secondary); }

        .btn[disabled] { opacity: .6; cursor: progress; }

        .grid { display: grid; gap: 0 1rem; grid-template-columns: 1fr 1fr; }

        @media (max-width: 40rem) { .grid { grid-template-columns: 1fr; } }

        .badge {
            display: inline-block;
            padding: .2rem .6rem;
            border-radius: 999px;
            background: var(--color-secondary);
            color: #fff;
            font-size: .8rem;
            font-weight: 600;
        }

        .reg-number {
            font-size: 1.5rem;
            font-weight: 700;
            letter-spacing: .05em;
            color: var(--color-primary);
        }

        .school-list { list-style: none; margin: 0; padding: 0; }

        .school-list li + li { margin-top: .75rem; }

        .school-list a { text-decoration: none; color: inherit; display: block; }

        dl.detail { margin: 0; }

        /* Nilainya data — nama sekolah, nama calon siswa — dan panjangnya
           tidak terbatas. Tanpa `flex-wrap` pasangan label/nilai ini tidak
           dapat menyusut di bawah min-content-nya (butir 394). */
        dl.detail div { display: flex; flex-wrap: wrap; justify-content: space-between; gap: .25rem 1rem; padding: .4rem 0; border-bottom: 1px solid var(--color-border); }

        dl.detail dt { color: var(--color-muted); font-size: .875rem; }

        dl.detail dd { margin: 0; min-width: 0; font-weight: 600; text-align: right; overflow-wrap: anywhere; }

        .nav-links { margin-top: 1rem; font-size: .875rem; }

        .nav-links a { color: var(--color-primary); }

        /* -------------------------------------------- pemilih bahasa (AUTH-05) */

        .locale-switch {
            display: inline-flex;
            align-items: center;
            gap: .125rem;
            margin: .75rem 0 0;
        }

        .locale-switch__item {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            /* Sasaran sentuh untuk jari, bukan kursor. */
            min-width: 2.75rem;
            min-height: 2.75rem;
            padding: 0 .5rem;
            border-radius: .5rem;
            color: #fff;
            font-size: .8125rem;
            font-weight: 600;
            line-height: 1;
            text-decoration: none;
            /* Tombol submit, bukan tautan: gayanya harus disetel ulang.
               `font-family`, bukan `font` — shorthand-nya akan menimpa
               `font-size` dan `font-weight` di atas. */
            font-family: inherit;
            background: none;
            border: 0;
            cursor: pointer;
        }

        .locale-switch__item--active { background: rgba(255, 255, 255, .28); }

        button.locale-switch__item:hover { background: rgba(255, 255, 255, .18); }
    </style>

    @livewireStyles
</head>
<body>
    <header class="ppdb-header">
        <div class="ppdb-shell">
            <h1>{{ $school?->name ?? config('app.name') }}</h1>
            <p>{{ __('Penerimaan Peserta Didik Baru (PPDB) Online') }}</p>
            <x-locale-switch />
        </div>
    </header>

    <main class="ppdb-shell">
        {{ $slot }}
    </main>

    @livewireScripts
</body>
</html>
