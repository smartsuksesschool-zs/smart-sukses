{{--
    Entri navigasi "Notifikasi" beserta lencana belum dibaca.

    Dipakai ketiga portal supaya lencananya tidak ditulis tiga kali dengan tiga
    perilaku yang perlahan berbeda. Angkanya diterima sebagai prop — dihitung
    sekali oleh tata letak, bukan sekali per komponen (butir 211).
--}}
@props(['route', 'active' => false, 'count' => 0])

<a href="{{ $route }}" @if ($active) aria-current="page" @endif>
    {{ __('Notifikasi') }}
    @if ($count > 0)
        {{-- Angkanya teks, bukan warna, dan disertai keterangan untuk pembaca
             layar supaya lencana tidak terbaca sebagai angka tanpa arti. --}}
        <span class="portal-nav__badge">{{ $count }}</span>
        <span class="portal-sr-only">{{ __('notifikasi belum dibaca') }}</span>
    @endif
</a>
