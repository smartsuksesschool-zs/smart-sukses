<div>
    <div class="portal-card" style="margin-bottom:1rem;">
        <div class="portal-label">Dasbor Kerja</div>
        <div style="font-size:1.25rem;font-weight:700;">{{ $data['teacher']->name }}</div>
        <div class="portal-muted">
            {{ $data['teacher']->primaryRole()?->label() }}
            @if ($data['academic_year'])
                — Tahun ajaran {{ $data['academic_year']->name }}
            @else
                — belum ada tahun ajaran aktif
            @endif
            @if ($data['homeroom_class'])
                · Wali kelas {{ $data['homeroom_class']->name }}
            @endif
        </div>
    </div>

    {{-- PORTAL-02 poin 1 — jadwal hari ini tampil di halaman utama. --}}
    <div class="portal-card" style="margin-bottom:1rem;">
        <div class="portal-label" style="margin-bottom:.5rem;">
            Jadwal Hari Ini — {{ $data['today']['day_label'] }}
        </div>

        @if (empty($data['today_schedule']))
            <p class="portal-muted" style="margin:0;">Tidak ada jadwal mengajar hari ini.</p>
        @else
            <ul class="portal-list">
                @foreach ($data['today_schedule'] as $lesson)
                    <li>
                        <span>
                            <strong>{{ $lesson['start_time'] }}–{{ $lesson['end_time'] }}</strong>
                            {{ $lesson['subject_name'] }}
                            <div class="portal-muted">
                                Kelas {{ $lesson['class_name'] }}
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
        <div class="portal-label" style="margin-bottom:.5rem;">Kelas Aktif</div>

        @if (empty($data['active_classes']))
            <p class="portal-muted" style="margin:0;">
                @if ($data['academic_year'] === null)
                    Belum ada tahun ajaran aktif di sekolah ini.
                @else
                    Belum ada kelas yang Anda ampu pada tahun ajaran ini.
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
                        <span class="portal-muted">{{ $row['student_count'] }} siswa</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    {{-- PORTAL-02 poin 2 — tiga pintasan. --}}
    <div class="portal-grid portal-grid--3" style="margin-bottom:1rem;">
        <a href="{{ \App\Filament\Pages\InputNilai::getUrl() }}" class="portal-card" style="text-decoration:none;color:inherit;">
            <div style="font-weight:700;">Input Nilai</div>
            <div class="portal-muted">Masukkan nilai kelas yang Anda ampu.</div>
        </a>

        <a href="{{ route('teacher.classes') }}" class="portal-card" style="text-decoration:none;color:inherit;">
            <div style="font-weight:700;">Daftar Siswa Kelas</div>
            <div class="portal-muted">Lihat siswa aktif per kelas ajar.</div>
        </a>

        {{--
            Pintasan ketiga sengaja tidak dapat diklik. PORTAL-02 memintanya,
            tetapi NOTIF-01 memberi pembuatan pengumuman kepada Admin Sekolah
            dan matriks 1.1.2 menandai GURU/WALI ❌ pada "Notifikasi (buat)".
            Yang spesifik dan bersifat kewenangan menang, jadi tidak ada tautan
            ke halaman yang memang tidak boleh dibukanya (butir 175).
        --}}
        <div class="portal-card" aria-disabled="true" style="opacity:.6;">
            <div style="font-weight:700;">Buat Pengumuman</div>
            <div class="portal-muted">
                Belum tersedia — pembuatan pengumuman adalah kewenangan Admin Sekolah.
            </div>
        </div>
    </div>

    {{--
        API 4.11 menyebut "notifikasi masuk" pada dasbor guru; modulnya milik
        Sprint 8 dan belum ada. Keadaannya dinyatakan apa adanya, bukan
        ditampilkan sebagai angka nol (butir 175).
    --}}
    <div class="portal-card">
        <div class="portal-label">Notifikasi Masuk</div>
        <div style="font-size:1rem;font-weight:600;margin-top:.25rem;">
            Notifikasi belum tersedia
        </div>
        <div class="portal-muted">Modul notifikasi belum aktif pada tahap ini.</div>
    </div>
</div>
