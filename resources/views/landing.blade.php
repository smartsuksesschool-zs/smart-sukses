{{--
    Halaman muka publik Smart Sukses School.

    Tambahan langsung atas permintaan pemilik; tidak ada di blueprint Phase 1
    (docs/owner-scope-changes.md bagian A).

    Seluruh teks di sini **naskah implementasi**, bukan naskah pemilik: Pak Akbar
    belum menyerahkan teks pemasaran. Karena itu tidak ada satu pun angka,
    testimoni, penghargaan, atau nama mitra — dan tidak ada nomor telepon, surel,
    maupun alamat yang dikarang. Yang disebut hanya kemampuan yang benar-benar
    sudah berjalan (butir 347).
--}}
@extends('layouts.landing')

@section('title', config('app.name').' — Platform Manajemen Sekolah Terintegrasi')

@section('description', 'Smart Sukses School: platform manajemen sekolah terintegrasi untuk PPDB, data siswa, akademik dan e-rapor, keuangan, ujian online, notifikasi, serta portal siswa dan orang tua.')

@section('content')
    {{-- ==================================================== navbar ==== --}}
    <header class="nav">
        <div class="shell nav__inner">
            <a href="{{ route('landing') }}" class="brand">
                <span class="brand__mark" aria-hidden="true">
                    {{-- Ikon hiasan, bukan logo resmi: tidak ada berkas logo
                         Smart Sukses School di repository, dan tidak ada yang
                         dikarang (butir 348). --}}
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false">
                        <path d="M22 10 12 5 2 10l10 5 10-5Z"/>
                        <path d="M6 12v5c0 1 2.7 2.5 6 2.5s6-1.5 6-2.5v-5"/>
                    </svg>
                </span>
                <span class="brand__name">{{ config('app.name') }}</span>
            </a>

            <nav class="nav__links" aria-label="Navigasi utama">
                <a href="#tentang">Tentang</a>
                <a href="#fitur">Fitur</a>
                <a href="#akses">Akses Pengguna</a>
                <a href="#cabang">PPDB</a>
                <a href="#akses" class="btn btn--primary" style="margin-left:.35rem;">Masuk</a>
            </nav>
        </div>
    </header>

    <main id="konten">
        {{-- ===================================================== hero ==== --}}
        <section class="hero">
            <div class="shell hero__inner">
                <span class="eyebrow">Sistem Informasi Sekolah</span>

                <h1>Platform Manajemen Sekolah Terintegrasi</h1>

                <p class="hero__lead">
                    Smart Sukses School menyatukan penerimaan siswa baru, data akademik,
                    keuangan sekolah, ujian online, dan komunikasi dengan orang tua ke dalam
                    satu sistem yang dapat diakses dari mana saja.
                </p>

                <div class="hero__cta">
                    {{-- Tidak ada satu halaman masuk untuk semua peran, jadi tombol
                         ini mengarah ke bagian akses — bukan berpura-pura ada
                         (butir 349). --}}
                    <a href="#akses" class="btn btn--primary">Masuk ke Sistem</a>
                    <a href="{{ route('ppdb.schools') }}" class="btn btn--accent">Daftar PPDB</a>
                </div>

                <p class="hero__note">
                    Setiap peran memiliki halaman masuknya sendiri — pilih pada bagian
                    <a href="#akses">Akses Pengguna</a>.
                </p>
            </div>
        </section>

        {{-- =================================================== tentang ==== --}}
        <section class="section section--tint" id="tentang">
            <div class="shell">
                <div class="section__head">
                    <h2>Satu sistem untuk seluruh operasional sekolah</h2>
                    <p>
                        Pekerjaan sekolah biasanya tersebar di banyak berkas dan aplikasi terpisah:
                        pendaftaran di satu tempat, nilai di tempat lain, tagihan di tempat ketiga.
                        Smart Sukses School menyatukannya, sehingga data siswa cukup dimasukkan
                        sekali dan dipakai seluruh bagian.
                    </p>
                </div>

                <div class="grid grid--3">
                    <article class="card">
                        <h3>Terpadu sejak pendaftaran</h3>
                        <p>
                            Data calon siswa dari PPDB langsung menjadi data siswa ketika diterima,
                            tanpa memasukkan ulang.
                        </p>
                    </article>

                    <article class="card">
                        <h3>Mendukung banyak cabang</h3>
                        <p>
                            Setiap cabang mengelola datanya sendiri dengan tampilan dan warnanya
                            sendiri, terpisah satu sama lain di dalam satu sistem.
                        </p>
                    </article>

                    <article class="card">
                        <h3>Diakses lewat peramban</h3>
                        <p>
                            Tidak perlu memasang aplikasi. Tampilannya menyesuaikan layar ponsel
                            maupun komputer.
                        </p>
                    </article>
                </div>
            </div>
        </section>

        {{-- ===================================================== fitur ==== --}}
        <section class="section" id="fitur">
            <div class="shell">
                <div class="section__head">
                    <h2>Yang sudah dapat digunakan</h2>
                    <p>
                        Daftar berikut adalah kemampuan yang sudah berjalan di sistem, bukan
                        rencana pengembangan.
                    </p>
                </div>

                <div class="grid grid--3">
                    @foreach ([
                        ['PPDB Online', 'Formulir pendaftaran publik per cabang, peninjauan berkas oleh admin, pembaruan status, dan penerimaan menjadi siswa.'],
                        ['Data Siswa & Kelas', 'Data induk siswa, pembagian kelas, mata pelajaran, penugasan guru, dan jadwal pelajaran mingguan.'],
                        ['Akademik & E-Rapor', 'Input nilai per komponen, konfigurasi bobot penilaian, perhitungan nilai akhir otomatis, serta rapor yang dapat diunduh sebagai PDF.'],
                        ['Keuangan Sekolah', 'Jenis tagihan, penerbitan tagihan massal, pencatatan pembayaran beserta buktinya, buku kas, dan laporan keuangan.'],
                        ['Ujian Online', 'Ujian pilihan ganda yang dikerjakan siswa langsung di peramban, tersimpan otomatis, dan dinilai sistem begitu dikumpulkan.'],
                        ['Notifikasi & Pengumuman', 'Pengumuman ke seluruh sekolah, satu kelas, atau perorangan, dengan kotak masuk di setiap portal dan tautan WhatsApp per penerima.'],
                        ['Portal Siswa', 'Jadwal, nilai per mata pelajaran, ujian online, rapor, dan notifikasi — hanya milik siswa yang bersangkutan.'],
                        ['Portal Orang Tua', 'Ringkasan anak, nilai, tagihan, jadwal, dan notifikasi, dengan perpindahan antar anak bila lebih dari satu.'],
                    ] as [$name, $summary])
                        <article class="card">
                            <span class="card__icon" aria-hidden="true">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false">
                                    <path d="m5 12 5 5L20 7"/>
                                </svg>
                            </span>
                            <h3>{{ $name }}</h3>
                            <p>{{ $summary }}</p>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- ============================================ akses pengguna ==== --}}
        <section class="section section--tint" id="akses">
            <div class="shell">
                <div class="section__head">
                    <h2>Akses Pengguna</h2>
                    <p>
                        Setiap peran masuk lewat halamannya sendiri. Pilih yang sesuai dengan Anda.
                    </p>
                </div>

                <div class="grid grid--4">
                    <a class="card access" href="{{ route('filament.admin.auth.login') }}">
                        <span class="tag">Sekolah</span>
                        <h3 style="margin-top:.6rem;">Admin &amp; Guru</h3>
                        <p>
                            Admin Sekolah, Kepala Sekolah, Guru, Wali Kelas, dan Bendahara
                            masuk lewat panel sekolah.
                        </p>
                        <span class="access__go" aria-hidden="true">Masuk ke panel →</span>
                        <span class="sr-only">Masuk ke panel sekolah</span>
                    </a>

                    <a class="card access" href="{{ route('student.login') }}">
                        <span class="tag">Siswa</span>
                        <h3 style="margin-top:.6rem;">Portal Siswa</h3>
                        <p>
                            Melihat jadwal, nilai, dan rapor, serta mengerjakan ujian online
                            yang sedang dibuka.
                        </p>
                        <span class="access__go" aria-hidden="true">Masuk portal siswa →</span>
                        <span class="sr-only">Masuk ke portal siswa</span>
                    </a>

                    <a class="card access" href="{{ route('portal.login') }}">
                        <span class="tag">Orang Tua</span>
                        <h3 style="margin-top:.6rem;">Portal Orang Tua</h3>
                        <p>
                            Memantau nilai, tagihan, dan jadwal anak, serta menerima
                            pengumuman dari sekolah.
                        </p>
                        <span class="access__go" aria-hidden="true">Masuk portal orang tua →</span>
                        <span class="sr-only">Masuk ke portal orang tua</span>
                    </a>

                    <a class="card access" href="{{ route('ppdb.schools') }}">
                        <span class="tag">Calon Siswa</span>
                        <h3 style="margin-top:.6rem;">Pendaftaran (PPDB)</h3>
                        <p>
                            Mendaftar sebagai siswa baru tanpa perlu akun, lalu memeriksa
                            status pendaftaran kapan saja.
                        </p>
                        <span class="access__go" aria-hidden="true">Buka pendaftaran →</span>
                        <span class="sr-only">Buka halaman pendaftaran PPDB</span>
                    </a>
                </div>
            </div>
        </section>

        {{-- ==================================================== cabang ==== --}}
        <section class="section" id="cabang">
            <div class="shell">
                <div class="section__head">
                    <h2>Cabang yang Membuka Pendaftaran</h2>
                    <p>
                        Pilih cabang tujuan untuk mengisi formulir pendaftaran siswa baru.
                    </p>
                </div>

                @if ($schools->isEmpty())
                    <div class="card">
                        <p class="muted" style="margin:0;">
                            Belum ada cabang yang membuka pendaftaran saat ini.
                        </p>
                    </div>
                @else
                    <div class="grid grid--3">
                        @foreach ($schools as $school)
                            {{-- Hanya nama, kode, dan alamat — persis yang sudah
                                 tampil di halaman PPDB publik. Telepon, surel,
                                 dan pengaturan cabang tidak ikut (butir 350). --}}
                            <a class="card access" href="{{ route('ppdb.register', ['schoolCode' => strtolower($school->code)]) }}">
                                <span class="tag">{{ $school->code }}</span>
                                <h3 style="margin-top:.6rem;">{{ $school->name }}</h3>
                                @if ($school->address)
                                    <p>{{ $school->address }}</p>
                                @endif
                                <span class="access__go" aria-hidden="true">Daftar di cabang ini →</span>
                                <span class="sr-only">Daftar PPDB di {{ $school->name }}</span>
                            </a>
                        @endforeach
                    </div>
                @endif

                <p class="muted" style="margin-top:1.25rem;font-size:.925rem;">
                    Sudah mendaftar?
                    <a href="{{ route('ppdb.check-status') }}">Cek status pendaftaran Anda</a>.
                </p>
            </div>
        </section>

        {{-- ==================================================== ajakan ==== --}}
        <section class="section">
            <div class="shell">
                <div class="cta">
                    <h2>Siap menggunakan Smart Sukses School?</h2>
                    <p>Masuk dengan akun Anda, atau mulai dari pendaftaran siswa baru.</p>

                    <div class="cta__buttons">
                        <a href="#akses" class="btn btn--accent">Masuk</a>
                        <a href="{{ route('ppdb.schools') }}" class="btn btn--ghost">Daftar PPDB</a>
                    </div>
                </div>
            </div>
        </section>
    </main>

    {{-- ==================================================== footer ==== --}}
    <footer class="footer">
        <div class="shell">
            <div class="footer__grid">
                <div>
                    <span class="footer__brand">
                        <span class="brand__mark" aria-hidden="true">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false">
                                <path d="M22 10 12 5 2 10l10 5 10-5Z"/>
                                <path d="M6 12v5c0 1 2.7 2.5 6 2.5s6-1.5 6-2.5v-5"/>
                            </svg>
                        </span>
                        {{ config('app.name') }}
                    </span>

                    <p class="footer__note">
                        Platform manajemen sekolah terintegrasi: PPDB, akademik, keuangan,
                        ujian online, dan portal untuk siswa serta orang tua.
                    </p>
                </div>

                <div>
                    <h3>Tautan</h3>
                    <ul>
                        <li><a href="#tentang">Tentang</a></li>
                        <li><a href="#fitur">Fitur</a></li>
                        <li><a href="#akses">Akses Pengguna</a></li>
                        <li><a href="{{ route('ppdb.schools') }}">Pendaftaran PPDB</a></li>
                        <li><a href="{{ route('ppdb.check-status') }}">Cek Status PPDB</a></li>
                    </ul>
                </div>

                <div>
                    <h3>Masuk</h3>
                    <ul>
                        <li><a href="{{ route('filament.admin.auth.login') }}">Admin &amp; Guru</a></li>
                        <li><a href="{{ route('student.login') }}">Portal Siswa</a></li>
                        <li><a href="{{ route('portal.login') }}">Portal Orang Tua</a></li>
                    </ul>
                </div>
            </div>

            <div class="footer__bottom">
                &copy; {{ now()->year }} {{ config('app.name') }}.
            </div>
        </div>
    </footer>
@endsection
