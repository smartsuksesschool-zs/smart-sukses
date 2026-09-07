{{--
    Menghubungkan akun Google dengan sekolah.

    Memakai kartu dan token yang sama dengan halaman masuk, sehingga tidak ada
    satu baris CSS pun yang digandakan (butir 436).

    Tidak ada satu pun nama siswa yang ditampilkan di halaman ini — tidak
    sebelum dicocokkan, dan tidak sesudahnya. Yang membuka halaman ini belum
    terbukti berhak atas data siswa mana pun.

    Yang dipilih pemohon adalah **jenis permintaan**, bukan peran. Tidak ada
    "Saya Guru" di sini, dan tidak akan pernah ada: staf hanya menyatakan bahwa
    ia bekerja di sekolah ini, dan admin yang menentukan perannya (butir 544).
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

        @if ($state === 'pending')
            <h1 class="auth__title">{{ __('Menunggu Persetujuan') }}</h1>

            <p class="auth__lead">{{ __(\App\Livewire\Auth\GoogleClaim::PENDING_MESSAGE) }}</p>

            <p class="auth__note">
                {{ __('Admin sekolah akan memeriksa permintaan Anda. Setelah disetujui, Anda cukup menekan "Masuk dengan Google" dan langsung diarahkan ke halaman yang sesuai.') }}
            </p>

            <button type="button" wire:click="cancel" class="btn btn--wide">
                {{ __('Batalkan dan ulangi') }}
            </button>
        @elseif ($state === 'rejected')
            <h1 class="auth__title">{{ __('Permintaan Belum Disetujui') }}</h1>

            <p class="auth__lead">{{ __(\App\Livewire\Auth\GoogleClaim::REJECTED_MESSAGE) }}</p>

            <p class="auth__note">
                {{ __('Silakan hubungi admin sekolah untuk keterangan lebih lanjut.') }}
            </p>
        @else
            <h1 class="auth__title">{{ __('Hubungkan Akun') }}</h1>

            <p class="auth__lead">
                {{ __('Akun Google Anda belum terhubung dengan data sekolah. Isi data berikut agar admin dapat memeriksanya.') }}
            </p>

            @if ($this->identityEmail() !== '')
                <p class="auth__note">
                    {{ __('Masuk sebagai') }} <strong>{{ $this->identityEmail() }}</strong>
                </p>
            @endif

            <form wire:submit="submit" class="auth__form">
                <fieldset class="field">
                    <legend>{{ __('Saya adalah') }}</legend>

                    @foreach ($this->typeOptions() as $value)
                        <label class="field__check">
                            <input type="radio" wire:model.live="type" value="{{ $value }}" required>
                            <span>{{ \App\Enums\AccountClaimType::from($value)->publicLabel() }}</span>
                        </label>
                    @endforeach

                    @error('type') <p class="field__error">{{ $message }}</p> @enderror
                </fieldset>

                @if ($this->needsSchoolChoice())
                    {{--
                        Staf hanya memilih cabang. Tidak ada NIS/NISN yang dapat
                        ditanyakan — sekolah ini tidak memegang pengenal induk
                        pegawai — dan tidak ada yang dikarang sebagai gantinya
                        (butir 545).

                        Daftar cabang ini sudah publik di halaman PPDB, jadi
                        menampilkannya di sini tidak membuka apa pun yang baru.
                    --}}
                    @if ($this->schoolChoiceIsImplicit())
                        {{--
                            Hanya satu cabang yang menerima pendaftaran, jadi
                            tidak ada yang perlu dipilih. Cabangnya tetap
                            disebutkan — pemohon berhak tahu ke mana
                            permintaannya pergi (butir 557).
                        --}}
                        <div class="field">
                            <span class="field__label">{{ __('Cabang Tempat Bertugas') }}</span>
                            <p class="auth__note" style="margin-top:.25rem;">
                                <strong>{{ $this->implicitSchoolName() }}</strong>
                            </p>
                            @error('schoolId') <p class="field__error">{{ $message }}</p> @enderror
                        </div>
                    @else
                        <div class="field">
                            <label for="schoolId">{{ __('Cabang Tempat Bertugas') }}</label>
                            <select id="schoolId" wire:model="schoolId" required>
                                <option value="">{{ __('— pilih cabang —') }}</option>
                                @foreach ($this->schoolOptions() as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                            @error('schoolId') <p class="field__error">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    <p class="auth__note">
                        {{ __('Peran Anda (guru, wali kelas, bendahara, atau kepala sekolah) ditentukan admin sekolah saat menyetujui permintaan ini.') }}
                    </p>
                @elseif ($type !== '')
                    <div class="field">
                        <label for="nis">{{ __('NIS Siswa') }}</label>
                        <input id="nis" type="text" wire:model="nis" autocomplete="off" required>
                        @error('nis') <p class="field__error">{{ $message }}</p> @enderror
                    </div>

                    <div class="field">
                        <label for="nisn">{{ __('NISN Siswa') }}</label>
                        <input id="nisn" type="text" wire:model="nisn" autocomplete="off" required>
                        @error('nisn') <p class="field__error">{{ $message }}</p> @enderror
                    </div>

                    <p class="auth__note">
                        {{ __('Orang tua/wali mengisi NIS dan NISN anaknya. Untuk anak kedua, ajukan permintaan terpisah setelah yang pertama disetujui.') }}
                    </p>
                @endif

                <button type="submit" class="btn btn--primary btn--wide btn--lg">
                    <span wire:loading.remove wire:target="submit">{{ __('Kirim Permintaan') }}</span>
                    <span wire:loading wire:target="submit">{{ __('Memproses…') }}</span>
                </button>
            </form>
        @endif

        <div class="auth__foot">
            <button type="button" wire:click="abandon" class="auth__back">
                {{ __('Keluar dari proses ini') }}
            </button>
            <x-locale-switch />
        </div>
    </div>
</div>
