@props([
    'url' => null,
    'alt' => '',
    'ratio' => '',
    'eager' => false,
])

{{--
    Bingkai foto halaman muka publik.

    Satu komponen untuk seluruh foto, karena keadaan "foto belum ada" harus
    tampil sama di mana pun ia muncul. Foto kegiatan Smart Sukses School yang
    sungguhan belum diserahkan, jadi keadaan itu bukan pengecualian langka
    melainkan keadaan bawaan halaman ini hari ini (butir 467).

    Penandanya sengaja bergaris — supaya terbaca sebagai bingkai yang belum
    berisi, bukan sebagai gambar yang gagal dimuat. Yang tidak dilakukan sama
    sekali: mengunduh foto sekolah lain untuk mengisinya.
    Foto anak yang bukan siswa Smart Sukses School, terpasang di halaman resmi
    Smart Sukses School, adalah klaim yang keliru sekalipun hanya sementara.

    Ketika fotonya tiba, pemilik cukup mengunggahnya dari panel admin. Tidak ada
    satu baris kode pun yang perlu berubah.
--}}
<div {{ $attributes->class(['photo', $ratio]) }}>
    @if ($url)
        <img
            src="{{ $url }}"
            alt="{{ $alt }}"
            {{-- Foto hero dimuat segera; sisanya menunggu gulungan. --}}
            loading="{{ $eager ? 'eager' : 'lazy' }}"
            decoding="async"
        >
    @else
        {{--
            Bingkai kosong yang netral, bukan pengumuman.

            Sampai M8 penandanya berbunyi "Foto menyusul" — kalimat yang benar
            selama fotonya memang belum ada sama sekali. Sejak sekolah
            menyerahkan koleksi fotonya, kalimat itu berubah menjadi janji yang
            dibaca pengunjung pada slot yang kebetulan belum diisi, padahal yang
            lain sudah. Yang tersisa karena itu hanya bingkai berukuran tetap:
            tata letaknya tidak bergeser, dan tidak ada yang dijanjikan
            (butir 565).
        --}}
        <div class="photo__ph" role="presentation">
            <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="3" y="5" width="18" height="14" rx="2"/>
                <circle cx="8.5" cy="10" r="1.5"/>
                <path d="m21 15-5-5L5 19"/>
            </svg>
        </div>
    @endif
</div>
