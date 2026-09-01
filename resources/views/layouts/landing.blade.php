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
            --muted: #4d576b;
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

            /*
               Token ruang. Batch L2.2 memisahkannya dari nilai yang tertulis di
               tempat pemakaian supaya irama seluler dapat disetel di satu
               tempat, bukan lewat puluhan penimpaan yang tidak saling tahu
               (butir 432).
            */
            --section-y: 4.5rem;
            --container-pad: 1.25rem;
            --card-pad: 1.5rem;
            --grid-gap: 1rem;
            --hero-y: 3.25rem;
            --head-gap: 2.5rem;
        }

        /*
           Irama seluler. Satu blok, bukan penimpaan yang tersebar: di bawah
           48rem seluruh halaman memakai jarak yang lebih rapat, dan desktop
           tidak tersentuh sama sekali karena nilainya hanya berlaku di dalam
           kueri ini (butir 432).
        */
        /* Tablet: di antara keduanya, bukan salinan desktop. */
        @media (min-width: 48rem) and (max-width: 63.99rem) {
            :root {
                --section-y: 3.5rem;
                --head-gap: 2rem;
            }
        }

        @media (max-width: 47.99rem) {
            :root {
                --section-y: 2.75rem;
                --container-pad: 1rem;
                --card-pad: 1.15rem;
                --grid-gap: .75rem;
                --hero-y: 1.75rem;
                --head-gap: 1.5rem;
            }
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
            padding: 0 var(--container-pad);
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
           Baris kedua wordmark disembunyikan di seluruh lebar ponsel, bukan
           hanya yang tersempit: pada 412px pun ia elemen terlebar di bar, dan
           nama sekolahnya yang penting — bukan tagline-nya. Footer tetap
           menampilkannya utuh (butir 421, disetel ulang pada butir 433).
        */
        @media (max-width: 47.99rem) {
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

        .hero__note { margin-top: 1.15rem; font-size: .95rem; color: var(--muted); }

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

        .section__head { max-width: 44rem; margin-bottom: var(--head-gap); }

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

        .section__head p { margin-top: .85rem; color: var(--muted); font-size: 1.06rem; line-height: 1.7; }

        /* Satu kolom lebih dulu; melebar hanya ketika layarnya memang cukup. */
        .grid {
            display: grid;
            gap: var(--grid-gap);
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
            padding: var(--card-pad);
            box-shadow: var(--shadow-sm);
        }

        .card h3 { font-size: 1.06rem; }

        .card p { margin-top: .55rem; color: var(--muted); font-size: .975rem; line-height: 1.7; }

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
        .pillar p { margin-top: .5rem; color: var(--muted); font-size: .975rem; line-height: 1.7; }

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
            font-size: .8rem;
            text-transform: uppercase;
            letter-spacing: .1em;
            color: #aab5c9;
            margin-bottom: .85rem;
        }

        .footer ul { list-style: none; margin: 0; padding: 0; }

        .footer li a {
            display: inline-flex;
            align-items: center;
            min-height: 2.25rem;
            font-size: .96rem;
        }

        .footer__brand { display: inline-flex; align-items: center; gap: .65rem; color: #fff; font-weight: 700; }
        .footer__brand .brand__tag { color: #93a1ba; }

        .footer__note { margin-top: .9rem; font-size: .96rem; line-height: 1.7; color: #c2cbdc; max-width: 24rem; }

        .footer__locale { margin-top: 1.25rem; }

        .footer .locale-switch { margin: 0; padding: 0; border: 0; }
        .footer .locale-switch__item { color: #cfd6e4; }
        .footer .locale-switch__item--active { background: rgba(255, 255, 255, .12); color: #fff; }
        .footer button.locale-switch__item:hover { background: rgba(255, 255, 255, .12); color: #fff; }

        .footer__bottom {
            margin-top: 2.5rem;
            padding-top: 1.35rem;
            border-top: 1px solid rgba(255, 255, 255, .1);
            font-size: .9rem;
            color: #aab5c9;
        }

        /* ------------------------------------------- navigasi seluler (L2.1) */

        /*
           Tinjauan tampilan manusia menyimpulkan strip yang menggulung ke
           samping "berjalan, tetapi tidak terlihat sebagai menu". Itu penilaian
           yang benar: menggulung mendatar adalah gerakan yang harus ditemukan
           sendiri, dan tidak ada satu pun tandanya di layar.

           Yang menggantikannya `<details>`/`<summary>` — tombol menu yang
           terlihat, dapat difokus dan ditekan papan ketik tanpa satu baris
           JavaScript, dan tetap bekerja bila skrip mati. Tombol **Masuk** dan
           wordmark sengaja tetap di bar: yang disembunyikan hanya tautan
           bagian, bukan aksi utama (butir 425).
        */
        .nav__disclosure { position: relative; margin-left: auto; }

        .nav__toggle {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            min-height: 2.75rem;
            padding: 0 .75rem;
            border: 1px solid var(--line);
            border-radius: var(--radius-sm);
            background: var(--surface);
            color: var(--ink-soft);
            font-size: .925rem;
            font-weight: 600;
            list-style: none;
            cursor: pointer;
            user-select: none;
        }

        /* Segitiga bawaan disembunyikan di kedua mesin render. */
        .nav__toggle::-webkit-details-marker { display: none; }
        .nav__toggle::marker { content: ""; }

        .nav__toggle:hover { border-color: var(--brand); color: var(--brand); }

        .nav__disclosure[open] .nav__toggle { border-color: var(--brand); color: var(--brand); }

        /* Ikon berganti bentuk saat terbuka — bukan hanya berputar warna. */
        .nav__toggle .nav__icon--close,
        .nav__disclosure[open] .nav__toggle .nav__icon--open { display: none; }
        .nav__disclosure[open] .nav__toggle .nav__icon--close { display: inline-flex; }

        .nav__panel {
            position: absolute;
            top: calc(100% + .55rem);
            right: 0;
            /* Tidak pernah melebihi lebar layar: `body` memotong tanpa tanda. */
            width: min(17rem, calc(100vw - 2.5rem));
            padding: .5rem;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            box-shadow: var(--shadow-lg);
            z-index: 25;
        }

        .nav__panel a {
            display: flex;
            align-items: center;
            min-height: 2.75rem;
            padding: 0 .75rem;
            border-radius: var(--radius-sm);
            color: var(--ink-soft);
            font-size: .975rem;
            font-weight: 500;
            text-decoration: none;
        }

        .nav__panel a:hover { background: var(--soft); color: var(--brand); }

        .nav__panel-locale {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: .5rem;
            margin-top: .35rem;
            padding: .5rem .75rem 0;
            border-top: 1px solid var(--line);
        }

        .nav__panel-locale span {
            font-size: .8rem;
            font-weight: 600;
            color: var(--muted);
        }

        .nav__panel-locale .locale-switch { margin: 0; padding: 0; border: 0; }

        /* Bawaannya seluler: strip desktop disembunyikan sampai ada ruang. */
        .nav__links { display: none; }

        @media (min-width: 48rem) {
            .nav__disclosure { display: none; }
            .nav__links { display: flex; }
        }


        .nav__cta--bar { margin-left: .4rem; }

        @media (min-width: 48rem) { .nav__cta--bar { display: none; } }

        /* ------------------------------------------- aksi kartu akses (L2.1) */

        /*
           Sebelumnya aksi setiap kartu hanya teks berwarna. Pada layar ponsel
           ia terbaca sebagai keterangan, bukan sebagai sesuatu yang ditekan.
           Sekarang berbentuk pil bergaris — jelas dapat ditekan, tetapi tetap
           ringan; menjadikan seluruh kartu satu tombol besar akan membuat empat
           blok berat yang justru saling berebut perhatian (butir 426).
        */
        .access__go {
            align-self: flex-start;
            padding: .5rem .9rem;
            border: 1px solid var(--line);
            border-radius: var(--radius-pill);
            background: var(--surface);
            font-size: .925rem;
        }

        .access:hover .access__go {
            border-color: var(--brand);
            background: var(--brand-tint);
        }

        .access--primary .access__go {
            background: var(--accent);
            border-color: var(--accent);
            color: #fff;
        }

        .access--primary:hover .access__go {
            background: var(--accent-dark);
            border-color: var(--accent-dark);
            color: #fff;
        }

        /* --------------------------------------------- bagian tentang (L2.1) */

        /*
           Tinjauan manusia: halaman ini memakai baris kartu putih bergaris
           tiga kali berturut-turut, dan yang ketiga terbaca sebagai pengulangan.
           Bagian Tentang karena itu berhenti menjadi kartu dan menjadi satu
           panel utuh dua kolom — teksnya di kiri, ketiga alasannya sebagai
           daftar bergaris tipis di kanan. Isinya sama persis; yang berubah
           bentuknya (butir 427).
        */
        .about {
            display: grid;
            gap: 2rem;
            grid-template-columns: 1fr;
            padding: 2rem 1.5rem;
            border-radius: var(--radius-lg);
            background: linear-gradient(160deg, var(--brand-tint) 0%, var(--surface) 68%);
            border: 1px solid var(--line);
        }

        @media (min-width: 62rem) {
            .about { grid-template-columns: 1fr 1.05fr; gap: 3rem; padding: 3rem; align-items: center; }
        }

        .about__copy h2 {
            font-size: clamp(1.55rem, 3.6vw, 2.15rem);
            letter-spacing: -.03em;
        }

        .about__copy p { margin-top: .9rem; color: var(--muted); font-size: 1.06rem; line-height: 1.7; }

        .about__list { list-style: none; margin: 0; padding: 0; }

        .about__item {
            display: flex;
            gap: 1rem;
            padding: 1.15rem 0;
            border-top: 1px solid var(--line);
        }

        .about__item:first-child { border-top: 0; padding-top: 0; }
        .about__item:last-child { padding-bottom: 0; }

        .about__num {
            display: grid;
            place-items: center;
            flex: 0 0 auto;
            width: 2.35rem;
            height: 2.35rem;
            border-radius: var(--radius-sm);
            background: var(--brand);
            color: #fff;
            font-size: .9rem;
            font-weight: 700;
        }

        .about__item h3 { font-size: 1.06rem; }
        .about__item p { margin-top: .4rem; color: var(--muted); font-size: .975rem; line-height: 1.7; }

        /* ------------------------------------------------- kartu cabang (L2.1) */

        .branch__name { margin-top: .75rem; font-size: 1.2rem; }

        .branch__address {
            display: flex;
            align-items: flex-start;
            gap: .5rem;
            margin-top: .6rem;
            color: var(--muted);
            font-size: .975rem;
            line-height: 1.65;
        }

        .branch__address svg { flex: 0 0 auto; margin-top: .2rem; }

        /* ------------------------------------------------ label hero (L2.1) */

        .mock__chips {
            display: flex;
            flex-wrap: wrap;
            gap: .35rem;
        }

        .mock__chip {
            padding: .25rem .6rem;
            border-radius: var(--radius-pill);
            background: var(--brand-tint);
            border: 1px solid #d5e0f2;
            color: var(--brand);
            font-size: .72rem;
            font-weight: 700;
            letter-spacing: .03em;
        }

        .mock__chip:nth-child(2n) { background: var(--accent-tint); border-color: #f6dcc6; color: var(--accent-dark); }

        /* -------------------------------------------------- hero seluler (L2.1) */

        /* Kedua tombol hero bertumpuk penuh di layar tersempit. */
        @media (max-width: 29.99rem) {
            .hero__cta { flex-direction: column; align-items: stretch; }
        }

        /* ==================================================== seluler (L2.2) */

        /*
           Tinjauan tangkapan layar manusia: halaman seluler "terasa kebesaran,
           terlalu panjang, dan bagian kepalanya sempit atau terpotong".
           Blok ini menjawab keempatnya, dan hanya berlaku di bawah 48rem —
           desktop tidak tersentuh (butir 432).
        */

        /* ----------------------------------------------- kepala yang muat */

        /*
           Cacat sungguhan, bukan sekadar sesak. Anggaran lebar di 360px:
           isi 320px, dikurangi dua jarak 1rem dan margin tombol = 281,6px.
           Wordmark satu baris ber-`white-space: nowrap` sendirian ±204px,
           tombol Menu ±82px, tombol Masuk ±70px — totalnya ±356px. Flex tidak
           dapat menyusutkan wordmark yang nowrap, jadi barisnya meluber dan
           `body { overflow-x: hidden }` memotongnya tanpa satu tanda pun.
           Itulah yang terlihat di tangkapan layar (butir 433).

           Perbaikannya bukan menyembunyikan, melainkan membuatnya muat:
           wordmark boleh membungkus, setiap anak flex boleh menyusut
           (`min-width: 0`), dan jaraknya dirapatkan.
        */
        @media (max-width: 47.99rem) {
            .nav__inner {
                gap: .5rem;
                min-height: 3.75rem;
            }

            /* Tanpa ini flex menolak menyusutkan anaknya di bawah min-content. */
            .nav__inner > * { min-width: 0; }

            /*
               Wordmark-lah yang menyusut, dan hanya ia. Kedua tombol
               `flex: 0 0 auto` supaya tidak pernah ikut terjepit — kalau
               keduanya boleh menyusut, yang mengecil justru sasaran sentuh.
            */
            .brand {
                flex: 1 1 auto;
                gap: .5rem;
                /* Boleh membungkus ke baris kedua; tidak pernah terpotong. */
                white-space: normal;
                min-width: 0;
            }

            .nav__disclosure { flex: 0 0 auto; }

            .brand__mark { width: 2.1rem; height: 2.1rem; }

            .brand__text { min-width: 0; line-height: 1.1; }

            .brand__name {
                font-size: .9rem;
                overflow-wrap: anywhere;
            }

            .nav__toggle {
                gap: .35rem;
                padding: 0 .6rem;
                font-size: .875rem;
                white-space: nowrap;
            }

            .nav__cta--bar {
                margin-left: .25rem;
                padding: .6rem .8rem;
                font-size: .9rem;
                flex: 0 0 auto;
            }

            /* Panel menu tidak pernah lebih lebar daripada layarnya. */
            .nav__panel { width: min(16rem, calc(100vw - 2rem)); }
        }

        /* ------------------------------------------------------------ hero */

        @media (max-width: 47.99rem) {
            .hero__inner {
                gap: 1.5rem;
                padding: var(--hero-y) 0 calc(var(--hero-y) + .75rem);
            }

            /*
               Judul lama terkunci di batas bawah clamp-nya (2rem) pada seluruh
               lebar ponsel, sehingga ia berukuran sama di 360px dan di 768px —
               teks berukuran desktop yang dibungkus ke kolom sempit, persis
               yang dikeluhkan tinjauan.
            */
            .hero h1 {
                margin-top: .85rem;
                font-size: clamp(1.75rem, 7vw, 2.4rem);
                letter-spacing: -.025em;
            }

            .hero__lead {
                margin-top: .8rem;
                font-size: 1rem;
                line-height: 1.65;
            }

            .hero__cta { margin-top: 1.25rem; gap: .6rem; }

            .hero__note { margin-top: .85rem; font-size: .875rem; }

            .eyebrow {
                max-width: 100%;
                padding: .3rem .7rem;
                font-size: .75rem;
                line-height: 1.35;
            }
        }

        /* ------------------------------------------------- kartu akses peran */

        /*
           Empat kartu tinggi berderet membuat halaman panjang tanpa menambah
           kejelasan. Yang dirapatkan jaraknya, bukan isinya: keempat peran,
           keempat tujuan, dan seluruh naskahnya tetap utuh.
        */
        @media (max-width: 47.99rem) {
            .access .card__icon {
                width: 2.25rem;
                height: 2.25rem;
                margin-bottom: .6rem;
                border-radius: var(--radius-sm);
            }

            .access .card__title { margin-top: .45rem; font-size: 1rem; }

            .access p { margin-top: .35rem; font-size: .925rem; line-height: 1.6; }

            .access__go { margin-top: .85rem; padding-top: 0; }
        }

        /* --------------------------------------------- kartu fitur (≤ 30rem) */

        /*
           Sumber panjang halaman terbesar: delapan kartu penuh dalam satu
           kolom. Di bawah 30rem bentuknya berubah menjadi baris padat —
           ikon di kiri, judul dan keterangan di kanan. Bukan kartu desktop yang
           dikecilkan, dan tidak satu kemampuan pun dihilangkan.

           Dua kolom sengaja tidak dipakai: pada 360px judul seperti
           "Academics & Digital Report Cards" akan pecah menjadi empat baris
           sempit. Keterbacaan menang atas kepadatan (butir 434).
        */
        @media (max-width: 30rem) {
            .grid--rows { gap: .5rem; }

            .grid--rows .card {
                display: grid;
                grid-template-columns: 2.25rem 1fr;
                gap: .8rem;
                align-items: start;
                padding: .9rem 1rem;
            }

            .grid--rows .card__icon {
                width: 2.25rem;
                height: 2.25rem;
                margin: 0;
                border-radius: var(--radius-sm);
            }

            .grid--rows .card h3 { font-size: .975rem; }

            .grid--rows .card p {
                margin-top: .25rem;
                font-size: .9rem;
                line-height: 1.55;
            }
        }

        /* ---------------------------------------------------------- tentang */

        @media (max-width: 47.99rem) {
            .about { gap: 1.25rem; padding: 1.25rem 1.15rem; }

            .about__copy p { margin-top: .7rem; font-size: 1rem; line-height: 1.65; }

            .about__item { gap: .8rem; padding: .9rem 0; min-width: 0; }

            .about__num { width: 2rem; height: 2rem; font-size: .85rem; }

            .about__item h3 { font-size: 1rem; }

            .about__item p { margin-top: .3rem; font-size: .925rem; line-height: 1.6; }
        }

        /* ------------------------------------------------------- ajakan PPDB */

        @media (max-width: 47.99rem) {
            .cta { padding: 1.75rem 1.15rem; }

            /* Lingkaran hiasan dikecilkan supaya tidak duduk di balik tombol. */
            .cta::after { inset: auto -5rem -7rem auto; width: 12rem; height: 12rem; }

            .cta h2 { margin-top: .8rem; font-size: clamp(1.3rem, 5.4vw, 1.7rem); }

            .cta p { margin-top: .65rem; font-size: .975rem; line-height: 1.65; }

            .cta__buttons { margin-top: 1.25rem; gap: .6rem; }
        }

        /* ------------------------------------------------------ kartu cabang */

        @media (max-width: 47.99rem) {
            .branch__name { margin-top: .55rem; font-size: 1.08rem; }

            .branch__address { margin-top: .45rem; font-size: .925rem; line-height: 1.55; }

            /* Nama sekolah yang panjang membungkus, tidak melebarkan kartunya. */
            .access .branch__name,
            .access p { overflow-wrap: anywhere; }
        }

        /* ------------------------------------------------------------ footer */

        /*
           Empat kelompok bertumpuk membuat footer seluler sangat tinggi. Dari
           26rem ke atas keempatnya menjadi dua kolom; tidak satu tautan pun
           dihapus untuk memendekkannya.
        */
        @media (min-width: 26rem) and (max-width: 47.99rem) {
            .footer__grid { grid-template-columns: 1fr 1fr; }
            .footer__grid > :first-child { grid-column: 1 / -1; }
        }

        @media (max-width: 47.99rem) {
            .footer { padding: 2rem 0 1.25rem; }
            .footer__grid { gap: 1.25rem; }
            .footer__note { margin-top: .7rem; font-size: .925rem; }
            .footer__locale { margin-top: .9rem; }
            .footer li a { min-height: 2.5rem; font-size: .925rem; }
            .footer__bottom { margin-top: 1.5rem; padding-top: 1rem; }
        }

        /* -------------------------------------------- tombol pada layar sempit */

        /*
           Tombol memakai `white-space: nowrap`, dan label Inggris terpanjang —
           "Check Admission Status" — akan mendorong barisnya melebihi lebar
           layar pada ponsel tersempit. Di bawah 30rem tombol ajakan melebar
           penuh dan boleh membungkus; tingginya tetap ≥ 2.75rem.
        */
        @media (max-width: 30rem) {
            .hero__cta .btn,
            .cta__buttons .btn {
                width: 100%;
                white-space: normal;
                text-align: center;
            }

            .cta__buttons { flex-direction: column; align-items: stretch; }

            .btn--lg { min-height: 2.9rem; padding: .7rem 1rem; font-size: .975rem; }
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
