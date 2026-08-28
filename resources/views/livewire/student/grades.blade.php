<div>
    @if ($data === null)
        @include('livewire.student.partials.unlinked')
    @else
        <div class="portal-card" style="margin-bottom:1rem;">
            <div class="portal-label">{{ __('Nilai') }}</div>
            <div style="font-size:1.25rem;font-weight:700;">{{ $data['student']->full_name }}</div>
            <div class="portal-muted">
                @if ($data['academic_year'])
                    {{ $data['academic_year']->name }}
                    {{-- PORTAL-03 poin 2 — "per semester", dari ERD (butir 184). --}}
                    · {{ __('Semester') }} {{ $data['academic_year']->semester }}
                @else
                    {{ __('Belum ada tahun ajaran aktif') }}
                @endif
                @if ($data['current_class'])
                    — {{ __('Kelas') }} {{ $data['current_class']->name }}
                @endif
            </div>
        </div>

        {{-- NILAI-04 poin 2 & 3 — rapor final hanya setelah diterbitkan. --}}
        @if ($data['report_card'])
            <div class="portal-card" style="margin-bottom:1rem;">
                <div class="portal-label">{{ __('Rapor Final') }}</div>
                <div class="portal-muted" style="margin-bottom:.5rem;">
                    {{ __('Diterbitkan') }} {{ $data['report_card']->published_at?->translatedFormat('d F Y') }}
                    @if ($data['report_card']->averageScore() !== null)
                        — {{ __('Rata-rata') }} {{ number_format($data['report_card']->averageScore(), 2, ',', '.') }}
                    @endif
                </div>
                <a
                    href="{{ route('student.report-card', ['reportCardId' => $data['report_card']->id]) }}"
                    class="portal-child-button"
                    style="text-decoration:none;display:inline-flex;align-items:center;"
                >{{ __('Unduh Rapor (PDF)') }}</a>
            </div>
        @endif

        @if ($data['academic_year'] === null)
            <div class="portal-card">
                <p class="portal-muted" style="margin:0;">
                    {{ __('Belum ada tahun ajaran aktif di sekolah ini, sehingga nilai belum dapat ditampilkan.') }}
                </p>
            </div>
        @elseif (empty($data['subjects']))
            <div class="portal-card">
                <p class="portal-muted" style="margin:0;">
                    {{ __('Belum ada nilai pada tahun ajaran yang sedang berjalan.') }}
                </p>
            </div>
        @else
            {{--
                Satu kartu per mata pelajaran, bukan satu tabel selebar halaman.
                Rincian komponennya yang menggulung bila sempit, bukan seluruh
                halamannya.
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
                                <div class="portal-label">{{ __('Nilai Akhir') }}</div>
                                <div style="font-size:1.25rem;font-weight:700;">
                                    @if ($subject['final_score'] === null)
                                        <span class="portal-muted" style="font-size:.9375rem;">{{ __('belum lengkap') }}</span>
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
                                        <th>{{ __('Komponen') }}</th>
                                        <th>{{ __('Sifat') }}</th>
                                        <th style="text-align:right;">{{ __('Nilai') }}</th>
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
