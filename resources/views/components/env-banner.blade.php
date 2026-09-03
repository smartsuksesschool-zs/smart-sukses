{{--
    Penanda lingkungan non-produksi.

    Staging memakai nama host sungguhan, data yang mirip sungguhan, dan tampilan
    yang identik dengan produksi. Satu-satunya yang membedakannya di mata staf
    adalah alamat di bilah URL — dan alamat adalah hal pertama yang berhenti
    dibaca orang setelah hari kedua. Menghapus data di tempat yang dikira
    staging padahal produksi adalah kekeliruan yang mahal dan sepenuhnya dapat
    dicegah (butir 510).

    Sengaja tidak dirender sama sekali di produksi maupun lokal: yang ada di
    produksi tidak boleh punya elemen ini dalam bentuk apa pun, bukan sekadar
    disembunyikan lewat CSS.

    Tidak mengganggu tata letak: `position: fixed` di luar aliran dokumen,
    `pointer-events: none` supaya tidak pernah menghalangi tombol di bawahnya,
    dan menghormati safe-area iOS.
--}}
@if (\App\Support\EnvironmentBanner::shouldRender())
    <div class="env-banner" role="status" aria-live="off">
        <span class="env-banner__dot" aria-hidden="true"></span>{{ \App\Support\EnvironmentBanner::label() }}
    </div>

    <style>
        .env-banner {
            position: fixed;
            right: max(.75rem, env(safe-area-inset-right));
            bottom: max(.75rem, env(safe-area-inset-bottom));
            z-index: 2147483000;
            display: inline-flex;
            align-items: center;
            gap: .4em;
            padding: .35em .75em;
            border-radius: 999px;
            border: 1px solid rgba(0, 0, 0, .12);
            background: #b45309;
            color: #fff;
            font: 600 11px/1.2 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            letter-spacing: .06em;
            text-transform: uppercase;
            white-space: nowrap;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .25);
            pointer-events: none;
        }

        .env-banner__dot {
            width: .5em;
            height: .5em;
            border-radius: 50%;
            background: #fde68a;
        }

        @media print {
            .env-banner { display: none; }
        }
    </style>
@endif
