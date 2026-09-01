{{--
    Halaman muka publik Smart Sukses School.

    Tambahan langsung atas permintaan pemilik; tidak ada di blueprint Phase 1
    (docs/owner-scope-changes.md bagian A).

    Seluruh teks di sini **naskah implementasi**, bukan naskah pemilik: Pak Akbar
    belum menyerahkan teks pemasaran. Karena itu tidak ada satu pun angka,
    testimoni, penghargaan, atau nama mitra — dan tidak ada nomor telepon, surel,
    maupun alamat yang dikarang. Yang disebut hanya kemampuan yang benar-benar
    sudah berjalan (butir 347).

    Batch L2 menata ulang urutan dan tampilannya. Yang berubah paling menentukan:
    bagian "Akses Pengguna" naik ke tepat bawah hero, sehingga pengunjung tidak
    perlu menggulung untuk menemukan pintu masuknya (butir 418).
--}}
@extends('layouts.landing')

@section('title', config('app.name').' — '.__('Platform Manajemen Sekolah Terintegrasi'))

@section('description', 'Smart Sukses School: platform manajemen sekolah terintegrasi untuk PPDB, data siswa, akademik dan e-rapor, keuangan, ujian online, notifikasi, serta portal siswa dan orang tua.')

@php
    /**
     * Ikon garis, satu bentuk per maksud.
     *
     * Sebelum batch ini kedelapan kartu fitur memakai satu ikon centang yang
     * sama, sehingga ikonnya tidak membedakan apa pun dan hanya menjadi hiasan
     * berulang. Bentuknya kini berbeda per fitur, dan seluruhnya
     * `aria-hidden` — makna tetap dibawa judul dan teksnya (butir 419).
     *
     * @var array<string, string>
     */
    $icons = [
        'ppdb' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="M9 15h6"/><path d="M9 11h2"/>',
        'siswa' => '<path d="M22 10 12 5 2 10l10 5 10-5Z"/><path d="M6 12v5c0 1 2.7 2.5 6 2.5s6-1.5 6-2.5v-5"/>',
        'nilai' => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/><path d="m10 8 2 2 4-4"/>',
        'keuangan' => '<rect x="2" y="6" width="20" height="13" rx="2"/><circle cx="12" cy="12.5" r="2.5"/><path d="M6 10v5M18 10v5"/>',
        'ujian' => '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/><path d="m9 8 2 2 4-4"/>',
        'notifikasi' => '<path d="M18 8a6 6 0 1 0-12 0c0 7-3 8-3 8h18s-3-1-3-8"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
        'portal-siswa' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'portal-ortu' => '<circle cx="9" cy="8" r="3"/><circle cx="17" cy="10" r="2.5"/><path d="M3 20a6 6 0 0 1 12 0"/><path d="M15.5 20a4.5 4.5 0 0 1 5.5-4.4"/>',
        'guru' => '<rect x="3" y="4" width="18" height="14" rx="2"/><path d="M7 20h10"/><path d="M8 9h6M8 13h4"/>',
        'cabang' => '<path d="M3 21h18"/><path d="M5 21V8l7-5 7 5v13"/><path d="M10 21v-5h4v5"/>',
        'kalender' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 11h18"/>',
        'perisai' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/>',
        'panah' => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'tutup' => '<path d="M6 6l12 12M18 6 6 18"/>',
        'pin' => '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
    ];

    /** Satu pembungkus SVG untuk seluruh ikon, supaya ukurannya konsisten. */
    $icon = fn (string $name, int $size = 20): string => sprintf(
        '<svg width="%1$d" height="%1$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
        .'stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false" '
        .'aria-hidden="true">%2$s</svg>',
        $size,
        $icons[$name] ?? '',
    );
@endphp

