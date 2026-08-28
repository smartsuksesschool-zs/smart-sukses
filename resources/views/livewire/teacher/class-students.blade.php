<div>
    <div class="portal-card" style="margin-bottom:1rem;">
        <div class="portal-label">{{ __('Daftar Siswa') }}</div>
        <div style="font-size:1.25rem;font-weight:700;">{{ __('Kelas') }} {{ $schoolClass->name }}</div>
        <div class="portal-muted">
            {{ __(':count siswa aktif', ['count' => $students->count()]) }} ·
            <a href="{{ route('teacher.classes') }}">{{ __('kembali ke kelas ajar') }}</a>
        </div>
    </div>

    @if ($students->isEmpty())
        <div class="portal-card">
            <p class="portal-muted" style="margin:0;">
                {{ __('Belum ada siswa aktif di kelas ini pada tahun ajaran yang berjalan.') }}
            </p>
        </div>
    @else
        {{-- SIS-04 poin 1 — hanya siswa aktif. --}}
        <div class="portal-card">
            <ul class="portal-list">
                @foreach ($students as $student)
                    <li>
                        <span>
                            <strong>{{ $student->full_name }}</strong>
                            <div class="portal-muted">NIS {{ $student->nis }}</div>
                        </span>
                        {{--
                            SIS-04 poin 2 juga meminta "status kehadiran hari
                            ini". Sumbernya belum ada di Phase 1 — presensi
                            digital adalah fitur Phase 2 — jadi yang ditampilkan
                            keadaan sebenarnya, bukan "Hadir" maupun angka nol
                            (butir 152).
                        --}}
                        <span class="portal-badge portal-badge--muted">{{ __('Kehadiran belum tersedia') }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
