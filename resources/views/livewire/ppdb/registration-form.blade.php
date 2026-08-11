{{-- PPDB-01 — formulir pendaftaran PPDB online, tanpa login. --}}
<div>
    @if ($regNumber)
        {{-- PPDB-01 poin 3 — setelah submit, tampil nomor pendaftaran unik. --}}
        <div class="card">
            <h2>{{ __('Pendaftaran Berhasil') }}</h2>
            <p>{{ __('Simpan nomor pendaftaran berikut untuk mengecek status seleksi:') }}</p>
            <p class="reg-number" data-testid="reg-number">{{ $regNumber }}</p>
            <p class="hint">
                {{ __('Status pendaftaran dapat dicek kapan saja menggunakan nomor ini dan tanggal lahir calon siswa.') }}
            </p>
            <a class="btn btn--secondary" href="{{ route('ppdb.check-status') }}" wire:navigate>
                {{ __('Cek Status Pendaftaran') }}
            </a>
        </div>
    @endif

    {{-- API 4.7 — GET /ppdb/{schoolCode}/info. --}}
    <div class="card">
        <h2>{{ __('Informasi Cabang') }}</h2>
        <dl class="detail">
            <div>
                <dt>{{ __('Kode Cabang') }}</dt>
                <dd>{{ $school->code }}</dd>
            </div>
            @if ($academicYear)
                <div>
                    <dt>{{ __('Tahun Ajaran') }}</dt>
                    <dd>{{ $academicYear->name }}</dd>
                </div>
            @endif
            @if ($school->address)
                <div>
                    <dt>{{ __('Alamat') }}</dt>
                    <dd>{{ $school->address }}</dd>
                </div>
            @endif
            @if ($school->phone)
                <div>
                    <dt>{{ __('Telepon') }}</dt>
                    <dd>{{ $school->phone }}</dd>
                </div>
            @endif
            @if ($school->email)
                <div>
                    <dt>{{ __('Email') }}</dt>
                    <dd>{{ $school->email }}</dd>
                </div>
            @endif
        </dl>
    </div>

    <div class="card">
        <h2>{{ __('Formulir Pendaftaran') }}</h2>

        <form wire:submit="submit">
            <div class="field">
                <label for="full_name">{{ __('Nama Lengkap') }} *</label>
                <input type="text" id="full_name" wire:model="full_name" maxlength="150">
                @error('full_name') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="grid">
                <div class="field">
                    <label for="gender">{{ __('Jenis Kelamin') }} *</label>
                    <select id="gender" wire:model="gender">
                        <option value="">{{ __('— Pilih —') }}</option>
                        @foreach ($genders as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('gender') <div class="error">{{ $message }}</div> @enderror
                </div>

                <div class="field">
                    <label for="birth_date">{{ __('Tanggal Lahir') }} *</label>
                    <input type="date" id="birth_date" wire:model="birth_date">
                    @error('birth_date') <div class="error">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="field">
                <label for="origin_school">{{ __('Asal Sekolah') }}</label>
                <input type="text" id="origin_school" wire:model="origin_school" maxlength="150"
                       placeholder="{{ __('SMP/MTs asal') }}">
                @error('origin_school') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="field">
                <label for="parent_name">{{ __('Nama Orang Tua / Wali') }} *</label>
                <input type="text" id="parent_name" wire:model="parent_name" maxlength="150">
                @error('parent_name') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="grid">
                <div class="field">
                    <label for="parent_phone">{{ __('No. HP Orang Tua') }} *</label>
                    <input type="tel" id="parent_phone" wire:model="parent_phone" maxlength="20"
                           placeholder="08xxxxxxxxxx">
                    <div class="hint">{{ __('Nomor ini dipakai sekolah untuk mengirim informasi seleksi via WhatsApp.') }}</div>
                    @error('parent_phone') <div class="error">{{ $message }}</div> @enderror
                </div>

                <div class="field">
                    <label for="parent_email">{{ __('Email Orang Tua') }}</label>
                    <input type="email" id="parent_email" wire:model="parent_email" maxlength="150">
                    @error('parent_email') <div class="error">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="field">
                <label for="documents">{{ __('Berkas Pendukung') }}</label>
                <input type="file" id="documents" wire:model="documents" multiple accept=".jpg,.jpeg,.png,.pdf">
                <div class="hint">{{ __('Opsional. JPG, PNG, atau PDF; maksimal 2 MB per berkas, hingga 5 berkas.') }}</div>
                @error('documents') <div class="error">{{ $message }}</div> @enderror
                @error('documents.*') <div class="error">{{ $message }}</div> @enderror
            </div>

            <button type="submit" class="btn" wire:loading.attr="disabled">{{ __('Kirim Pendaftaran') }}</button>
            <span wire:loading wire:target="documents" class="hint">{{ __('Mengunggah berkas…') }}</span>
        </form>
    </div>

    <p class="nav-links">
        <a href="{{ route('ppdb.check-status') }}" wire:navigate>{{ __('Cek status pendaftaran') }}</a>
        &middot;
        <a href="{{ route('ppdb.schools') }}" wire:navigate>{{ __('Daftar cabang lain') }}</a>
    </p>
</div>