@section('content')
    {{-- ==================================================== navbar ==== --}}
    <header class="nav">
        <div class="shell nav__inner">
            <a href="{{ route('landing') }}" class="brand">
                <span class="brand__mark" aria-hidden="true">
                    {{-- Ikon hiasan, bukan logo resmi: tidak ada berkas logo
                         Smart Sukses School di repository, dan tidak ada yang
                         dikarang (butir 348). --}}
                    {!! $icon('siswa', 20) !!}
                </span>
                <span class="brand__text">
                    <span class="brand__name">{{ config('app.name') }}</span>
                    <span class="brand__tag">{{ __('Sistem Informasi Sekolah') }}</span>
                </span>
            </a>

            {{-- Strip lengkap; muncul hanya ketika layarnya memang cukup lebar. --}}
            <nav class="nav__links" aria-label="{{ __('Navigasi utama') }}">
                <a class="nav__link" href="#konten">{{ __('Beranda') }}</a>
                <a class="nav__link" href="#akses">{{ __('Akses Pengguna') }}</a>
                <a class="nav__link" href="#fitur">{{ __('Fitur') }}</a>
                <a class="nav__link" href="#tentang">{{ __('Tentang') }}</a>
                <a class="nav__link" href="#cabang">{{ __('Cabang') }}</a>
                <a class="nav__link" href="{{ route('ppdb.schools') }}">{{ __('PPDB') }}</a>
                <a href="#akses" class="btn btn--primary nav__cta">{{ __('Masuk') }}</a>
                <x-locale-switch />
            </nav>

            {{-- Menu seluler. `<details>` — dapat dibuka papan ketik, bekerja
                 tanpa JavaScript, dan tetap terbuka bila skrip mati (butir 425). --}}
            <details class="nav__disclosure">
                <summary class="nav__toggle" aria-label="{{ __('Buka menu navigasi') }}">
                    <span class="nav__icon nav__icon--open">{!! $icon('menu', 18) !!}</span>
                    <span class="nav__icon nav__icon--close">{!! $icon('tutup', 18) !!}</span>
                    {{ __('Menu') }}
                </summary>

                <nav class="nav__panel" aria-label="{{ __('Navigasi utama') }}">
                    <a href="#konten">{{ __('Beranda') }}</a>
                    <a href="#akses">{{ __('Akses Pengguna') }}</a>
                    <a href="#fitur">{{ __('Fitur') }}</a>
                    <a href="#tentang">{{ __('Tentang') }}</a>
                    <a href="#cabang">{{ __('Cabang') }}</a>
                    <a href="{{ route('ppdb.schools') }}">{{ __('PPDB') }}</a>
                    <a href="{{ route('ppdb.check-status') }}">{{ __('Cek Status PPDB') }}</a>

                    <div class="nav__panel-locale">
                        <span>{{ __('Bahasa / Language') }}</span>
                        <x-locale-switch />
                    </div>
                </nav>
            </details>

            {{-- Aksi utama tidak pernah ikut disembunyikan di balik menu. --}}
            <a href="#akses" class="btn btn--primary nav__cta--bar">{{ __('Masuk') }}</a>
        </div>
    </header>

    <main id="konten">
        {{-- ===================================================== hero ==== --}}
        <section class="hero">
            <div class="shell hero__inner">
                <div class="hero__copy">
                    <span class="eyebrow">
                        <span class="eyebrow__dot" aria-hidden="true"></span>
                        {{ __('Platform Manajemen Sekolah Terintegrasi') }}
                    </span>

                    {{-- Satu <h1> di seluruh halaman. Dua bagian kalimat, dua
                         kunci terjemahan — bukan satu kalimat ber-HTML yang
                         harus diterjemahkan berikut markupnya. --}}
                    <h1>
                        <span class="accentuate">{{ __('Belajar, mengelola, dan terhubung') }}</span>
                        {{ __('dalam satu sistem sekolah') }}
                    </h1>

                    <p class="hero__lead">
                        {{ __('Smart Sukses School menyatukan penerimaan siswa baru, data akademik, keuangan sekolah, ujian online, dan komunikasi dengan orang tua ke dalam satu sistem yang dapat diakses dari mana saja.') }}
                    </p>

                    <div class="hero__cta">
                        <a href="{{ route('ppdb.schools') }}" class="btn btn--accent btn--lg">{{ __('Daftar PPDB') }}</a>
                        {{-- Tidak ada satu halaman masuk untuk semua peran, jadi tombol
                             ini mengarah ke bagian akses — bukan berpura-pura ada
                             (butir 349). --}}
                        <a href="#akses" class="btn btn--ghost btn--lg">{{ __('Masuk ke Sistem') }}</a>
                    </div>

                    <p class="hero__note">
                        {{ __('Setiap peran memiliki halaman masuknya sendiri — pilih pada bagian') }}
                        <a href="#akses">{{ __('Akses Pengguna') }}</a>.
                    </p>
                </div>

                {{-- Gubahan hiasan sepenuhnya: tidak ada satu angka pun yang
                     mengaku data sungguhan, dan seluruhnya disembunyikan dari
                     pembaca layar. --}}
                <div class="hero__visual" aria-hidden="true">
                    <div class="mock">
                        <div class="mock__bar">
                            <span class="mock__dot"></span>
                            <span class="mock__dot"></span>
                            <span class="mock__dot"></span>
                            <span class="mock__title">{{ __('Portal Sekolah') }}</span>
                        </div>

                        <div class="mock__body">
                            {{-- Label produk yang benar-benar ada, tanpa satu
                                 angka pun (butir 428). --}}
                            <div class="mock__chips">
                                <span class="mock__chip">{{ __('PPDB') }}</span>
                                <span class="mock__chip">{{ __('Jadwal') }}</span>
                                <span class="mock__chip">{{ __('Nilai') }}</span>
                                <span class="mock__chip">{{ __('Ujian') }}</span>
                                <span class="mock__chip">{{ __('Portal') }}</span>
                            </div>

                            <div class="mock__tiles">
                                <div class="mock__tile"><span class="mock__cap"></span><span class="mock__cap"></span></div>
                                <div class="mock__tile"><span class="mock__cap"></span><span class="mock__cap"></span></div>
                                <div class="mock__tile"><span class="mock__cap"></span><span class="mock__cap"></span></div>
                            </div>

                            <div class="mock__rows">
                                <div class="mock__row">
                                    <span class="mock__pill"></span>
                                    <span class="mock__line"></span>
                                    <span class="mock__line mock__line--short"></span>
                                </div>
                                <div class="mock__row">
                                    <span class="mock__pill"></span>
                                    <span class="mock__line"></span>
                                    <span class="mock__line mock__line--short"></span>
                                </div>
                                <div class="mock__row">
                                    <span class="mock__pill"></span>
                                    <span class="mock__line"></span>
                                    <span class="mock__line mock__line--short"></span>
                                </div>
                            </div>

                            <div class="mock__chart">
                                <span class="mock__bar-item" style="height:42%"></span>
                                <span class="mock__bar-item" style="height:68%"></span>
                                <span class="mock__bar-item" style="height:55%"></span>
                                <span class="mock__bar-item" style="height:82%"></span>
                                <span class="mock__bar-item" style="height:61%"></span>
                                <span class="mock__bar-item" style="height:74%"></span>
                            </div>
                        </div>
                    </div>

                    <span class="float float--a">
                        <span class="float__dot">{!! $icon('kalender', 14) !!}</span>
                        {{ __('Jadwal & Nilai') }}
                    </span>

                    <span class="float float--b">
                        <span class="float__dot">{!! $icon('perisai', 14) !!}</span>
                        {{ __('Data per cabang terpisah') }}
                    </span>
                </div>
            </div>
        </section>

        {{-- ============================================ akses pengguna ==== --}}
        {{-- Sengaja bagian pertama setelah hero: pertanyaan "saya masuk lewat
             mana" tidak boleh menuntut gulungan (butir 418). --}}
        <section class="section section--tint" id="akses">
            <div class="shell">
                <div class="section__head">
                    <span class="section__kicker">{{ __('Pintu Masuk') }}</span>
                    <h2>{{ __('Akses Pengguna') }}</h2>
                    <p>
                        {{ __('Setiap peran masuk lewat halamannya sendiri. Pilih yang sesuai dengan Anda.') }}
                    </p>
                </div>

                <div class="grid grid--4">
                    <a class="card card--accent access access--primary" href="{{ route('ppdb.schools') }}">
                        <span class="card__icon">{!! $icon('ppdb') !!}</span>
                        <span class="tag tag--accent">{{ __('Calon Siswa') }}</span>
                        <h3 class="card__title">{{ __('Pendaftaran (PPDB)') }}</h3>
                        <p>
                            {{ __('Mendaftar sebagai siswa baru tanpa perlu akun, lalu memeriksa status pendaftaran kapan saja.') }}
                        </p>
                        <span class="access__go">
                            {{ __('Buka pendaftaran') }}
                            <span class="access__arrow" aria-hidden="true">{!! $icon('panah', 16) !!}</span>
                        </span>
                        <span class="sr-only">{{ __('Buka halaman pendaftaran PPDB') }}</span>
                    </a>

                    <a class="card access" href="{{ route('student.login') }}">
                        <span class="card__icon">{!! $icon('portal-siswa') !!}</span>
                        <span class="tag tag--brand">{{ __('Siswa') }}</span>
                        <h3 class="card__title">{{ __('Portal Siswa') }}</h3>
                        <p>
                            {{ __('Melihat jadwal, nilai, dan rapor, serta mengerjakan ujian online yang sedang dibuka.') }}
                        </p>
                        <span class="access__go">
                            {{ __('Masuk portal siswa') }}
                            <span class="access__arrow" aria-hidden="true">{!! $icon('panah', 16) !!}</span>
                        </span>
                        <span class="sr-only">{{ __('Masuk ke portal siswa') }}</span>
                    </a>

                    <a class="card access" href="{{ route('portal.login') }}">
                        <span class="card__icon">{!! $icon('portal-ortu') !!}</span>
                        <span class="tag tag--brand">{{ __('Orang Tua') }}</span>
                        <h3 class="card__title">{{ __('Portal Orang Tua') }}</h3>
                        <p>
                            {{ __('Memantau nilai, tagihan, dan jadwal anak, serta menerima pengumuman dari sekolah.') }}
                        </p>
                        <span class="access__go">
                            {{ __('Masuk portal orang tua') }}
                            <span class="access__arrow" aria-hidden="true">{!! $icon('panah', 16) !!}</span>
                        </span>
                        <span class="sr-only">{{ __('Masuk ke portal orang tua') }}</span>
                    </a>

                    <a class="card access" href="{{ route('filament.admin.auth.login') }}">
                        <span class="card__icon">{!! $icon('guru') !!}</span>
                        <span class="tag tag--brand">{{ __('Sekolah') }}</span>
                        <h3 class="card__title">{{ __('Admin & Guru') }}</h3>
                        <p>
                            {{ __('Admin Sekolah, Kepala Sekolah, Guru, Wali Kelas, dan Bendahara masuk lewat panel sekolah.') }}
                        </p>
                        <span class="access__go">
                            {{ __('Masuk ke panel') }}
                            <span class="access__arrow" aria-hidden="true">{!! $icon('panah', 16) !!}</span>
                        </span>
                        <span class="sr-only">{{ __('Masuk ke panel sekolah') }}</span>
                    </a>
                </div>
            </div>
        </section>

        {{-- ===================================================== fitur ==== --}}
        <section class="section" id="fitur">
            <div class="shell">
                <div class="section__head">
                    <span class="section__kicker">{{ __('Kemampuan') }}</span>
                    <h2>{{ __('Yang sudah dapat digunakan') }}</h2>
                    <p>
                        {{ __('Daftar berikut adalah kemampuan yang sudah berjalan di sistem, bukan rencana pengembangan.') }}
                    </p>
                </div>

                {{-- `grid--rows`: di bawah 30rem kedelapan kartu berubah menjadi
                     baris padat, bukan kartu desktop yang dikecilkan
                     (butir 434). --}}
                <div class="grid grid--4 grid--rows">
                    {{-- Kuncinya ditulis sebagai pemanggilan `__()` harfiah di
                         dalam array, bukan `__($name)` atas variabel.
                         Bedanya bukan gaya: pemindai kunci pada
                         BilingualCoverageTest hanya melihat literal, sehingga
                         bentuk lama membuat kedua belas kunci ini tidak terlihat
                         olehnya — dan seluruh bagian ini tetap berbahasa
                         Indonesia di mode EN tanpa satu tes pun gagal
                         (butir 424). --}}
                    @foreach ([
                        ['ppdb', __('PPDB Online'), __('Formulir pendaftaran publik per cabang, peninjauan berkas oleh admin, pembaruan status, dan penerimaan menjadi siswa.')],
                        ['siswa', __('Data Siswa & Kelas'), __('Data induk siswa, pembagian kelas, mata pelajaran, penugasan guru, dan jadwal pelajaran mingguan.')],
                        ['nilai', __('Akademik & E-Rapor'), __('Input nilai per komponen, konfigurasi bobot penilaian, perhitungan nilai akhir otomatis, serta rapor yang dapat diunduh sebagai PDF.')],
                        ['keuangan', __('Keuangan Sekolah'), __('Jenis tagihan, penerbitan tagihan massal, pencatatan pembayaran beserta buktinya, buku kas, dan laporan keuangan.')],
                        ['ujian', __('Ujian Online'), __('Ujian pilihan ganda yang dikerjakan siswa langsung di peramban, tersimpan otomatis, dan dinilai sistem begitu dikumpulkan.')],
                        ['notifikasi', __('Notifikasi & Pengumuman'), __('Pengumuman ke seluruh sekolah, satu kelas, atau perorangan, dengan kotak masuk di setiap portal dan tautan WhatsApp per penerima.')],
                        ['portal-siswa', __('Portal Siswa'), __('Jadwal, nilai per mata pelajaran, ujian online, rapor, dan notifikasi — hanya milik siswa yang bersangkutan.')],
                        ['portal-ortu', __('Portal Orang Tua'), __('Ringkasan anak, nilai, tagihan, jadwal, dan notifikasi, dengan perpindahan antar anak bila lebih dari satu.')],
                    ] as [$glyph, $name, $summary])
                        <article class="card">
                            <span class="card__icon">{!! $icon($glyph) !!}</span>
                            <h3>{{ $name }}</h3>
                            <p>{{ $summary }}</p>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- =================================================== tentang ==== --}}
        <section class="section section--tint" id="tentang">
            <div class="shell">
                {{-- Bukan baris kartu ketiga: satu panel utuh dua kolom.
                     Naskah dan ketiga alasannya persis sama dengan sebelumnya —
                     yang berubah hanya bentuknya (butir 427). --}}
                <div class="about">
                    <div class="about__copy">
                        <span class="section__kicker">{{ __('Tentang') }}</span>
                        <h2>{{ __('Satu sistem untuk seluruh operasional sekolah') }}</h2>
                        <p>
                            {{ __('Pekerjaan sekolah biasanya tersebar di banyak berkas dan aplikasi terpisah: pendaftaran di satu tempat, nilai di tempat lain, tagihan di tempat ketiga. Smart Sukses School menyatukannya, sehingga data siswa cukup dimasukkan sekali dan dipakai seluruh bagian.') }}
                        </p>
                    </div>

                    <ul class="about__list">
                        <li class="about__item">
                            <span class="about__num" aria-hidden="true">1</span>
                            <div>
                                <h3>{{ __('Terpadu sejak pendaftaran') }}</h3>
                                <p>{{ __('Data calon siswa dari PPDB langsung menjadi data siswa ketika diterima, tanpa memasukkan ulang.') }}</p>
                            </div>
                        </li>

                        <li class="about__item">
                            <span class="about__num" aria-hidden="true">2</span>
                            <div>
                                <h3>{{ __('Mendukung banyak cabang') }}</h3>
                                <p>{{ __('Setiap cabang mengelola datanya sendiri dengan tampilan dan warnanya sendiri, terpisah satu sama lain di dalam satu sistem.') }}</p>
                            </div>
                        </li>

                        <li class="about__item">
                            <span class="about__num" aria-hidden="true">3</span>
                            <div>
                                <h3>{{ __('Diakses lewat peramban') }}</h3>
                                <p>{{ __('Tidak perlu memasang aplikasi. Tampilannya menyesuaikan layar ponsel maupun komputer.') }}</p>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>
        </section>

        {{-- =============================================== ajakan PPDB ==== --}}
        <section class="section">
            <div class="shell">
                <div class="cta">
                    <span class="eyebrow">
                        <span class="eyebrow__dot" aria-hidden="true"></span>
                        {{ __('Penerimaan Peserta Didik Baru') }}
                    </span>

                    <h2>{{ __('Daftar sebagai siswa baru, tanpa perlu membuat akun') }}</h2>
                    <p>
                        {{ __('Isi formulir pendaftaran secara online, unggah berkas pendukung, lalu pantau status pendaftaran kapan saja dengan nomor pendaftaran Anda.') }}
                    </p>

                    <div class="cta__buttons">
                        <a href="{{ route('ppdb.schools') }}" class="btn btn--accent btn--lg">{{ __('Mulai Pendaftaran') }}</a>
                        <a href="{{ route('ppdb.check-status') }}" class="btn btn--ghost btn--lg">{{ __('Cek Status Pendaftaran') }}</a>
                    </div>
                </div>
            </div>
        </section>

        {{-- ==================================================== cabang ==== --}}
        <section class="section" id="cabang">
            <div class="shell">
                <div class="section__head">
                    <span class="section__kicker">{{ __('Cabang') }}</span>
                    <h2>{{ __('Cabang yang Membuka Pendaftaran') }}</h2>
                    <p>
                        {{ __('Pilih cabang tujuan untuk mengisi formulir pendaftaran siswa baru.') }}
                    </p>
                </div>

                @if ($schools->isEmpty())
                    <div class="card">
                        <p class="muted" style="margin:0;">
                            {{ __('Belum ada cabang yang membuka pendaftaran saat ini.') }}
                        </p>
                    </div>
                @else
                    <div class="grid grid--3">
                        @foreach ($schools as $school)
                            {{-- Hanya nama, kode, dan alamat — persis yang sudah
                                 tampil di halaman PPDB publik. Telepon, surel,
                                 dan pengaturan cabang tidak ikut (butir 350). --}}
                            <a class="card access access--primary" href="{{ route('ppdb.register', ['schoolCode' => strtolower($school->code)]) }}">
                                <span class="card__icon">{!! $icon('cabang') !!}</span>
                                <span class="tag tag--brand">
                                    <span class="sr-only">{{ __('Kode cabang') }}: </span>{{ $school->code }}
                                </span>
                                <h3 class="branch__name">{{ $school->name }}</h3>
                                @if ($school->address)
                                    <p class="branch__address">
                                        <span aria-hidden="true">{!! $icon('pin', 16) !!}</span>
                                        <span><span class="sr-only">{{ __('Alamat') }}: </span>{{ $school->address }}</span>
                                    </p>
                                @endif
                                <span class="access__go">
                                    {{ __('Daftar di cabang ini') }}
                                    <span class="access__arrow" aria-hidden="true">{!! $icon('panah', 16) !!}</span>
                                </span>
                                <span class="sr-only">{{ __('Daftar PPDB di :school', ['school' => $school->name]) }}</span>
                            </a>
                        @endforeach
                    </div>
                @endif

                <p class="muted" style="margin-top:1.5rem;font-size:.93rem;">
                    {{ __('Sudah mendaftar?') }}
                    <a href="{{ route('ppdb.check-status') }}">{{ __('Cek status pendaftaran Anda') }}</a>.
                </p>
            </div>
        </section>
    </main>

    {{-- ==================================================== footer ==== --}}
    <footer class="footer">
        <div class="shell">
            <div class="footer__grid">
                <div>
                    <span class="footer__brand">
                        <span class="brand__mark" aria-hidden="true">{!! $icon('siswa', 20) !!}</span>
                        <span class="brand__text">
                            <span class="brand__name">{{ config('app.name') }}</span>
                            <span class="brand__tag">{{ __('Sistem Informasi Sekolah') }}</span>
                        </span>
                    </span>

                    <p class="footer__note">
                        {{ __('Platform manajemen sekolah terintegrasi: PPDB, akademik, keuangan, ujian online, dan portal untuk siswa serta orang tua.') }}
                    </p>

                    <div class="footer__locale">
                        <x-locale-switch />
                    </div>
                </div>

                <div>
                    <h3>{{ __('Jelajahi') }}</h3>
                    <ul>
                        <li><a href="#akses">{{ __('Akses Pengguna') }}</a></li>
                        <li><a href="#fitur">{{ __('Fitur') }}</a></li>
                        <li><a href="#tentang">{{ __('Tentang') }}</a></li>
                        <li><a href="#cabang">{{ __('Cabang') }}</a></li>
                    </ul>
                </div>

                <div>
                    <h3>{{ __('Pendaftaran') }}</h3>
                    <ul>
                        <li><a href="{{ route('ppdb.schools') }}">{{ __('Pendaftaran PPDB') }}</a></li>
                        <li><a href="{{ route('ppdb.check-status') }}">{{ __('Cek Status PPDB') }}</a></li>
                    </ul>
                </div>

                <div>
                    <h3>{{ __('Masuk') }}</h3>
                    <ul>
                        <li><a href="{{ route('student.login') }}">{{ __('Portal Siswa') }}</a></li>
                        <li><a href="{{ route('portal.login') }}">{{ __('Portal Orang Tua') }}</a></li>
                        <li><a href="{{ route('filament.admin.auth.login') }}">{{ __('Admin & Guru') }}</a></li>
                    </ul>
                </div>
            </div>

            <div class="footer__bottom">
                &copy; {{ now()->year }} {{ config('app.name') }}.
            </div>
        </div>
    </footer>
@endsection
