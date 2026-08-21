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
            <div class="portal-label">Nilai</div>
            <div style="font-size:1.25rem;font-weight:700;">{{ $child->full_name }}</div>
            <div class="portal-muted">
                @if ($data['academic_year'])
                    Tahun ajaran {{ $data['academic_year']->name }}
                @else
                    Belum ada tahun ajaran aktif
                @endif
                @if ($child->activeStudentClass?->schoolClass)
                    — Kelas {{ $child->activeStudentClass->schoolClass->name }}
                @endif
            </div>
        </div>

        {{-- NILAI-04 poin 2 & 3 — rapor hanya setelah diterbitkan. --}}
        @if ($data['report_card'])
            <div class="portal-card" style="margin-bottom:1rem;">
                <div class="portal-label">Rapor Final</div>
                <div class="portal-muted" style="margin-bottom:.5rem;">
                    Diterbitkan
                    {{ $data['report_card']->published_at?->translatedFormat('d F Y') }}
                    @if ($data['report_card']->averageScore() !== null)
                        — rata-rata {{ number_format($data['report_card']->averageScore(), 2, ',', '.') }}
                    @endif
                </div>
                <a
                    href="{{ route('portal.report-card', [
                        'studentId' => $child->id,
                        'reportCardId' => $data['report_card']->id,
                    ]) }}"
                    class="portal-child-button"
                    style="text-decoration:none;display:inline-flex;align-items:center;"
                >Unduh Rapor (PDF)</a>
            </div>
        @endif

        @if ($data['academic_year'] === null)
            <div class="portal-card">
                <p class="portal-muted" style="margin:0;">
                    Belum ada tahun ajaran aktif di sekolah ini, sehingga nilai belum dapat ditampilkan.
                </p>
            </div>
        @elseif (empty($data['subjects']))
            <div class="portal-card">
                <p class="portal-muted" style="margin:0;">
                    Belum ada nilai pada tahun ajaran yang sedang berjalan.
                </p>
            </div>
        @else
            {{--
                Satu kartu per mata pelajaran, bukan satu tabel lebar. Pada
                layar kecil kartu menumpuk dan tetap terbaca; rincian
                komponennya yang dapat menggulung, bukan seluruh halaman.
            --}}
            <div class="portal-grid">
                @foreach ($data['subjects'] as $subject)
                    <div class="portal-card">
                        <div style="display:flex;justify-content:space-between;gap:1rem;align-items:baseline;">
                            <div>
                                <div style="font-weight:700;">{{ $subject['subject_name'] }}</div>
                                <div class="portal-muted">{{ $subject['subject_code'] }}</div>
                            </div>
                            <div style="text-align:right;">
                                <div class="portal-label">Nilai Akhir</div>
                                <div style="font-size:1.25rem;font-weight:700;">
                                    @if ($subject['final_score'] === null)
                                        <span class="portal-muted" style="font-size:.9375rem;">belum lengkap</span>
                                    @else
                                        {{ number_format($subject['final_score'], 2, ',', '.') }}
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="portal-scroll" style="margin-top:.75rem;">
                            <table class="portal-table">
                                <thead>
                                    <tr>
                                        <th>Penilaian</th>
                                        <th>Sifat</th>
                                        <th style="text-align:right;">Nilai</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($subject['components'] as $component)
                                        <tr>
                                            <td>
                                                {{ $component['grade_type_label'] }}
                                                @if ($component['description'])
                                                    <div class="portal-muted">{{ $component['description'] }}</div>
                                                @endif
                                            </td>
                                            <td>
                                                <span class="portal-badge portal-badge--muted">
                                                    {{ $component['assessment_type_label'] ?? '—' }}
                                                </span>
                                            </td>
                                            <td style="text-align:right;">
                                                {{ $component['score'] === null
                                                    ? '—'
                                                    : number_format($component['score'], 2, ',', '.') }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    @endif
</div>
