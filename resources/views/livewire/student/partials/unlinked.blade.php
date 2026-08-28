{{--
    Akun berperan SISWA yang belum tertaut ke data siswa. `students.user_id`
    boleh NULL menurut ERD, jadi keadaan ini wajar — penautannya dilakukan
    admin sekolah lewat data siswa (butir 182).
--}}
<div class="portal-card">
    <h1 style="margin-top:0;font-size:1.25rem;">{{ __('Akun belum terhubung') }}</h1>
    <p class="portal-muted" style="margin-bottom:0;">
        {{ __('Akun Anda belum terhubung dengan data siswa mana pun. Silakan hubungi administrasi sekolah untuk menautkannya.') }}
    </p>
</div>
