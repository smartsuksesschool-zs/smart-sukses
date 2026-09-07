{{--
    Pintu masuk alur "daftarkan anak berikutnya".

    Tanpa tautan ini alur anak kedua secara teknis ada tetapi tidak dapat
    dijangkau siapa pun: begitu anak pertama disetujui, akun orang tuanya sudah
    ada, sehingga setiap "Masuk dengan Google" berikutnya langsung mendarat di
    halaman ini dan halaman pencocokan tidak pernah lagi terbuka sendiri
    (butir 537).

    Hanya muncul bagi akun yang memang lahir dari Google. Akun orang tua yang
    dibuatkan admin secara manual ditautkan admin pula, dan menawarkan formulir
    NIS/NISN kepadanya hanya akan membingungkan.
--}}
@if (auth()->user()?->auth_provider === \App\Enums\AuthProvider::Google)
    <p class="portal-muted" style="margin:0 0 1rem;">
        <a href="{{ route('oauth.google.claim') }}">{{ __('Daftarkan anak lainnya') }}</a>
    </p>
@endif
