{{--
    NOTIF-04 — kotak masuk notifikasi portal.

    Satu halaman untuk ketiga portal. Daftar kartu, dan mengklik satu kartu
    membentangkan pesannya sekaligus menandainya terbaca — bukan halaman detail
    tersendiri, karena tidak ada yang perlu ditampilkan di sana selain pesan yang
    sudah ada di sini (butir 208).

    Isi notifikasi ditulis manusia dan tetap diperlakukan sebagai data tampilan
    yang tidak dipercaya: seluruhnya dirender `{{ }}` yang ter-escape, dan baris
    barunya diatur `white-space: pre-line` — tidak ada `{!! !!}` di halaman ini
    (butir 210).
--}}
<div>
    <div class="notif-head">
        <div>
            <h1 class="notif-head__title">Notifikasi</h1>
            {{--
                Lencana halaman. `aria-live` supaya pembaca layar mendengar
                angkanya berubah setelah menandai terbaca, bukan hanya melihat.
            --}}
            <p class="portal-muted" aria-live="polite">
                @if ($unreadCount > 0)
                    {{ $unreadCount }} belum dibaca dari {{ count($items) }} notifikasi
                @elseif (count($items) > 0)
                    Semua notifikasi sudah dibaca
                @else
                    Belum ada notifikasi
                @endif
            </p>
        </div>

        @if ($unreadCount > 0)
            <button
                type="button"
                class="notif-markall"
                wire:click="markAllRead"
                wire:loading.attr="disabled"
            >Tandai semua dibaca</button>
        @endif
    </div>

    <div class="notif-list">
        @forelse ($items as $item)
            <article class="portal-card notif @unless ($item['is_read']) notif--unread @endunless">
                {{--
                    Seluruh kartu adalah satu tombol: sasaran sentuhnya selebar
                    kartu, bukan hanya judulnya.
                --}}
                <button
                    type="button"
                    class="notif__toggle"
                    wire:click="open({{ $item['id'] }})"
                    aria-expanded="{{ $openId === $item['id'] ? 'true' : 'false' }}"
                >
                    <span class="notif__top">
                        <span class="notif__title">{{ $item['title'] }}</span>
                        @if ($item['type_label'])
                            <span class="portal-badge portal-badge--muted">{{ $item['type_label'] }}</span>
                        @endif
                    </span>

                    <span class="notif__meta">
                        @if ($item['sent_at_label'])
                            <time datetime="{{ $item['sent_at'] }}">{{ $item['sent_at_label'] }}</time>
                        @endif

                        {{--
                            Keadaan terbaca tidak pernah hanya warna: ada
                            teksnya, dan yang belum dibaca juga ditebalkan.
                        --}}
                        @if ($item['is_read'])
                            <span class="portal-badge portal-badge--muted">Sudah dibaca</span>
                        @else
                            <span class="portal-badge portal-badge--warning">Belum dibaca</span>
                        @endif
                    </span>
                </button>

                @if ($openId === $item['id'])
                    <div class="notif__body">
                        <p class="notif__message">{{ $item['message'] }}</p>

                        <p class="portal-muted notif__from">
                            @if ($item['sender_name'])
                                Dari {{ $item['sender_name'] }}
                            @else
                                Notifikasi sistem
                            @endif

                            @if ($item['read_at_label'])
                                · Dibaca {{ $item['read_at_label'] }}
                            @endif
                        </p>
                    </div>
                @endif
            </article>
        @empty
            <div class="portal-card">
                <div style="font-weight:600;">Belum ada notifikasi</div>
                <p class="portal-muted" style="margin:.25rem 0 0;">
                    Pengumuman dari sekolah akan muncul di halaman ini.
                </p>
            </div>
        @endforelse
    </div>
</div>
