{{--
    Halaman masuk terpadu.

    Memakai tata letak halaman muka apa adanya, sehingga seluruh token warna,
    radius, bayangan, tombol, dan pemilih bahasanya sama persis — tanpa satu
    baris CSS pun yang digandakan (butir 436).

    Tidak ada pemilih peran. Tidak ada tautan lupa kata sandi: SMTP belum
    diadakan, dan menawarkan pemulihan yang tidak dapat mengirim surel lebih
    buruk daripada tidak menawarkannya sama sekali (butir 441).
--}}
<div class="auth" id="konten">
    <div class="auth__card">
        <a href="{{ route('landing') }}" class="brand auth__brand">
            <span class="brand__mark" aria-hidden="true">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false">
                    <path d="M22 10 12 5 2 10l10 5 10-5Z"/>
                    <path d="M6 12v5c0 1 2.7 2.5 6 2.5s6-1.5 6-2.5v-5"/>
                </svg>
            </span>
            <span class="brand__text">
                <span class="brand__name">{{ config('app.name') }}</span>
                <span class="brand__tag">{{ __('Sistem Informasi Sekolah') }}</span>
            </span>
        </a>

        <h1 class="auth__title">{{ __('Masuk ke Sistem') }}</h1>

        <p class="auth__lead">{{ __('Gunakan akun Smart Sukses School Anda.') }}</p>

        <form wire:submit="authenticate" class="auth__form">
            <div class="field">
                <label for="email">{{ __('Email') }}</label>
                <input
                    id="email"
                    type="email"
                    wire:model="email"
                    autocomplete="username"
                    inputmode="email"
                    autofocus
                    required
                >
                @error('email') <p class="field__error">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label for="password">{{ __('Kata Sandi') }}</label>
                <input
                    id="password"
                    type="password"
                    wire:model="password"
                    autocomplete="current-password"
                    required
                >
                @error('password') <p class="field__error">{{ $message }}</p> @enderror
            </div>

            <label class="field__check">
                <input type="checkbox" wire:model="remember">
                <span>{{ __('Ingat saya') }}</span>
            </label>

            <button type="submit" class="btn btn--primary btn--wide btn--lg">
                <span wire:loading.remove wire:target="authenticate">{{ __('Masuk') }}</span>
                <span wire:loading wire:target="authenticate">{{ __('Memproses…') }}</span>
            </button>
        </form>

        <p class="auth__note">
            {{ __('Belum menjadi siswa?') }}
            <a href="{{ route('ppdb.schools') }}">{{ __('Daftar PPDB') }}</a>
        </p>

        <div class="auth__foot">
            <a href="{{ route('landing') }}" class="auth__back">{{ __('Kembali ke beranda') }}</a>
            <x-locale-switch />
        </div>
    </div>
</div>
