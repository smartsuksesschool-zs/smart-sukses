{{--
    Halaman muka publik Smart Sukses School — V2.

    **Perubahan maksud, bukan perubahan gaya.** V1 menjelaskan perangkat
    lunaknya: modul, fitur, portal. Umpan balik pemilik setelah simulasi
    menyatakan pembaca `/` bukan calon pengguna sistem melainkan orang tua calon
    siswa, dan yang harus ia pahami lebih dulu adalah sekolahnya. Sistem
    informasi tetap ada, turun menjadi satu bagian "Akses Sistem" menjelang kaki
    halaman (butir 475).

    Seluruh teks, tautan, dan foto dibaca lewat `App\Support\PublicSite`,
    sehingga dapat disunting pemilik dari panel admin tanpa menyentuh berkas ini
    (butir 466). Yang tetap ditulis di sini hanya label antarmuka — nama menu,
    label tombol — karena itu bagian dari aplikasinya, bukan naskah pemilik.

    Tidak ada satu angka pun: jumlah siswa, jumlah alumni, tahun berdiri, dan
    klaim sejenis dari materi publik lama tidak diulang karena belum ada sumber
    terkini yang mengesahkannya (butir 468).
--}}
@extends('layouts.landing')

@section('title', config('app.name').' — '.__('Sekolah Berbasis Beasiswa, Karakter, dan Life Skills'))

@section('description', 'Smart Sukses School adalah ekosistem pendidikan berbasis beasiswa dengan dua unit: Smart Bee (SD) dan Smart Building (SMA). Pembelajaran akademik, character building, life skills, dan kepemimpinan.')

@php
    /**
     * Ikon garis, satu bentuk per maksud, seluruhnya `aria-hidden` — makna
     * dibawa judul dan teksnya (butir 419).
     *
     * @var array<string, string>
     */
    $icons = [
        'hati' => '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/>',
        'tumbuh' => '<path d="M12 22V9"/><path d="M12 9C12 5 9 2 5 2c0 4 3 7 7 7Z"/><path d="M12 12c0-3.31 2.69-6 6-6 0 3.31-2.69 6-6 6Z"/>',
        'sukses' => '<circle cx="12" cy="8" r="5"/><path d="M8.5 12.5 7 22l5-3 5 3-1.5-9.5"/>',
        'buku' => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/>',
        'topi' => '<path d="M22 10 12 5 2 10l10 5 10-5Z"/><path d="M6 12v5c0 1 2.7 2.5 6 2.5s6-1.5 6-2.5v-5"/>',
        'siswa' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'ortu' => '<circle cx="9" cy="8" r="3"/><circle cx="17" cy="10" r="2.5"/><path d="M3 20a6 6 0 0 1 12 0"/><path d="M15.5 20a4.5 4.5 0 0 1 5.5-4.4"/>',
        'guru' => '<rect x="3" y="4" width="18" height="14" rx="2"/><path d="M7 20h10"/><path d="M8 9h6M8 13h4"/>',
        'pin' => '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
        'telepon' => '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.2a2 2 0 0 1 2.1-.5c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2Z"/>',
        'surel' => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2 7 10 6 10-6"/>',
        'panah' => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
        'luar' => '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><path d="M15 3h6v6"/><path d="M10 14 21 3"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'tutup' => '<path d="M6 6l12 12M18 6 6 18"/>',
        'instagram' => '<rect x="2" y="2" width="20" height="20" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1"/>',
        'facebook' => '<path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3Z"/>',
        'youtube' => '<path d="M22 12s0-3.5-.45-5.17a2.7 2.7 0 0 0-1.9-1.9C17.98 4.5 12 4.5 12 4.5s-5.98 0-7.65.43a2.7 2.7 0 0 0-1.9 1.9C2 8.5 2 12 2 12s0 3.5.45 5.17a2.7 2.7 0 0 0 1.9 1.9c1.67.43 7.65.43 7.65.43s5.98 0 7.65-.43a2.7 2.7 0 0 0 1.9-1.9C22 15.5 22 12 22 12Z"/><path d="m10 15 5-3-5-3Z"/>',
    ];

    /** Satu pembungkus SVG untuk seluruh ikon, supaya ukurannya konsisten. */
    $icon = fn (string $name, int $size = 20): string => sprintf(
        '<svg width="%1$d" height="%1$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
        .'stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false" '
        .'aria-hidden="true">%2$s</svg>',
        $size,
        $icons[$name] ?? '',
    );

    /**
     * Menu utama, ditulis satu kali.
     *
     * Strip lebar dan panel seluler menampilkan daftar yang sama; menulisnya
     * dua kali berarti keduanya berbeda diam-diam pada perubahan berikutnya.
     *
     * Butir menu yang bagiannya tidak dirender ikut dibuang: bagian yang belum
     * berisi memang tidak ditampilkan (butir 482), dan tautan menuju jangkar
     * yang tidak ada adalah tautan mati — pengunjung menekannya dan tidak
     * terjadi apa-apa.
     *
     * @var array<string, string>
     */
    $menu = array_filter([
        '#konten' => __('Beranda'),
        '#tentang' => __('Tentang'),
        '#unit' => $units->isNotEmpty() ? __('Unit Pendidikan') : null,
        '#program' => $programs->isNotEmpty() ? __('Program') : null,
        '#kegiatan' => $gallery->isNotEmpty() ? __('Kegiatan') : null,
        '#artikel' => ($articles->isNotEmpty() || $site->blogUrl()) ? __('Artikel') : null,
        '#ppdb' => __('PPDB'),
    ]);

    $socials = array_filter([
        'instagram' => $site->social('instagram'),
        'facebook' => $site->social('facebook'),
        'youtube' => $site->social('youtube'),
    ]);
