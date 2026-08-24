<div>
    @if ($student === null)
        @include('livewire.student.partials.unlinked')
    @else
        <div class="portal-card" style="margin-bottom:1rem;">
            <div class="portal-label">Profil</div>
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
                <li><span class="portal-muted">Nama Lengkap</span><strong>{{ $student->full_name }}</strong></li>
                <li><span class="portal-muted">NIS</span><strong>{{ $student->nis }}</strong></li>
                <li><span class="portal-muted">NISN</span><strong>{{ $student->nisn ?? '—' }}</strong></li>
                <li>
                    <span class="portal-muted">Kelas Aktif</span>
                    <strong>{{ $currentClass?->name ?? 'Belum terdaftar' }}</strong>
                </li>
                <li>
                    <span class="portal-muted">Tahun Ajaran</span>
                    <strong>{{ $academicYear?->name ?? 'Belum ada tahun ajaran aktif' }}</strong>
                </li>
                <li>
                    <span class="portal-muted">Status</span>
                    <strong>{{ $student->status?->label() ?? '—' }}</strong>
                </li>
                <li><span class="portal-muted">Foto</span>
                    <strong>{{ filled($student->photo_url) ? 'Tersedia' : 'Belum ada' }}</strong>
                </li>
            </ul>

            {{--
                Mengubah profil dan kata sandi belum punya jalurnya di repo ini
                (butir 187).
            --}}
            <p class="portal-muted" style="margin:.75rem 0 0;">
                Perubahan data profil dilakukan melalui administrasi sekolah.
            </p>
        </div>
    @endif
</div>
