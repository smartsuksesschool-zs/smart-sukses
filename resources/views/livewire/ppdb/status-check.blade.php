{{-- PPDB-02 — halaman cek status pendaftaran, dapat diakses publik. --}}
<div>
    <div class="card">
        <h2>{{ __('Cek Status Pendaftaran') }}</h2>
        <p class="hint">{{ __('Masukkan nomor pendaftaran dan tanggal lahir calon siswa.') }}</p>

        <form wire:submit="check">
            <div class="field">
                <label for="regNumber">{{ __('Nomor Pendaftaran') }}</label>
                <input type="text" id="regNumber" wire:model="regNumber" placeholder="MADANI-2026-0001" maxlength="20">
                @error('regNumber') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="field">
                <label for="birthDate">{{ __('Tanggal Lahir') }}</label>
                <input type="date" id="birthDate" wire:model="birthDate">
                @error('birthDate') <div class="error">{{ $message }}</div> @enderror
            </div>

            <button type="submit" class="btn" wire:loading.attr="disabled">{{ __('Cek Status') }}</button>
        </form>
    </div>

    @if ($result)
        <div class="card">
            <h2>{{ __('Hasil') }}</h2>

            <dl class="detail">
                <div>
                    <dt>{{ __('Nomor Pendaftaran') }}</dt>
                    <dd>{{ $result['reg_number'] }}</dd>
                </div>
                <div>
                    <dt>{{ __('Nama Calon Siswa') }}</dt>
                    <dd>{{ $result['full_name'] }}</dd>
                </div>
                <div>
                    <dt>{{ __('Cabang') }}</dt>
                    <dd>{{ $result['school_name'] }}</dd>
                </div>
                <div>
                    <dt>{{ __('Waktu Daftar') }}</dt>
                    <dd>{{ $result['registered_at'] }}</dd>
                </div>
                <div>
                    <dt>{{ __('Status') }}</dt>
                    <dd><span class="badge" data-status="{{ $result['status'] }}">{{ $result['status_label'] }}</span></dd>
                </div>
            </dl>

            @if ($result['status_notes'])
                <p class="hint" style="margin-top:1rem;"><strong>{{ __('Catatan') }}:</strong> {{ $result['status_notes'] }}</p>
            @endif
        </div>
    @endif

    <p class="nav-links">
        <a href="{{ route('ppdb.schools') }}" wire:navigate>{{ __('Kembali ke daftar cabang') }}</a>
    </p>
</div>
