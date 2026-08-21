<div>
    @include('livewire.portal.partials.child-switcher')

    @if ($data === null)
        <div class="portal-card">
            <h1 style="margin-top:0;font-size:1.25rem;">Belum ada data anak</h1>
            <p class="portal-muted" style="margin-bottom:0;">
                Akun Anda belum terhubung dengan data siswa mana pun.
            </p>
        </div>
    @else
        @php($child = $data['child'])

        <div class="portal-card" style="margin-bottom:1rem;">
            <div class="portal-label">Jadwal Pelajaran</div>
            <div style="font-size:1.25rem;font-weight:700;">{{ $child->full_name }}</div>
            <div class="portal-muted">
                @if ($data['current_class'])
                    Kelas {{ $data['current_class']->name }}
                    @if ($data['academic_year'])
                        — {{ $data['academic_year']->name }}
                    @endif
                @elseif ($data['academic_year'] === null)
                    Belum ada tahun ajaran aktif
                @else
                    Belum terdaftar di kelas pada tahun ajaran aktif
                @endif
            </div>
        </div>

        @if ($data['current_class'] === null)
            <div class="portal-card">
                <p class="portal-muted" style="margin:0;">
                    Jadwal belum tersedia karena anak belum memiliki kelas pada tahun ajaran yang berjalan.
                </p>
            </div>
        @else
            <div class="portal-card" style="margin-bottom:1rem;">
                <div class="portal-label" style="margin-bottom:.5rem;">Hari Ini</div>

                @if ($today->isEmpty())
                    <p class="portal-muted" style="margin:0;">Tidak ada pelajaran hari ini.</p>
                @else
                    <ul class="portal-list">
                        @foreach ($today as $lesson)
                            <li>
                                <span>
                                    <strong>{{ $lesson['start_time'] }}–{{ $lesson['end_time'] }}</strong>
                                    {{ $lesson['subject_name'] }}
                                    <div class="portal-muted">
                                        {{ $lesson['teacher_name'] ?? 'Guru belum ditentukan' }}
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

            <div class="portal-card">
                <div class="portal-label" style="margin-bottom:.5rem;">Jadwal Mingguan</div>

                @if ($week->isEmpty())
                    <p class="portal-muted" style="margin:0;">Belum ada jadwal untuk kelas ini.</p>
                @else
                    {{-- Sudah terurut hari lalu jam dari service. --}}
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
                                                {{ $lesson['teacher_name'] ?? 'Guru belum ditentukan' }}
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
                @endif
            </div>
        @endif
    @endif
</div>
