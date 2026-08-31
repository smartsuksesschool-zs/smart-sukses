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
     * pun (butir 345).
     *
     * Batch L2 menata ulang gaya di berkas ini menjadi satu sistem kecil yang
     * konsisten — token warna, skala jarak, radius, bayangan, tipografi — supaya
     * halamannya tidak lagi merakit nilai satu per satu di tempat pemakaian
     * (butir 416).
     */
    use App\Support\SchoolBranding;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="@yield('description', 'Platform manajemen sekolah terintegrasi: PPDB, akademik, keuangan, ujian online, dan portal pengguna.')">
    <meta name="theme-color" content="{{ SchoolBranding::FALLBACK_PRIMARY }}">
    <title>@yield('title', config('app.name'))</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">

    <style>
        /* ============================================================ token */

        :root {
            /* Warna platform (SchoolBranding::FALLBACK_*), bukan warna cabang. */
            --brand: {{ SchoolBranding::FALLBACK_PRIMARY }};
            --accent: {{ SchoolBranding::FALLBACK_SECONDARY }};

            --brand-dark: #142c52;
            --brand-deep: #0d1e3a;
            --brand-tint: #eaf0fa;
            --accent-dark: #c25d16;
            --accent-tint: #fdf0e6;

            --ink: #16202f;
            --ink-soft: #33415a;
            --muted: #5b6577;
            --line: #e2e6ee;
            --line-soft: #edf0f6;
            --surface: #ffffff;
            --canvas: #f6f8fc;
            --soft: #eef2f9;

            --radius-sm: .5rem;
            --radius: .75rem;
            --radius-lg: 1.1rem;
            --radius-pill: 999px;

            --shadow-sm: 0 1px 2px rgba(13, 30, 58, .05);
            --shadow-md: 0 8px 24px -12px rgba(13, 30, 58, .22);
            --shadow-lg: 0 24px 56px -28px rgba(13, 30, 58, .38);

            --shell: 72rem;
            --section-y: 4.5rem;
        }

        *, *::before, *::after { box-sizing: border-box; }

        html {
            -webkit-text-size-adjust: 100%;
            scroll-behavior: smooth;
            /* Judul bagian tidak tersembunyi di balik navbar lengket. */
            scroll-padding-top: 5.5rem;
        }

        body {
            margin: 0;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background: var(--canvas);
            color: var(--ink);
            line-height: 1.65;
            -webkit-font-smoothing: antialiased;
            /* Tidak ada elemen yang boleh mendorong halaman melebar. */
            overflow-x: hidden;
        }

        img { max-width: 100%; height: auto; }

        h1, h2, h3, h4 { line-height: 1.2; margin: 0; letter-spacing: -.02em; }

        p { margin: 0; }

        a { color: var(--brand); }

        /* Fokus keyboard harus terlihat di seluruh halaman. */
        a:focus-visible,
        button:focus-visible,
        summary:focus-visible {
            outline: 3px solid var(--accent);
            outline-offset: 3px;
            border-radius: var(--radius-sm);
        }

        .shell {
            width: 100%;
            max-width: var(--shell);
            margin: 0 auto;
            padding: 0 1.25rem;
        }

        /* Gerak hanya sebagai bumbu, dan hanya bagi yang memintanya. */
        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }

            *, *::before, *::after {
                animation-duration: .001ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: .001ms !important;
            }
        }

        /* =========================================================== navbar */

        .nav {
            position: sticky;
            top: 0;
            z-index: 20;
            background: rgba(255, 255, 255, .88);
            border-bottom: 1px solid var(--line);
            backdrop-filter: saturate(160%) blur(10px);
        }

        .nav__inner {
            display: flex;
            align-items: center;
            gap: 1rem;
            min-height: 4.25rem;
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
            width: 2.4rem;
            height: 2.4rem;
            border-radius: var(--radius);
            background: linear-gradient(140deg, var(--brand) 0%, var(--brand-deep) 100%);
            color: #fff;
            box-shadow: var(--shadow-sm);
            flex: 0 0 auto;
        }

        .brand__text { display: flex; flex-direction: column; line-height: 1.15; }
        .brand__name { font-size: 1.02rem; letter-spacing: -.02em; }

        .brand__tag {
            font-size: .66rem;
            font-weight: 600;
            letter-spacing: .11em;
            text-transform: uppercase;
            color: var(--muted);
        }

        /*
           Pada layar 360px, baris kedua wordmark inilah elemen terlebar di
           navbar; ia memakan ruang yang dibutuhkan strip tautan yang menggulung
           di sebelahnya. Di bawah 30rem namanya saja sudah cukup — footer tetap
           menampilkannya utuh (butir 421).
        */
        @media (max-width: 29.99rem) {
            .nav .brand__tag { display: none; }
        }

        /*
           Tanpa menu hamburger. Pada layar sempit tautannya menggulung ke
           samping — konvensi yang sudah dipakai `layouts/portal.blade.php`,
           dan yang tidak menuntut satu baris JavaScript pun (butir 346).
        */
        .nav__links {
            display: flex;
            align-items: center;
            gap: .15rem;
            margin-left: auto;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
            /*
               Wajib. `.nav__links` adalah flex item, dan flex item punya
               `min-width: auto` — ia menolak menyusut di bawah lebar
               min-content-nya. Tanpa baris ini `overflow-x: auto` di atas tidak
               pernah aktif: isinya meluber keluar `.nav__inner`, lalu
               `body { overflow-x: hidden }` memotongnya. Tombol Masuk dan
               pemilih bahasa duduk paling kanan, jadi justru keduanya yang
               hilang dan tidak dapat dicapai pada layar ponsel (butir 390).
            */
            min-width: 0;
        }

        .nav__links::-webkit-scrollbar { display: none; }

        .nav__link {
            display: inline-flex;
            align-items: center;
            /* Sasaran sentuh untuk jari, bukan kursor. */
            min-height: 2.75rem;
            padding: 0 .7rem;
            border-radius: var(--radius-sm);
            color: var(--muted);
            font-size: .925rem;
            font-weight: 500;
            text-decoration: none;
            white-space: nowrap;
            transition: color .15s ease, background-color .15s ease;
        }

        .nav__link:hover { color: var(--brand); background: var(--soft); }

        .nav__cta { margin-left: .4rem; }

        /* ------------------------------------------ pemilih bahasa (AUTH-05) */

        .locale-switch {
            display: inline-flex;
            align-items: center;
            gap: .125rem;
            margin: 0 0 0 .4rem;
            padding-left: .4rem;
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
            border-radius: var(--radius-sm);
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

        .locale-switch__item--active { background: var(--brand-tint); color: var(--brand); }

        button.locale-switch__item:hover { color: var(--brand); background: var(--soft); }

        /* =========================================================== tombol */

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .5rem;
            min-height: 2.75rem;
            padding: .65rem 1.25rem;
            border: 1px solid transparent;
            border-radius: var(--radius);
            font: inherit;
            font-weight: 600;
            text-decoration: none;
            white-space: nowrap;
            cursor: pointer;
            transition: background-color .15s ease, border-color .15s ease,
                        transform .15s ease, box-shadow .15s ease;
        }

        .btn:hover { transform: translateY(-1px); }
        .btn:active { transform: translateY(0); }

        .btn--primary {
            background: var(--brand);
            color: #fff;
            box-shadow: var(--shadow-md);
        }

        .btn--primary:hover { background: var(--brand-dark); }

        .btn--accent {
            background: var(--accent);
            color: #fff;
            box-shadow: var(--shadow-md);
        }

        .btn--accent:hover { background: var(--accent-dark); }

        .btn--ghost {
            background: var(--surface);
            color: var(--brand);
            border-color: var(--line);
        }

        .btn--ghost:hover { background: var(--soft); border-color: var(--brand); }

        .btn--lg { min-height: 3.15rem; padding: .8rem 1.6rem; font-size: 1.02rem; }

        .btn--wide { width: 100%; }

        /* ============================================================= hero */

        .hero {
            position: relative;
            overflow: hidden;
            background:
                radial-gradient(48rem 26rem at 88% -10%, rgba(224, 112, 32, .18), transparent 62%),
                radial-gradient(52rem 30rem at 4% 8%, rgba(27, 58, 107, .10), transparent 60%),
                linear-gradient(180deg, #fcfdff 0%, var(--canvas) 100%);
            border-bottom: 1px solid var(--line);
        }

        .hero__inner {
            display: grid;
            gap: 2.75rem;
            grid-template-columns: 1fr;
            align-items: center;
            padding: 3.25rem 0 3.75rem;
        }

        @media (min-width: 64rem) {
            .hero__inner {
                grid-template-columns: 1.05fr .95fr;
                gap: 3.5rem;
                padding: 5rem 0 5.5rem;
            }
        }

        .hero__copy { max-width: 36rem; }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            padding: .35rem .8rem;
            border-radius: var(--radius-pill);
            background: var(--surface);
            border: 1px solid var(--line);
            box-shadow: var(--shadow-sm);
            color: var(--brand);
            font-size: .8rem;
            font-weight: 600;
        }

        .eyebrow__dot {
            width: .45rem;
            height: .45rem;
            border-radius: 50%;
            background: var(--accent);
            flex: 0 0 auto;
        }

        .hero h1 {
            margin: 1.1rem 0 0;
            font-size: clamp(2rem, 5.4vw, 3.35rem);
            letter-spacing: -.035em;
        }

        .hero h1 .accentuate {
            /* Warna, bukan satu-satunya penanda: kalimatnya tetap utuh dibaca. */
            color: var(--brand);
        }

        .hero__lead {
            margin-top: 1.15rem;
            font-size: clamp(1.02rem, 2.3vw, 1.16rem);
            color: var(--muted);
            max-width: 34rem;
        }

        .hero__cta {
            display: flex;
            flex-wrap: wrap;
            gap: .75rem;
            margin-top: 2rem;
        }

        .hero__note { margin-top: 1.1rem; font-size: .9rem; color: var(--muted); }

        /* ------------------------------------- gubahan visual hero (hiasan) */

        /*
           Tidak ada satu berkas gambar pun di repository ini — tidak ada logo
           resmi, tidak ada foto sekolah, dan tidak ada foto stok yang boleh
           diunduh. Gubahan di bawah karena itu dibangun dari HTML dan CSS:
           ia menggambarkan bentuk antarmuka yang benar-benar ada di produk
           (jadwal, nilai, tagihan) tanpa mengarang satu angka pun yang
           mengaku data sungguhan (butir 417).
        */
        .hero__visual {
            position: relative;
            display: none;
        }

        @media (min-width: 48rem) {
            .hero__visual { display: block; }
        }

        .mock {
            position: relative;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
        }

        .mock__bar {
            display: flex;
            align-items: center;
            gap: .4rem;
            padding: .7rem .9rem;
            background: var(--soft);
            border-bottom: 1px solid var(--line);
        }

        .mock__dot { width: .5rem; height: .5rem; border-radius: 50%; background: #c9d1e0; }

        .mock__title {
            margin-left: .4rem;
            font-size: .72rem;
            font-weight: 600;
            letter-spacing: .04em;
            color: var(--muted);
            text-transform: uppercase;
        }

        .mock__body { padding: 1.1rem; display: grid; gap: .85rem; }

        .mock__tiles { display: grid; grid-template-columns: repeat(3, 1fr); gap: .6rem; }

        .mock__tile {
            padding: .7rem .75rem;
            border-radius: var(--radius);
            background: var(--brand-tint);
            border: 1px solid var(--line-soft);
        }

        .mock__tile:nth-child(2) { background: var(--accent-tint); }
        .mock__tile:nth-child(3) { background: var(--soft); }

        .mock__cap {
            height: .4rem;
            border-radius: var(--radius-pill);
            background: rgba(27, 58, 107, .22);
        }

        .mock__cap + .mock__cap { margin-top: .45rem; width: 62%; background: rgba(27, 58, 107, .12); }

        .mock__rows { display: grid; gap: .5rem; }

        .mock__row {
            display: flex;
            align-items: center;
            gap: .65rem;
            padding: .6rem .7rem;
            border: 1px solid var(--line-soft);
            border-radius: var(--radius);
        }

        .mock__pill {
            flex: 0 0 auto;
            width: 2rem;
            height: 1.35rem;
            border-radius: var(--radius-sm);
            background: var(--brand);
            opacity: .16;
        }

        .mock__row:nth-child(2) .mock__pill { background: var(--accent); opacity: .22; }

        .mock__line { flex: 1; height: .42rem; border-radius: var(--radius-pill); background: rgba(27, 58, 107, .16); }
        .mock__line--short { flex: 0 0 22%; background: rgba(27, 58, 107, .09); }

        .mock__chart {
            display: flex;
            align-items: flex-end;
            gap: .4rem;
            height: 4.25rem;
            padding: .75rem;
            border: 1px solid var(--line-soft);
            border-radius: var(--radius);
        }

        .mock__bar-item {
            flex: 1;
            border-radius: .3rem .3rem 0 0;
            background: linear-gradient(180deg, var(--brand) 0%, rgba(27, 58, 107, .45) 100%);
        }

        /* Kartu kecil yang mengambang di tepi gubahan. */
        .float {
            position: absolute;
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            padding: .55rem .8rem;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            box-shadow: var(--shadow-md);
            font-size: .78rem;
            font-weight: 600;
            color: var(--ink-soft);
            white-space: nowrap;
        }

        .float__dot {
            display: grid;
            place-items: center;
            width: 1.4rem;
            height: 1.4rem;
            border-radius: var(--radius-sm);
            background: var(--brand-tint);
            color: var(--brand);
            flex: 0 0 auto;
        }

        /*
           Menjorok hanya ke atas dan ke bawah, tidak pernah ke samping.
           `body { overflow-x: hidden }` memotong apa pun yang melewati tepi
           mendatar tanpa satu tanda pun bahwa ia terpotong (butir 421).
        */
        .float--a { top: -1.1rem; left: 1rem; animation: drift 7s ease-in-out infinite; }
        .float--b { bottom: -1.1rem; right: 1rem; animation: drift 9s ease-in-out infinite reverse; }
        .float--b .float__dot { background: var(--accent-tint); color: var(--accent-dark); }

        @keyframes drift {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-6px); }
        }

        /* ========================================================== section */

        .section { padding: var(--section-y) 0; }

        .section--tint { background: var(--surface); border-block: 1px solid var(--line); }

        .section__head { max-width: 44rem; margin-bottom: 2.5rem; }

        .section__kicker {
            display: block;
            margin-bottom: .6rem;
            font-size: .78rem;
            font-weight: 700;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: var(--accent-dark);
        }

        .section__head h2 {
            font-size: clamp(1.55rem, 3.6vw, 2.15rem);
            letter-spacing: -.03em;
        }

        .section__head p { margin-top: .8rem; color: var(--muted); font-size: 1.02rem; }

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

        /* ============================================================ kartu */

        .card {
            position: relative;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
        }

        .card h3 { font-size: 1.06rem; }

        .card p { margin-top: .5rem; color: var(--muted); font-size: .93rem; }

        .card__icon {
            display: grid;
            place-items: center;
            width: 2.75rem;
            height: 2.75rem;
            margin-bottom: 1rem;
            border-radius: var(--radius);
            background: var(--brand-tint);
            color: var(--brand);
        }

        .card--accent .card__icon { background: var(--accent-tint); color: var(--accent-dark); }

        /* Kartu yang seluruhnya dapat diklik — tetap `<a>`, bukan div ber-onclick. */
        .access {
            display: flex;
            flex-direction: column;
            height: 100%;
            text-decoration: none;
            color: inherit;
            transition: border-color .15s ease, box-shadow .15s ease, transform .15s ease;
        }

        .access:hover {
            border-color: var(--brand);
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
        }

        .access__go {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            margin-top: auto;
            padding-top: 1rem;
            color: var(--brand);
            font-weight: 600;
            font-size: .9rem;
        }

        .access:hover .access__go { color: var(--brand-dark); }

        .access__arrow { transition: transform .15s ease; }
        .access:hover .access__arrow { transform: translateX(3px); }

        .tag {
            display: inline-block;
            padding: .2rem .6rem;
            border-radius: var(--radius-pill);
            background: var(--soft);
            border: 1px solid var(--line);
            color: var(--muted);
            font-size: .72rem;
            font-weight: 700;
            letter-spacing: .05em;
            text-transform: uppercase;
        }

        .tag--brand { background: var(--brand-tint); border-color: #d5e0f2; color: var(--brand); }
        .tag--accent { background: var(--accent-tint); border-color: #f6dcc6; color: var(--accent-dark); }

        .card__title { margin-top: .7rem; }

        .muted { color: var(--muted); }

        /* --------------------------------------------- pita nilai (tentang) */

        .pillars { display: grid; gap: 1rem; grid-template-columns: 1fr; }

        @media (min-width: 48rem) { .pillars { grid-template-columns: repeat(3, 1fr); } }

        .pillar {
            padding: 1.4rem 1.5rem;
            border-radius: var(--radius-lg);
            background: var(--surface);
            border: 1px solid var(--line);
            box-shadow: var(--shadow-sm);
        }

        .pillar__num {
            display: inline-grid;
            place-items: center;
            width: 2.1rem;
            height: 2.1rem;
            border-radius: var(--radius-sm);
            background: var(--brand);
            color: #fff;
            font-size: .85rem;
            font-weight: 700;
            margin-bottom: .9rem;
        }

        .pillar h3 { font-size: 1.02rem; }
        .pillar p { margin-top: .45rem; color: var(--muted); font-size: .93rem; }

        /* ======================================================== ajakan PPDB */

        .cta {
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg, var(--brand) 0%, var(--brand-deep) 100%);
            color: #fff;
            border-radius: var(--radius-lg);
            padding: 2.75rem 1.5rem;
            text-align: center;
        }

        .cta::after {
            content: "";
            position: absolute;
            inset: auto -6rem -9rem auto;
            width: 20rem;
            height: 20rem;
            border-radius: 50%;
            background: rgba(224, 112, 32, .22);
            pointer-events: none;
        }

        .cta > * { position: relative; }

        @media (min-width: 48rem) { .cta { padding: 3.25rem 3rem; } }

        .cta .eyebrow {
            background: rgba(255, 255, 255, .14);
            border-color: rgba(255, 255, 255, .3);
            color: #fff;
            box-shadow: none;
        }

        .cta h2 { margin-top: 1rem; font-size: clamp(1.45rem, 3.4vw, 2rem); }

        .cta p { margin: .8rem auto 0; color: rgba(255, 255, 255, .86); max-width: 38rem; }

        .cta__buttons {
            display: flex;
            flex-wrap: wrap;
            gap: .75rem;
            justify-content: center;
            margin-top: 1.75rem;
        }

        .cta .btn--ghost {
            background: rgba(255, 255, 255, .12);
            color: #fff;
            border-color: rgba(255, 255, 255, .38);
            box-shadow: none;
        }

        .cta .btn--ghost:hover { background: rgba(255, 255, 255, .22); border-color: #fff; }

        /* =========================================================== footer */

        .footer {
            background: var(--brand-deep);
            color: #cfd6e4;
            padding: 3rem 0 1.5rem;
        }

        .footer a { color: #cfd6e4; text-decoration: none; }

        .footer a:hover { color: #fff; text-decoration: underline; }

        .footer__grid {
            display: grid;
            gap: 2rem;
            grid-template-columns: 1fr;
        }

        @media (min-width: 48rem) {
            .footer__grid { grid-template-columns: 1.5fr 1fr 1fr 1fr; }
        }

        .footer h3 {
            font-size: .76rem;
            text-transform: uppercase;
            letter-spacing: .1em;
            color: #93a1ba;
            margin-bottom: .85rem;
        }

        .footer ul { list-style: none; margin: 0; padding: 0; }

        .footer li a {
            display: inline-flex;
            align-items: center;
            min-height: 2.25rem;
            font-size: .92rem;
        }

        .footer__brand { display: inline-flex; align-items: center; gap: .65rem; color: #fff; font-weight: 700; }
        .footer__brand .brand__tag { color: #93a1ba; }

        .footer__note { margin-top: .9rem; font-size: .92rem; color: #93a1ba; max-width: 24rem; }

        .footer__locale { margin-top: 1.25rem; }

        .footer .locale-switch { margin: 0; padding: 0; border: 0; }
        .footer .locale-switch__item { color: #cfd6e4; }
        .footer .locale-switch__item--active { background: rgba(255, 255, 255, .12); color: #fff; }
        .footer button.locale-switch__item:hover { background: rgba(255, 255, 255, .12); color: #fff; }

        .footer__bottom {
            margin-top: 2.5rem;
            padding-top: 1.35rem;
            border-top: 1px solid rgba(255, 255, 255, .1);
            font-size: .85rem;
            color: #93a1ba;
        }

        /* ========================================================== utilitas */

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
            top: -4rem;
            z-index: 30;
            background: var(--brand);
            color: #fff;
            padding: .7rem 1.15rem;
            border-radius: var(--radius-sm);
            font-weight: 600;
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
