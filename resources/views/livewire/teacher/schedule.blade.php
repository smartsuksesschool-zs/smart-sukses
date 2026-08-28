<div>
    <div class="portal-card" style="margin-bottom:1rem;">
        <div class="portal-label">{{ __('Jadwal Mengajar') }}</div>
        <div class="portal-muted">{{ __('Minggu berjalan, hanya jadwal Anda sendiri.') }}</div>
    </div>

    @if ($week->isEmpty())
        <div class="portal-card">
            <p class="portal-muted" style="margin:0;">{{ __('Belum ada jadwal mengajar untuk Anda.') }}</p>
        </div>
    @else
        <div class="portal-card">
            @foreach ($week as $day => $lessons)
                <div style="margin-bottom:1rem;">
                    <div style="font-weight:700;margin-bottom:.25rem;">
                        {{ $lessons->first()['day_label'] }}
                    </div>
                    <ul class="portal-list">
                        @foreach ($lessons as $lesson)
                            <li>
                                <span>
                                    {{ $lesson['start_time'] }}–{{ $lesson['end_time'] }}
                                    <strong>{{ $lesson['subject_name'] }}</strong>
                                    <div class="portal-muted">
                                        {{ __('Kelas') }} {{ $lesson['class_name'] }}
                                        @if ($lesson['room'])
                                            — {{ $lesson['room'] }}
                                        @endif
                                    </div>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    @endif
</div>
