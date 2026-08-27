<div>
    @if ($result === null)
        @include('livewire.student.partials.unlinked')
    @else
        <div class="portal-card" style="margin-bottom:1rem;">
            <div class="portal-label">Hasil Ujian</div>
            <div style="font-size:1.25rem;font-weight:700;overflow-wrap:anywhere;">
                {{ $result['title'] }}
            </div>
            <div class="portal-muted">
                {{ $result['subject_name'] ?? 'Mata pelajaran' }}
                @if ($result['class_name'])
                    — Kelas {{ $result['class_name'] }}
                @endif
            </div>
        </div>

        <div class="portal-card" style="margin-bottom:1rem;text-align:center;">
            <div class="portal-label">Nilai</div>
            <div class="portal-metric" style="font-size:2.5rem;">
                {{ $result['score'] === null ? '—' : number_format($result['score'], 2, ',', '.') }}
            </div>
            <div class="portal-muted">dari 100</div>
        </div>

        <div class="portal-card">
            <ul class="portal-list">
                <li>
                    <span class="portal-muted">Status</span>
                    <span class="portal-badge portal-badge--success">Sudah dikumpulkan</span>
                </li>
                <li>
                    <span class="portal-muted">Dikumpulkan</span>
                    <span>{{ $result['submitted_at']?->translatedFormat('d F Y H:i') ?? '—' }}</span>
                </li>
                <li>
                    <span class="portal-muted">Dimulai</span>
                    <span>{{ $result['started_at']?->translatedFormat('d F Y H:i') ?? '—' }}</span>
                </li>
                <li>
                    <span class="portal-muted">Durasi ujian</span>
                    <span>{{ $result['duration_minutes'] }} menit</span>
                </li>
            </ul>

            {{--
                Kunci jawaban per soal sengaja tidak ditampilkan. Selama jendela
                ujian masih terbuka, siswa yang mengumpulkan lebih awal akan
                menjadi sumber kunci bagi teman-temannya (butir 318).
            --}}
            <p class="portal-muted" style="margin:1rem 0 0;">
                Pembahasan per soal tidak ditampilkan di sini. Tanyakan kepada guru
                mata pelajaran Anda bila ingin membahas jawabannya.
            </p>

            <a
                href="{{ route('student.exams') }}"
                class="portal-child-button"
                style="text-decoration:none;display:inline-flex;align-items:center;margin-top:1rem;"
            >Kembali ke Daftar Ujian</a>
        </div>
    @endif
</div>
