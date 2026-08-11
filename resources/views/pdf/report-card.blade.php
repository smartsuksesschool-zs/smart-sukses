{{-- NILAI-04 poin 3 / API 4.8 — GET /report-cards/{id}/pdf. --}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Rapor {{ $reportCard->student?->full_name }}</title>
    <style>
        @page { margin: 24mm 18mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1b2130; }
        h1 { font-size: 16px; margin: 0 0 2px; }
        .school { text-align: center; border-bottom: 2px solid #1B3A6B; padding-bottom: 10px; margin-bottom: 16px; }
        .school p { margin: 0; font-size: 10px; color: #5c6474; }
        table { width: 100%; border-collapse: collapse; }
        table.identity td { padding: 2px 0; vertical-align: top; }
        table.identity td.label { width: 28%; color: #5c6474; }
        table.scores { margin-top: 14px; }
        table.scores th, table.scores td { border: 1px solid #c9cfda; padding: 6px 8px; }
        table.scores th { background: #eef1f6; text-align: left; font-size: 10px; text-transform: uppercase; }
        table.scores td.num { text-align: right; width: 90px; }
        .section-title { margin: 18px 0 6px; font-size: 12px; font-weight: bold; }
        .muted { color: #5c6474; }
        .notes { border: 1px solid #c9cfda; padding: 8px; min-height: 48px; }
        .footer { margin-top: 28px; font-size: 10px; }
        .signature { margin-top: 48px; }
        .draft { color: #b42318; font-weight: bold; }
    </style>
</head>
<body>
    <div class="school">
        <h1>{{ $reportCard->school?->name }}</h1>
        @if ($reportCard->school?->address)
            <p>{{ $reportCard->school->address }}</p>
        @endif
        <p>LAPORAN HASIL BELAJAR SISWA</p>
    </div>

    @unless ($reportCard->is_published)
        <p class="draft">DRAFT — rapor ini belum diterbitkan.</p>
    @endunless

    <table class="identity">
        <tr>
            <td class="label">Nama Siswa</td>
            <td><strong>{{ $reportCard->student?->full_name }}</strong></td>
            <td class="label">Kelas</td>
            <td>{{ $reportCard->schoolClass?->name }}</td>
        </tr>
        <tr>
            <td class="label">NIS</td>
            <td>{{ $reportCard->student?->nis }}</td>
            <td class="label">Tahun Ajaran</td>
            <td>{{ $reportCard->academicYear?->name }}</td>
        </tr>
        <tr>
            <td class="label">NISN</td>
            <td>{{ $reportCard->student?->nisn ?: '—' }}</td>
            <td class="label">Wali Kelas</td>
            <td>{{ $reportCard->schoolClass?->homeroomTeacher?->name ?: '—' }}</td>
        </tr>
    </table>

    <div class="section-title">A. Nilai Akhir Mata Pelajaran</div>

    <table class="scores">
        <thead>
            <tr>
                <th style="width:60px;">Kode</th>
                <th>Mata Pelajaran</th>
                <th class="num">Nilai Akhir</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($reportCard->final_scores ?? [] as $code => $score)
                <tr>
                    <td>{{ $code }}</td>
                    <td>{{ $subjects[$code] ?? $code }}</td>
                    <td class="num">{{ number_format((float) $score, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="muted">Belum ada nilai akhir.</td></tr>
            @endforelse
        </tbody>
        @if ($reportCard->averageScore() !== null)
            <tfoot>
                <tr>
                    <th colspan="2" style="text-align:right;">Rata-rata</th>
                    <th class="num">{{ number_format($reportCard->averageScore(), 2) }}</th>
                </tr>
            </tfoot>
        @endif
    </table>

    <div class="section-title">B. Sikap</div>
    <p>
        Predikat:
        <strong>{{ $reportCard->attitude_score?->value ?? '—' }}</strong>
        @if ($reportCard->attitude_score)
            <span class="muted">({{ $reportCard->attitude_score->label() }})</span>
        @endif
    </p>

    <div class="section-title">C. Ketidakhadiran</div>
    <table class="scores">
        <thead>
            <tr><th>Sakit</th><th>Izin</th><th>Alpa</th><th>Hadir</th></tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $reportCard->attend_sick ?? '—' }}</td>
                <td>{{ $reportCard->attend_permission ?? '—' }}</td>
                <td>{{ $reportCard->attend_absent ?? '—' }}</td>
                <td>{{ $reportCard->attend_present ?? '—' }}</td>
            </tr>
        </tbody>
    </table>

    <div class="section-title">D. Catatan Wali Kelas</div>
    <div class="notes">{{ $reportCard->homeroom_notes ?: '' }}</div>

    <div class="footer">
        <table>
            <tr>
                <td style="width:60%;"></td>
                <td>
                    {{ $reportCard->school?->name }},
                    {{ optional($reportCard->published_at)->translatedFormat('d F Y') ?: '.....................' }}<br>
                    Wali Kelas,
                    <div class="signature">
                        <strong>{{ $reportCard->publisher?->name ?: $reportCard->schoolClass?->homeroomTeacher?->name ?: '.....................' }}</strong>
                    </div>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
