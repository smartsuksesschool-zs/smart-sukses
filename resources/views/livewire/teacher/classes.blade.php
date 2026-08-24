<div>
    <div class="portal-card" style="margin-bottom:1rem;">
        <div class="portal-label">Kelas Ajar</div>
        <div class="portal-muted">Kelas yang Anda ampu pada tahun ajaran aktif.</div>
    </div>

    @if (empty($classes))
        <div class="portal-card">
            <p class="portal-muted" style="margin:0;">
                Belum ada kelas yang Anda ampu pada tahun ajaran yang sedang berjalan.
            </p>
        </div>
    @else
        <div class="portal-grid portal-grid--2">
            @foreach ($classes as $row)
                <a
                    href="{{ route('teacher.class-students', ['classId' => $row['class']['id']]) }}"
                    class="portal-card"
                    style="text-decoration:none;color:inherit;"
                >
                    <div style="font-weight:700;font-size:1.125rem;">{{ $row['class']['name'] }}</div>
                    <div class="portal-muted">
                        @if ($row['class']['grade_level'])
                            Tingkat {{ $row['class']['grade_level'] }}
                        @endif
                        @if ($row['class']['room'])
                            — Ruang {{ $row['class']['room'] }}
                        @endif
                    </div>
                    <div style="margin-top:.5rem;">
                        {{ collect($row['subjects'])->pluck('name')->join(', ') }}
                    </div>
                    <div class="portal-muted" style="margin-top:.25rem;">
                        {{ $row['student_count'] }} siswa aktif
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</div>
