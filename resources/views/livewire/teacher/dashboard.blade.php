<div>
    <div class="portal-card" style="margin-bottom:1rem;">
        <div class="portal-label">{{ __('Dasbor Kerja') }}</div>
        <div style="font-size:1.25rem;font-weight:700;">{{ $data['teacher']->name }}</div>
        <div class="portal-muted">
            {{ $data['teacher']->primaryRole()?->label() }}
            @if ($data['academic_year'])
                — {{ __('Tahun Ajaran') }} {{ $data['academic_year']->name }}
            @else
                — {{ __('belum ada tahun ajaran aktif') }}
            @endif
            @if ($data['homeroom_class'])
                · {{ __('Wali Kelas') }} {{ $data['homeroom_class']->name }}
            @endif
        </div>
    </div>

    {{-- PORTAL-02 poin 1 — jadwal hari ini tampil di halaman utama. --}}
    <div class="portal-card" style="margin-bottom:1rem;">
        <div class="portal-label" style="margin-bottom:.5rem;">
            {{ __('Jadwal Hari Ini') }} — {{ $data['today']['day_label'] }}
        </div>

        @if (empty($data['today_schedule']))
            <p class="portal-muted" style="margin:0;">{{ __('Tidak ada jadwal mengajar hari ini.') }}</p>
        @else
            <ul class="portal-list">
                @foreach ($data['today_schedule'] as $lesson)
                    <li>
                        <span>
                            <strong>{{ $lesson['start_time'] }}–{{ $lesson['end_time'] }}</strong>
                            {{ $lesson['subject_name'] }}
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
        @endif
    </div>

    {{-- PORTAL-02 — kelas yang aktif. --}}
    <div class="portal-card" style="margin-bottom:1rem;">
        <div class="portal-label" style="margin-bottom:.5rem;">{{ __('Kelas Aktif') }}</div>

        @if (empty($data['active_classes']))
            <p class="portal-muted" style="margin:0;">
                @if ($data['academic_year'] === null)
                    {{ __('Belum ada tahun ajaran aktif di sekolah ini.') }}
                @else
                    {{ __('Belum ada kelas yang Anda ampu pada tahun ajaran ini.') }}
                @endif
            </p>
        @else
            <ul class="portal-list">
                @foreach ($data['active_classes'] as $row)
                    <li>
                        <span>
                            <a href="{{ route('teacher.class-students', ['classId' => $row['class']['id']]) }}">
                                <strong>{{ $row['class']['name'] }}</strong>
                            </a>
                            <div class="portal-muted">
                                {{ collect($row['subjects'])->pluck('name')->join(', ') }}
                            </div>
                        </span>
                        <span class="portal-muted">{{ __(':count siswa', ['count' => $row['student_count']]) }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    {{-- PORTAL-02 poin 2 — tiga pintasan. --}}
    <div class="portal-grid portal-grid--3" style="margin-bottom:1rem;">
        <a href="{{ \App\Filament\Pages\InputNilai::getUrl() }}" class="portal-card" style="text-decoration:none;color:inherit;">
            <div style="font-weight:700;">{{ __('Input Nilai') }}</div>
            <div class="portal-muted">{{ __('Masukkan nilai kelas yang Anda ampu.') }}</div>
        </a>

        <a href="{{ route('teacher.classes') }}" class="portal-card" style="text-decoration:none;color:inherit;">
            <div style="font-weight:700;">{{ __('Daftar Siswa Kelas') }}</div>
            <div class="portal-muted">{{ __('Lihat siswa aktif per kelas ajar.') }}</div>
        </a>

        {{--
            Pintasan ketiga sengaja tidak dapat diklik. PORTAL-02 memintanya,
            tetapi NOTIF-01 memberi pembuatan pengumuman kepada Admin Sekolah
            dan matriks 1.1.2 menandai GURU/WALI ❌ pada "Notifikasi (buat)".
            Yang spesifik dan bersifat kewenangan menang, jadi tidak ada tautan
            ke halaman yang memang tidak boleh dibukanya (butir 175).
        --}}
        <div class="portal-card" aria-disabled="true" style="opacity:.6;">
            <div style="font-weight:700;">{{ __('Buat Pengumuman') }}</div>
            <div class="portal-muted">
                {{ __('Belum tersedia — pembuatan pengumuman adalah kewenangan Admin Sekolah.') }}
            </div>
        </div>
    </div>

    {{--
        API 4.11 — "notifikasi masuk". Sejak Batch 8.2 isinya nyata, dan daftar
        penuhnya ada di halaman Notifikasi (butir 214).
    --}}
    <x-portal.notification-summary
        :notifications="$data['notifications']"
        :route="route('teacher.notifications')"
        :label="__('Notifikasi Masuk')"
    />
</div>
