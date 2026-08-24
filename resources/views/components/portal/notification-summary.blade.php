{{--
    Ringkasan notifikasi pada dasbor guru dan siswa (API 4.11).

    Satu komponen untuk keduanya: bentuk datanya identik karena berasal dari
    formatter yang sama, jadi dua salinan kartu ini hanya akan menjadi dua
    tampilan yang perlahan berbeda (butir 207).

    Datanya sudah berupa array daftar-izin — komponen ini tidak pernah menerima
    model Notification, sehingga tidak ada kolom yang tidak disebut presenter
    yang dapat sampai ke halaman.
--}}
@props(['notifications', 'route', 'label' => 'Notifikasi'])

<div class="portal-card">
    <div class="portal-label">{{ $label }}</div>

    @if ($notifications['unread_count'] > 0)
        <div class="portal-metric">{{ $notifications['unread_count'] }}</div>
        <div class="portal-muted">belum dibaca</div>
    @else
        <div style="font-weight:600;margin-top:.25rem;">Tidak ada notifikasi belum dibaca</div>
    @endif

    @if (count($notifications['items']) > 0)
        <ul class="portal-list" style="margin-top:.75rem;">
            @foreach ($notifications['items'] as $item)
                <li>
                    <span style="min-width:0;">
                        {{-- Yang belum dibaca ditebalkan, bukan hanya diberi
                             warna. --}}
                        <span @unless ($item['is_read']) style="font-weight:700;" @endunless>{{ $item['title'] }}</span>
                        @if ($item['type_label'])
                            <span class="portal-badge portal-badge--muted">{{ $item['type_label'] }}</span>
                        @endif
                    </span>

                    <span class="portal-muted" style="white-space:nowrap;">
                        @if ($item['sent_at'])
                            {{--
                                Dasbor menerima `sent_at` dalam ISO 8601 — bentuk
                                yang sama dengan responsnya — dan memformatnya di
                                sini. Menambahkan label siap-tampil ke muatan API
                                akan memperluas kontraknya demi satu tampilan.
                            --}}
                            <time datetime="{{ $item['sent_at'] }}">
                                {{ \Illuminate\Support\Carbon::parse($item['sent_at'])->translatedFormat('d M Y') }}
                            </time>
                        @endif
                    </span>
                </li>
            @endforeach
        </ul>
    @endif

    <div style="margin-top:.75rem;">
        <a href="{{ $route }}">Lihat semua notifikasi</a>
    </div>
</div>
