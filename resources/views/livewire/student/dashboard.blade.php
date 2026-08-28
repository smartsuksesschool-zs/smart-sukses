<div>
    @if ($data === null)
        @include('livewire.student.partials.unlinked')
    @else
        <div class="portal-card" style="margin-bottom:1rem;">
            <div class="portal-label">{{ __('Beranda') }}</div>
            <div style="font-size:1.25rem;font-weight:700;">{{ $data['student']->full_name }}</div>
            <div class="portal-muted">
                NIS {{ $data['student']->nis }}
                @if ($data['current_class'])
                    — {{ __('Kelas') }} {{ $data['current_class']->name }}
                @else
                    — {{ __('belum terdaftar di kelas pada tahun ajaran aktif') }}
                @endif
                @if ($data['academic_year'])
                    · {{ $data['academic_year']->name }}
                @endif
            </div>
        </div>

        {{-- API 4.11 — jadwal hari ini. --}}
        <div class="portal-card" style="margin-bottom:1rem;">
            <div class="portal-label" style="margin-bottom:.5rem;">
                {{ __('Jadwal Hari Ini') }} — {{ $data['today']['day_label'] }}
            </div>

            @if (empty($data['today_schedule']))
                <p class="portal-muted" style="margin:0;">{{ __('Tidak ada pelajaran hari ini.') }}</p>
            @else
                <ul class="portal-list">
                    @foreach ($data['today_schedule'] as $lesson)
                        <li>
                            <span>
                                <strong>{{ $lesson['start_time'] }}–{{ $lesson['end_time'] }}</strong>
                                {{ $lesson['subject_name'] }}
                                <div class="portal-muted">
                                    {{ $lesson['teacher_name'] ?? __('Guru belum ditentukan') }}
                                    @if ($lesson['room'])
                                        — {{ $lesson['room'] }}
                                    @endif
                                </div>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        {{-- API 4.11 — 5 nilai terbaru, satu entri per mata pelajaran. --}}
        <div class="portal-card" style="margin-bottom:1rem;">
            <div class="portal-label" style="margin-bottom:.5rem;">{{ __('Nilai Terbaru') }}</div>

            @if (empty($data['latest_grades']))
                <p class="portal-muted" style="margin:0;">
                    {{ __('Belum ada nilai pada tahun ajaran yang sedang berjalan.') }}
                </p>
            @else
                <ul class="portal-list">
                    @foreach ($data['latest_grades'] as $subject)
                        <li>
                            <span>{{ $subject['subject_name'] }}</span>
                            <strong>
                                @if ($subject['final_score'] === null)
                                    <span class="portal-muted">{{ __('belum lengkap') }}</span>
                                @else
                                    {{ number_format($subject['final_score'], 2, ',', '.') }}
                                @endif
                            </strong>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        {{--
            PORTAL-03 poin 3 — "Notifikasi dari sekolah tampil dengan
            timestamp". Sejak Batch 8.2 isinya nyata (butir 214).
        --}}
        <x-portal.notification-summary
            :notifications="$data['notifications']"
            :route="route('student.notifications')"
        />
    @endif
</div>