@endphp

@section('content')
    {{-- ==================================================== navbar ==== --}}
    <header class="nav">
        <div class="shell nav__inner">
            <a href="{{ route('landing') }}" class="brand">
                {{-- Logo dibaca dari pengaturan, dengan berkas merek yang
                     diserahkan pemilik sebagai bawaan. Tidak dipatri di Blade:
                     pemilik harus dapat menggantinya sendiri (butir 466). --}}
                <img
                    src="{{ $site->logoUrl() }}"
                    alt="{{ config('app.name') }}"
                    class="brand__logo"
                    width="500"
                    height="200"
                >
            </a>

            <nav class="nav__links" aria-label="{{ __('Navigasi utama') }}">
                @foreach ($menu as $href => $label)
                    <a class="nav__link" href="{{ $href }}">{{ $label }}</a>
                @endforeach

                <a href="{{ route('login') }}" class="btn btn--primary nav__cta">{{ __('Masuk') }}</a>
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
                    @foreach ($menu as $href => $label)
                        <a href="{{ $href }}">{{ $label }}</a>
                    @endforeach
                    <a href="#akses">{{ __('Akses Sistem') }}</a>
                    <a href="#kontak">{{ __('Kontak') }}</a>

                    <div class="nav__panel-locale">
                        <span>{{ __('Bahasa / Language') }}</span>
                        <x-locale-switch />
                    </div>
                </nav>
            </details>

            {{-- Aksi utama tidak pernah ikut disembunyikan di balik menu. --}}
            <a href="{{ route('login') }}" class="btn btn--primary nav__cta--bar">{{ __('Masuk') }}</a>
        </div>
    </header>

    <main id="konten">
        {{-- ===================================================== hero ==== --}}
        <section class="hero">
            <div class="shell hero__inner">
                <div class="hero__copy">
                    {{-- Identitas sekolah dibawa eyebrow, bukan <h1>: judulnya
                         sendiri harus dapat disunting pemilik, dan nama sekolah
                         yang terkunci di <h1> membuat judul editable itu tidak
                         punya tempat (butir 480). --}}
                    <span class="eyebrow">
                        <span class="eyebrow__dot" aria-hidden="true"></span>
                        {{ config('app.name') }}
                    </span>

                    {{-- Satu <h1> di seluruh halaman, dan isinya dapat diubah
                         dari panel admin. --}}
                    <h1>{{ $site->text('hero_heading') }}</h1>

                    {{-- Tagline resmi pemilik, kata demi kata. Konstanta, bukan
                         pengaturan yang dapat disunting — lihat PublicSite. --}}
                    <p class="hero__tagline">{{ \App\Support\PublicSite::TAGLINE }}</p>

                    <p class="hero__lead">{{ $site->text('hero_description') }}</p>

                    <div class="hero__cta">
                        <a
                            href="{{ $site->ppdbUrl() }}"
                            class="btn btn--accent btn--lg"
                            @if ($site->ppdbIsExternal()) target="_blank" rel="noopener" @endif
                        >{{ __('Daftar PPDB') }}</a>

                        <a href="#tentang" class="btn btn--ghost btn--lg">{{ __('Kenali Smart Sukses School') }}</a>
                    </div>

                    <p class="hero__note">
                        {{ __('Dua unit pendidikan: Smart Bee untuk jenjang SD dan Smart Building untuk jenjang SMA.') }}
                    </p>
                </div>

                <div class="hero__media">
                    <x-site-photo
                        :url="$site->heroImageUrl()"
                        :alt="__('Kegiatan siswa Smart Sukses School')"
                        ratio="photo--wide"
                        :eager="true"
                    />
                </div>
            </div>
        </section>

        {{-- ================================================== tentang ==== --}}
        <section class="section section--tint" id="tentang">
            <div class="shell">
                <div class="section__head">
                    <span class="section__kicker">{{ __('Tentang') }}</span>
                    <h2>{{ $site->text('about_heading') }}</h2>
                    <p>{{ $site->text('about_body') }}</p>
                </div>

                {{-- Tiga kata pada tagline, dijelaskan satu per satu. Bukan
                     daftar fitur perangkat lunak: bagian ini menjelaskan
                     programnya (butir 475). --}}
                <div class="grid grid--3">
                    <article class="card">
                        <span class="card__icon" aria-hidden="true">{!! $icon('hati') !!}</span>
                        <h3>{{ __('Belajar dengan Hati') }}</h3>
                        <p>{{ __('Pendampingan yang mengenali setiap anak, bukan sekadar mengejar nilai.') }}</p>
                    </article>

                    <article class="card">
                        <span class="card__icon" aria-hidden="true">{!! $icon('tumbuh') !!}</span>
                        <h3>{{ __('Tumbuh dengan Aksi') }}</h3>
                        <p>{{ __('Karakter dan keterampilan dilatih lewat kegiatan nyata, bukan hanya dibicarakan di kelas.') }}</p>
                    </article>

                    <article class="card">
                        <span class="card__icon" aria-hidden="true">{!! $icon('sukses') !!}</span>
                        <h3>{{ __('Sukses untuk Masa Depan') }}</h3>
                        <p>{{ __('Bekal akademik, kemandirian, dan kepemimpinan untuk jenjang berikutnya.') }}</p>
                    </article>
                </div>
            </div>
        </section>

        {{-- ========================================== unit pendidikan ==== --}}
        {{--
            Bagian yang isinya berasal dari basis data hanya dirender bila
            isinya memang ada.

            Tanpa penjaga ini, pemasangan yang belum pernah diisi menampilkan
            judul bagian di atas ruang kosong — lubang yang terbaca sebagai
            kerusakan, bukan sebagai halaman yang belum lengkap. Karena
            PublicSiteSeeder sengaja tidak berjalan otomatis di produksi
            (butir 481), keadaan "belum ada isi" adalah keadaan awal yang
            normal dan harus tampil rapi (butir 482).
        --}}
        @if ($units->isNotEmpty())
        <section class="section" id="unit">
            <div class="shell">
                <div class="section__head">
                    <span class="section__kicker">{{ __('Unit Pendidikan') }}</span>
                    <h2>{{ __('Dua unit di bawah Smart Sukses School') }}</h2>
                    <p>{{ __('Smart Bee dan Smart Building bukan lembaga terpisah dan bukan mitra — keduanya unit pendidikan Smart Sukses School untuk jenjang yang berbeda.') }}</p>
                </div>

                <div class="grid grid--2">
                    @foreach ($units as $unit)
                        <article class="card unit">
                            <x-site-photo
                                :url="$unit->imageUrl()"
                                :alt="$unit->title"
                                ratio="photo--wide"
                            />

                            <div class="unit__body">
                                @if ($unit->subtitle)
                                    <span class="unit__level">{{ $unit->subtitle }}</span>
                                @endif

                                <h3>{{ $unit->title }}</h3>

                                @if ($unit->body)
                                    <p>{{ $unit->body }}</p>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>

        @endif

        {{-- ================================================== program ==== --}}
        @if ($programs->isNotEmpty())
        <section class="section section--tint" id="program">
            <div class="shell">
                <div class="section__head">
                    <span class="section__kicker">{{ __('Program') }}</span>
                    <h2>{{ __('Pendekatan pendidikan kami') }}</h2>
                    <p>{{ __('Akademik berjalan berdampingan dengan pembentukan karakter dan keterampilan hidup.') }}</p>
                </div>

                <div class="grid grid--3">
                    @foreach ($programs as $program)
                        <article class="card">
                            <span class="card__icon" aria-hidden="true">{!! $icon('buku') !!}</span>
                            <h3>{{ $program->title }}</h3>

                            @if ($program->body)
                                <p>{{ $program->body }}</p>
                            @endif
                        </article>
                    @endforeach
                </div>
            </div>
        </section>

        @endif

        {{-- ================================================= kegiatan ==== --}}
        @if ($gallery->isNotEmpty())
        <section class="section" id="kegiatan">
            <div class="shell">
                <div class="section__head">
                    <span class="section__kicker">{{ __('Kegiatan') }}</span>
                    <h2>{{ $site->text('activity_heading') }}</h2>
                    <p>{{ $site->text('activity_description') }}</p>
                </div>

                {{-- Kartu pertama melebar pada layar besar sehingga bagian ini
                     punya titik berat; pada ponsel seluruhnya satu kolom
                     (butir 477). --}}
                <div class="gallery">
                    @foreach ($gallery as $index => $activity)
                        <figure class="gallery__item @if ($index === 0) gallery__item--lead @endif">
                            <x-site-photo
                                :url="$activity->imageUrl()"
                                :alt="$activity->title"
                                :ratio="$index === 0 ? 'photo--wide' : 'photo--square'"
                            />
                            <figcaption class="gallery__caption">{{ $activity->title }}</figcaption>
                        </figure>
                    @endforeach
                </div>
            </div>
        </section>

        @endif

        {{-- ================================================== artikel ==== --}}
        {{-- Bagian ini tetap berguna tanpa satu pratinjau pun, asalkan alamat
             blognya sudah disetel: tautannya sendiri yang menjadi isinya. --}}
        @if ($articles->isNotEmpty() || $site->blogUrl())
        <section class="section section--tint" id="artikel">
            <div class="shell">
                <div class="section__head">
                    <span class="section__kicker">{{ __('Kabar') }}</span>
                    <h2>{{ $site->text('article_heading') }}</h2>
                    <p>{{ $site->text('article_description') }}</p>
                </div>

                @if ($articles->isNotEmpty())
                    <div class="grid grid--3">
                        @foreach ($articles as $article)
                            <article class="card article">
                                <x-site-photo
                                    :url="$article->imageUrl()"
                                    :alt="$article->title"
                                    ratio="photo--wide"
                                />

                                <div class="article__body">
                                    @if ($article->link_url)
                                        <a href="{{ $article->link_url }}" class="article__link" target="_blank" rel="noopener">
                                            <h3>{{ $article->title }}</h3>
                                        </a>
                                    @else
                                        <h3>{{ $article->title }}</h3>
                                    @endif

                                    @if ($article->body)
                                        <p>{{ $article->body }}</p>
                                    @endif
                                </div>
                            </article>
                        @endforeach
                    </div>
                @endif

                {{-- Tautan blog hanya muncul bila alamatnya memang sudah
                     disetel; alamat blog tidak pernah ditulis di template
                     (butir 470). --}}
                @if ($site->blogUrl())
                    <p style="margin-top:1.75rem;">
                        <a href="{{ $site->blogUrl() }}" class="btn btn--ghost" target="_blank" rel="noopener">
                            {{ __('Baca semua artikel') }}
                            {!! $icon('luar', 16) !!}
                        </a>
                    </p>
                @endif
            </div>
        </section>

        @endif

        {{-- ===================================================== ppdb ==== --}}
        <section class="section" id="ppdb">
            <div class="shell">
                <div class="cta">
                    <h2>{{ $site->text('ppdb_heading') }}</h2>
                    <p>{{ $site->text('ppdb_description') }}</p>

                    <div class="cta__buttons">
                        <a
                            href="{{ $site->ppdbUrl() }}"
                            class="btn btn--accent btn--lg"
                            @if ($site->ppdbIsExternal()) target="_blank" rel="noopener" @endif
                        >{{ __('Daftar Sekarang') }}</a>

                        <a href="{{ route('ppdb.check-status') }}" class="btn btn--ghost btn--lg">
                            {{ __('Cek Status Pendaftaran') }}
                        </a>
                    </div>
                </div>
            </div>
        </section>

        {{-- ============================================ akses sistem ==== --}}
        {{--
            Turun ke sini dari posisinya di V1, tepat di bawah hero.

            Pembacanya berbeda dari pembaca seluruh halaman di atas: ini untuk
            siswa, orang tua, dan guru yang sudah punya akun. Menempatkannya di
            atas berarti pengunjung pertama kali disambut pintu masuk sistem
            sebelum ia tahu sekolahnya apa (butir 475).
        --}}
        <section class="section section--tint" id="akses">
            <div class="shell">
                <div class="section__head">
                    <span class="section__kicker">{{ __('Untuk Warga Sekolah') }}</span>
                    <h2>{{ __('Akses Sistem Informasi') }}</h2>
                    <p>{{ __('Siswa, orang tua, guru, dan staf masuk lewat satu halaman yang sama. Sistem mengenali peran Anda dari akun.') }}</p>
                </div>

                <div class="grid grid--3">
                    <a href="{{ route('login') }}" class="card access">
                        <span class="card__icon" aria-hidden="true">{!! $icon('siswa') !!}</span>
                        <h3>{{ __('Siswa') }}</h3>
                        <p>{{ __('Jadwal pelajaran, nilai, dan ujian online.') }}</p>
                        <span class="access__go">{{ __('Masuk') }} {!! $icon('panah', 16) !!}</span>
                    </a>

                    <a href="{{ route('login') }}" class="card access">
                        <span class="card__icon" aria-hidden="true">{!! $icon('ortu') !!}</span>
                        <h3>{{ __('Orang Tua') }}</h3>
                        <p>{{ __('Perkembangan belajar dan tagihan anak.') }}</p>
                        <span class="access__go">{{ __('Masuk') }} {!! $icon('panah', 16) !!}</span>
                    </a>

                    <a href="{{ route('login') }}" class="card access">
                        <span class="card__icon" aria-hidden="true">{!! $icon('guru') !!}</span>
                        <h3>{{ __('Guru & Staf') }}</h3>
                        <p>{{ __('Kelas ajar, input nilai, dan administrasi sekolah.') }}</p>
                        <span class="access__go">{{ __('Masuk') }} {!! $icon('panah', 16) !!}</span>
                    </a>
                </div>
            </div>
        </section>

        {{-- =================================================== kontak ==== --}}
        <section class="section" id="kontak">
            <div class="shell">
                <div class="section__head">
                    <span class="section__kicker">{{ __('Kontak') }}</span>
                    <h2>{{ __('Hubungi Smart Sukses School') }}</h2>
                </div>

                {{-- Setiap baris hanya dirender bila datanya memang ada. Alamat,
                     nomor, dan surel sekolah tidak pernah dikarang. --}}
                <div class="grid grid--3">
                    @if ($site->contact('address'))
                        <div class="contact__item">
                            {!! $icon('pin') !!}
                            <div>
                                <div class="contact__label">{{ __('Alamat') }}</div>
                                <div class="contact__value">{{ $site->contact('address') }}</div>
                            </div>
                        </div>
                    @endif

                    @if ($site->contact('phone'))
                        <div class="contact__item">
                            {!! $icon('telepon') !!}
                            <div>
                                <div class="contact__label">{{ __('Telepon') }}</div>
                                <div class="contact__value">
                                    <a href="tel:{{ preg_replace('/[^0-9+]/', '', $site->contact('phone')) }}">
                                        {{ $site->contact('phone') }}
                                    </a>
                                </div>
                            </div>
                        </div>
                    @endif

                    @if ($site->contact('email'))
                        <div class="contact__item">
                            {!! $icon('surel') !!}
                            <div>
                                <div class="contact__label">{{ __('Surel') }}</div>
                                <div class="contact__value">
                                    <a href="mailto:{{ $site->contact('email') }}">{{ $site->contact('email') }}</a>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>

                @if ($socials !== [])
                    <div class="socials">
                        @foreach ($socials as $name => $url)
                            <a href="{{ $url }}" target="_blank" rel="noopener" aria-label="{{ ucfirst($name) }}">
                                {!! $icon($name) !!}
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>
    </main>

    {{-- ==================================================== footer ==== --}}
    <footer class="footer">
        <div class="shell">
            <div class="footer__grid">
                <div>
                    <img
                        src="{{ $site->logoUrl() }}"
                        alt="{{ config('app.name') }}"
                        class="footer__logo"
                        width="500"
                        height="200"
                    >

                    <p class="footer__note">{{ \App\Support\PublicSite::TAGLINE }}</p>

                    <div class="footer__locale">
                        <x-locale-switch />
                    </div>
                </div>

                <div>
                    <h3>{{ __('Jelajahi') }}</h3>
                    {{-- Daftar yang sama dengan menu utama, jadi ia ikut
                         menyusut bersama bagian yang tidak dirender. --}}
                    <ul>
                        @foreach ($menu as $href => $label)
                            @if ($href !== '#konten' && $href !== '#ppdb')
                                <li><a href="{{ $href }}">{{ $label }}</a></li>
                            @endif
                        @endforeach
                    </ul>
                </div>

                <div>
                    <h3>{{ __('Pendaftaran') }}</h3>
                    <ul>
                        <li>
                            <a
                                href="{{ $site->ppdbUrl() }}"
                                @if ($site->ppdbIsExternal()) target="_blank" rel="noopener" @endif
                            >{{ __('Pendaftaran PPDB') }}</a>
                        </li>
                        <li><a href="{{ route('ppdb.check-status') }}">{{ __('Cek Status PPDB') }}</a></li>
                        @if ($site->blogUrl())
                            <li><a href="{{ $site->blogUrl() }}" target="_blank" rel="noopener">{{ __('Artikel') }}</a></li>
                        @endif
                    </ul>
                </div>

                <div>
                    <h3>{{ __('Masuk') }}</h3>
                    <ul>
                        <li><a href="{{ route('login') }}">{{ __('Portal Siswa') }}</a></li>
                        <li><a href="{{ route('login') }}">{{ __('Portal Orang Tua') }}</a></li>
                        <li><a href="{{ route('login') }}">{{ __('Guru & Staf') }}</a></li>
                    </ul>
                </div>
            </div>

            <div class="footer__bottom">
                &copy; {{ now()->year }} {{ config('app.name') }}.
            </div>
        </div>
    </footer>
@endsection
