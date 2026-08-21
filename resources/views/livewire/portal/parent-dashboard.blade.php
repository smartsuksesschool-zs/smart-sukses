<div>
    @if ($children->isEmpty())
        {{--
            Akun orang tua yang belum ditautkan ke satu pun siswa. Bukan
            kesalahan — penautannya dilakukan admin sekolah lewat data siswa.
        --}}
        <div class="portal-card">
            <h1 style="margin-top:0;font-size:1.25rem;">Belum ada data anak</h1>
            <p class="portal-muted" style="margin-bottom:0;">
                Akun Anda belum terhubung dengan data siswa mana pun. Silakan hubungi
                administrasi sekolah untuk menautkannya.
            </p>
        </div>
    @else
        @include('livewire.portal.partials.child-switcher')

        @if ($summary)
            @php($child = $summary['child'])

            <div class="portal-card" style="margin-bottom:1rem;">
                <div class="portal-label">Profil Anak</div>
                <div style="font-size:1.25rem;font-weight:700;">{{ $child->full_name }}</div>
                <div class="portal-muted">
                    NIS {{ $child->nis }}
                    @if ($child->activeStudentClass?->schoolClass)
                        — Kelas {{ $child->activeStudentClass->schoolClass->name }}
                        @if ($child->activeStudentClass->academicYear)
                            ({{ $child->activeStudentClass->academicYear->name }})
                        @endif
                    @else
                        — belum terdaftar di kelas pada tahun ajaran aktif
                    @endif
                </div>
            </div>

            <div class="portal-grid portal-grid--2" style="margin-bottom:1rem;">
                {{-- PORTAL-01 poin 1 — tagihan belum lunas: jumlah & nominal. --}}
                <div class="portal-card">
                    <div class="portal-label">Tagihan Belum Lunas</div>
                    <div class="portal-metric">
                        Rp {{ number_format((float) $summary['pending_fees']['outstanding_amount'], 0, ',', '.') }}
                    </div>
                    <div class="portal-muted">
                        {{ $summary['pending_fees']['count'] }} tagihan menunggu pembayaran
                    </div>
                </div>

                {{--
                    "Kehadiran bulan ini" diminta PORTAL-01, tetapi Phase 1 tidak
                    punya sumber datanya sama sekali — presensi digital adalah
                    fitur Phase 2. Yang ditampilkan karena itu keadaan sebenarnya,
                    bukan angka nol yang terbaca seolah anak tidak pernah hadir
                    (butir 152).
                --}}
                <div class="portal-card">
                    <div class="portal-label">Kehadiran Bulan Ini</div>
                    @if ($summary['attendance']['available'])
                        <div class="portal-metric">{{ $summary['attendance']['present_count'] }}</div>
                    @else
                        <div style="font-size:1rem;font-weight:600;margin-top:.25rem;">
                            Data kehadiran belum tersedia
                        </div>
                        <div class="portal-muted">
                            Pencatatan presensi belum tersedia pada tahap ini.
                        </div>
                    @endif
                </div>
            </div>

            {{-- PORTAL-01 poin 1 — 3 nilai terbaru. --}}
            <div class="portal-card">
                <div class="portal-label" style="margin-bottom:.5rem;">Nilai Terbaru</div>

                @if (empty($grades))
                    <p class="portal-muted" style="margin:0;">
                        Belum ada nilai pada tahun ajaran yang sedang berjalan.
                    </p>
                @else
                    <ul class="portal-list">
                        @foreach ($grades as $grade)
                            <li>
                                <span>{{ $grade['subject_name'] }}</span>
                                <strong>
                                    @if ($grade['score'] === null)
                                        <span class="portal-muted">belum lengkap</span>
                                    @else
                                        {{ number_format($grade['score'], 2, ',', '.') }}
                                    @endif
                                </strong>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif
    @endif
</div>
