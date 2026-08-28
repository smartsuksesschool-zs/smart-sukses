@php
    /**
     * Tata letak halaman muka publik.
     *
     * Tambahan langsung atas permintaan pemilik, di luar blueprint Phase 1
     * (docs/owner-scope-changes.md bagian A).
     *
     * **Warnanya warna platform, bukan warna cabang mana pun.** Halaman ini
     * memakai konstanta `SchoolBranding::FALLBACK_*` secara langsung dan tidak
     * pernah memanggil `currentSchool()`. Sebabnya nyata: bila seorang pengguna
     * cabang sedang login lalu membuka `/`, `currentSchool()` akan
     * mengembalikan cabangnya, dan halaman muka umum akan tampil memakai
     * white-label milik satu sekolah seolah-olah itu identitas seluruh platform
     * (butir 344).
     *
     * CSS-nya ditulis tangan di sini, tanpa Vite. Alasannya bukan selera:
     * `public/build` tidak pernah ada di project ini, dan kedua tata letak yang
     * benar-benar terpasang — portal dan PPDB — memakai CSS inline dengan CSS
     * custom properties. Menjadikan `npm run build` syarat untuk merender `/`
     * berarti menambah langkah deployment yang belum pernah dijalankan siapa
     * pun, pada minggu yang sama dengan go-live (butir 345).
     */
    use App\Support\SchoolBranding;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="@yield('description', 'Platform manajemen sekolah terintegrasi: PPDB, akademik, keuangan, ujian online, dan portal pengguna.')">
    <title>@yield('title', config('app.name'))</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">

    <style>
        :root {
            /* Warna platform (SchoolBranding::FALLBACK_*), bukan warna cabang. */
            --brand: {{ SchoolBranding::FALLBACK_PRIMARY }};
            --accent: {{ SchoolBranding::FALLBACK_SECONDARY }};

            --brand-dark: #142c52;
            --ink: #16202f;
            --muted: #5b6577;
            --line: #e2e6ee;
            --surface: #ffffff;
            --canvas: #f6f8fc;
            --soft: #eef2f9;
        }

        *, *::before, *::after { box-sizing: border-box; }

        html {
            -webkit-text-size-adjust: 100%;
            scroll-behavior: smooth;
            /* Judul bagian tidak tersembunyi di balik navbar lengket. */
            scroll-padding-top: 5rem;
        }

        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }
        }

        body {
            margin: 0;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background: var(--canvas);
            color: var(--ink);
            line-height: 1.6;
            /* Tidak ada elemen yang boleh mendorong halaman melebar. */
            overflow-x: hidden;
        }

        img { max-width: 100%; height: auto; }

        h1, h2, h3 { line-height: 1.25; margin: 0; }

        p { margin: 0; }

        a { color: var(--brand); }

        /* Fokus keyboard harus terlihat di seluruh halaman. */
        a:focus-visible,
        button:focus-visible {
            outline: 3px solid var(--accent);
            outline-offset: 2px;
            border-radius: .35rem;
        }

        .shell {
            width: 100%;
            max-width: 72rem;
            margin: 0 auto;
            padding: 0 1.25rem;
        }

        /* ------------------------------------------------------------ navbar */

        .nav {
            position: sticky;
            top: 0;
            z-index: 20;
            background: rgba(255, 255, 255, .95);
            border-bottom: 1px solid var(--line);
            backdrop-filter: saturate(140%) blur(6px);
        }

        .nav__inner {
            display: flex;
            align-items: center;
            gap: 1rem;
            min-height: 4rem;
        }

        .brand {
            display: inline-flex;
            align-items: center;
            gap: .6rem;
            font-weight: 700;
            color: var(--brand);
            text-decoration: none;
            white-space: nowrap;
        }

        .brand__mark {
            display: grid;
            place-items: center;
            width: 2.25rem;
            height: 2.25rem;
            border-radius: .6rem;
            background: var(--brand);
            color: #fff;
            flex: 0 0 auto;
        }

        .brand__name { font-size: 1.02rem; letter-spacing: -.01em; }

        /*
           Tanpa menu hamburger. Pada layar sempit tautannya menggulung ke
           samping — konvensi yang sudah dipakai `layouts/portal.blade.php`,
           dan yang tidak menuntut satu baris JavaScript pun (butir 346).
        */
        .nav__links {
            display: flex;
            align-items: center;
            gap: .25rem;
            margin-left: auto;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
        }

        .nav__links::-webkit-scrollbar { display: none; }

        .nav__links a {
            display: inline-flex;
            align-items: center;
            /* Sasaran sentuh untuk jari, bukan kursor. */
            min-height: 2.75rem;
            padding: 0 .7rem;
            border-radius: .5rem;
            color: var(--muted);
            font-size: .925rem;
            font-weight: 500;
            text-decoration: none;
            white-space: nowrap;
        }

        .nav__links a:hover { color: var(--brand); background: var(--soft); }

        /* -------------------------------------------- pemilih bahasa (AUTH-05) */

        .locale-switch {
            display: inline-flex;
            align-items: center;
            gap: .125rem;
            margin: 0 0 0 .35rem;
            padding-left: .35rem;
            border-left: 1px solid var(--line);
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
            color: var(--muted);
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

        .locale-switch__item--active { background: var(--soft); color: var(--brand); }

        button.locale-switch__item:hover { color: var(--brand); background: var(--soft); }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .45rem;
            min-height: 2.75rem;
            padding: .6rem 1.15rem;
            border: 1px solid transparent;
            border-radius: .6rem;
            font: inherit;
            font-weight: 600;
            text-decoration: none;
            white-space: nowrap;
            cursor: pointer;
        }

        .btn--primary { background: var(--brand); color: #fff; }
        .btn--primary:hover { background: var(--brand-dark); }

        .btn--accent { background: var(--accent); color: #fff; }
        .btn--accent:hover { filter: brightness(.94); }

        .btn--ghost {
            background: var(--surface);
            color: var(--brand);
            border-color: var(--line);
        }

        .btn--ghost:hover { background: var(--soft); }

        .btn--wide { width: 100%; }

        /* -------------------------------------------------------------- hero */

        .hero {
            /* Gradien tipis, bukan hiasan yang menutupi teks. */
            background:
                radial-gradient(60rem 28rem at 78% -18%, rgba(224, 112, 32, .16), transparent 60%),
                linear-gradient(180deg, #fbfcfe 0%, var(--canvas) 100%);
            border-bottom: 1px solid var(--line);
        }

        .hero__inner { padding: 3.5rem 0 3.75rem; max-width: 46rem; }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            padding: .3rem .75rem;
            border-radius: 999px;
            background: var(--soft);
            border: 1px solid var(--line);
            color: var(--brand);
            font-size: .8rem;
            font-weight: 600;
        }

        .hero h1 {
            margin: 1rem 0 0;
            font-size: clamp(1.9rem, 5.2vw, 3.05rem);
            letter-spacing: -.02em;
        }

        .hero__lead {
            margin-top: 1rem;
            font-size: clamp(1rem, 2.4vw, 1.14rem);
            color: var(--muted);
        }

        .hero__cta {
            display: flex;
            flex-wrap: wrap;
            gap: .75rem;
            margin-top: 1.75rem;
        }

        .hero__note { margin-top: 1rem; font-size: .875rem; color: var(--muted); }

        /* ----------------------------------------------------------- section */

        .section { padding: 3.5rem 0; }

        .section--tint { background: var(--surface); border-block: 1px solid var(--line); }

        .section__head { max-width: 44rem; margin-bottom: 2rem; }

        .section__head h2 {
            font-size: clamp(1.45rem, 3.4vw, 2rem);
            letter-spacing: -.015em;
        }

        .section__head p { margin-top: .7rem; color: var(--muted); }

        /* Satu kolom lebih dulu; melebar hanya ketika layarnya memang cukup. */
        .grid {
            display: grid;
            gap: 1rem;
            grid-template-columns: 1fr;
        }

        @media (min-width: 40rem) { .grid--2 { grid-template-columns: repeat(2, 1fr); } }

        @media (min-width: 48rem) {
            .grid--3 { grid-template-columns: repeat(2, 1fr); }
            .grid--4 { grid-template-columns: repeat(2, 1fr); }
        }

        @media (min-width: 64rem) {
            .grid--3 { grid-template-columns: repeat(3, 1fr); }
            .grid--4 { grid-template-columns: repeat(4, 1fr); }
        }

        .card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: .9rem;
            padding: 1.35rem;
            box-shadow: 0 1px 2px rgba(20, 44, 82, .04);
        }

        .card h3 { font-size: 1.02rem; }

        .card p { margin-top: .45rem; color: var(--muted); font-size: .925rem; }

        .card__icon {
            display: grid;
            place-items: center;
            width: 2.5rem;
            height: 2.5rem;
            margin-bottom: .85rem;
            border-radius: .65rem;
            background: var(--soft);
            color: var(--brand);
        }

        /* Kartu akses peran: seluruh kartunya dapat diklik. */
        .access {
            display: flex;
            flex-direction: column;
            height: 100%;
            text-decoration: none;
            color: inherit;
            transition: border-color .15s ease, box-shadow .15s ease;
        }

        .access:hover {
            border-color: var(--brand);
            box-shadow: 0 6px 18px rgba(20, 44, 82, .08);
        }

        .access__go {
            margin-top: auto;
            padding-top: .9rem;
            color: var(--brand);
            font-weight: 600;
            font-size: .9rem;
        }

        .tag {
            display: inline-block;
            padding: .15rem .55rem;
            border-radius: 999px;
            background: var(--soft);
            border: 1px solid var(--line);
            color: var(--muted);
            font-size: .75rem;
            font-weight: 600;
            letter-spacing: .02em;
        }

        .muted { color: var(--muted); }

        .list { margin: 1rem 0 0; padding-left: 1.1rem; color: var(--muted); }

        .list li { margin-top: .4rem; }

        /* ---------------------------------------------------------- ajakan */

        .cta {
            background: linear-gradient(135deg, var(--brand) 0%, var(--brand-dark) 100%);
            color: #fff;
            border-radius: 1rem;
            padding: 2.25rem 1.5rem;
            text-align: center;
        }

        .cta h2 { font-size: clamp(1.3rem, 3.2vw, 1.75rem); }

        .cta p { margin-top: .6rem; color: rgba(255, 255, 255, .85); }

        .cta__buttons {
            display: flex;
            flex-wrap: wrap;
            gap: .75rem;
            justify-content: center;
            margin-top: 1.5rem;
        }

        .cta .btn--ghost { background: rgba(255, 255, 255, .12); color: #fff; border-color: rgba(255, 255, 255, .35); }
        .cta .btn--ghost:hover { background: rgba(255, 255, 255, .2); }

        /* ---------------------------------------------------------- footer */

        .footer {
            background: #10192a;
            color: #cfd6e4;
            padding: 2.5rem 0 1.5rem;
        }

        .footer a { color: #cfd6e4; text-decoration: none; }

        .footer a:hover { color: #fff; text-decoration: underline; }

        .footer__grid {
            display: grid;
            gap: 1.75rem;
            grid-template-columns: 1fr;
        }

        @media (min-width: 48rem) {
            .footer__grid { grid-template-columns: 1.4fr 1fr 1fr; }
        }

        .footer h3 {
            font-size: .8rem;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: #8e9bb3;
            margin-bottom: .75rem;
        }

        .footer ul { list-style: none; margin: 0; padding: 0; }

        .footer li + li { margin-top: .45rem; }

        .footer li a {
            display: inline-flex;
            align-items: center;
            min-height: 2.25rem;
            font-size: .9rem;
        }

        .footer__brand { display: inline-flex; align-items: center; gap: .6rem; color: #fff; font-weight: 700; }

        .footer__note { margin-top: .8rem; font-size: .9rem; color: #8e9bb3; max-width: 26rem; }

        .footer__bottom {
            margin-top: 2rem;
            padding-top: 1.25rem;
            border-top: 1px solid rgba(255, 255, 255, .1);
            font-size: .85rem;
            color: #8e9bb3;
        }

        /* Hanya untuk pembaca layar. */
        .sr-only {
            position: absolute;
            width: 1px; height: 1px;
            margin: -1px; padding: 0;
            overflow: hidden;
            clip: rect(0 0 0 0);
            white-space: nowrap;
            border: 0;
        }

        /* Lompat ke konten — muncul begitu difokuskan keyboard. */
        .skip {
            position: absolute;
            left: 1rem;
            top: -3rem;
            z-index: 30;
            background: var(--brand);
            color: #fff;
            padding: .6rem 1rem;
            border-radius: .5rem;
            transition: top .15s ease;
        }

        .skip:focus { top: 1rem; }
    </style>
</head>
<body>
    <a class="skip" href="#konten">{{ __('Lompat ke konten utama') }}</a>

    @yield('content')
</body>
</html>
