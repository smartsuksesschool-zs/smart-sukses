<div>
    @if ($rows === null)
        @include('livewire.student.partials.unlinked')
    @else
        <div class="portal-card" style="margin-bottom:1rem;">
            <div class="portal-label">{{ __('Ujian Online') }}</div>
            <div class="portal-muted" style="margin-top:.25rem;">
                {{ __('Ujian untuk kelas Anda pada tahun ajaran yang sedang berjalan.') }}
            </div>
        </div>

        {{-- Alasan penolakan dari halaman pengerjaan, bila siswa dikembalikan ke sini. --}}
        @if (session('exam-notice'))
            <div class="portal-card" style="margin-bottom:1rem;border-left:4px solid var(--color-danger);">
                <p style="margin:0;">{{ session('exam-notice') }}</p>
            </div>
        @endif

        @if (empty($rows))
            <div class="portal-card">
                <p class="portal-muted" style="margin:0;">
                    {{ __('Belum ada ujian online untuk kelas Anda.') }}
                </p>
            </div>
        @else
            <div class="portal-grid">
                @foreach ($rows as $row)
                    <div class="portal-card">
                        <div class="notif__top">
                            <strong style="overflow-wrap:anywhere;">{{ $row['title'] }}</strong>
                            <span class="portal-badge {{ $row['state']->badge() }}">
                                {{ $row['state']->label() }}
                            </span>
                        </div>

                        <div class="portal-muted" style="margin-top:.375rem;">
                            {{ $row['subject_name'] ?? __('Mata pelajaran') }}
                            @if ($row['class_name'])
                                — {{ __('Kelas') }} {{ $row['class_name'] }}
                            @endif
                        </div>

                        <ul class="portal-list" style="margin-top:.75rem;">
                            <li>
                                <span class="portal-muted">{{ __('Dibuka') }}</span>
                                <span>{{ $row['available_from']?->translatedFormat('d M Y H:i') ?? '—' }}</span>
                            </li>
                            <li>
                                <span class="portal-muted">{{ __('Ditutup') }}</span>
                                <span>{{ $row['available_until']?->translatedFormat('d M Y H:i') ?? '—' }}</span>
                            </li>
                            <li>
                                <span class="portal-muted">{{ __('Durasi') }}</span>
                                <span>{{ trans_choice(':count menit|:count menit', $row['duration_minutes']) }} · {{ trans_choice(':count soal|:count soal', $row['question_count']) }}</span>
                            </li>

                            @if ($row['state']->hasResult())
                                <li>
                                    <span class="portal-muted">{{ __('Nilai') }}</span>
                                    <span style="font-weight:700;">
                                        {{ $row['score'] === null ? '—' : number_format($row['score'], 2, ',', '.') }}
                                    </span>
                                </li>
                                <li>
                                    <span class="portal-muted">{{ __('Dikumpulkan') }}</span>
                                    <span>{{ $row['submitted_at']?->translatedFormat('d M Y H:i') ?? '—' }}</span>
                                </li>
                            @endif
                        </ul>

                        @if ($row['state']->isWorkable())
                            <a
                                href="{{ route('student.exam', ['examId' => $row['id']]) }}"
                                class="portal-button"
                                style="text-decoration:none;display:inline-flex;align-items:center;justify-content:center;margin-top:.75rem;"
                            >{{ $row['state']->action() }}</a>
                        @elseif ($row['state']->hasResult())
                            <a
                                href="{{ route('student.exam-result', ['examId' => $row['id']]) }}"
                                class="portal-child-button"
                                style="text-decoration:none;display:inline-flex;align-items:center;justify-content:center;margin-top:.75rem;"
                            >{{ $row['state']->action() }}</a>
                        @else
                            {{-- Belum dibuka atau sudah terlewat: keadaannya
                                 terlihat, tetapi tidak ada yang dapat ditekan. --}}
                            <p class="portal-muted" style="margin:.75rem 0 0;">
                                {{ $row['state'] === \App\Enums\StudentExamState::Upcoming
                                    ? __('Ujian ini belum dibuka.')
                                    : __('Waktu ujian ini sudah berakhir.') }}
                            </p>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    @endif
</div>
