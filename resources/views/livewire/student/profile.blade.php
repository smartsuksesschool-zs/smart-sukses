<div>
    @if ($student === null)
        @include('livewire.student.partials.unlinked')
    @else
        <div class="portal-card" style="margin-bottom:1rem;">
            <div class="portal-label">{{ __('Profil') }}</div>
            <div style="font-size:1.25rem;font-weight:700;">{{ $student->full_name }}</div>
            <div class="portal-muted">{{ $student->school?->name }}</div>
        </div>

        {{--
            Hanya data dirinya, dan hanya yang memang miliknya sendiri. Yang
            tidak ikut: `parent_user_id`, `school_id`, catatan administrasi, dan
            jalur foto mentah (butir 186).
        --}}
        <div class="portal-card">
            <ul class="portal-list">
                <li><span class="portal-muted">{{ __('Nama Lengkap') }}</span><strong>{{ $student->full_name }}</strong></li>
                <li><span class="portal-muted">{{ __('NIS') }}</span><strong>{{ $student->nis }}</strong></li>
                <li><span class="portal-muted">{{ __('NISN') }}</span><strong>{{ $student->nisn ?? '—' }}</strong></li>
                <li>
                    <span class="portal-muted">{{ __('Kelas Aktif') }}</span>
                    <strong>{{ $currentClass?->name ?? __('Belum terdaftar') }}</strong>
                </li>
                <li>
                    <span class="portal-muted">{{ __('Tahun Ajaran') }}</span>
                    <strong>{{ $academicYear?->name ?? __('Belum ada tahun ajaran aktif') }}</strong>
                </li>
                <li>
                    <span class="portal-muted">{{ __('Status') }}</span>
                    <strong>{{ $student->status?->label() ?? '—' }}</strong>
                </li>
                <li><span class="portal-muted">{{ __('Foto') }}</span>
                    <strong>{{ filled($student->photo_url) ? __('Tersedia') : __('Belum ada') }}</strong>
                </li>
            </ul>

            {{--
                Mengubah profil dan kata sandi belum punya jalurnya di repo ini
                (butir 187).
            --}}
            <p class="portal-muted" style="margin:.75rem 0 0;">
                {{ __('Perubahan data profil dilakukan melalui administrasi sekolah.') }}
            </p>
        </div>
    @endif
</div>
