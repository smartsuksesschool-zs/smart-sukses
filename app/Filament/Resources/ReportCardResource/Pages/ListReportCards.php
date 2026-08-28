<?php

namespace App\Filament\Resources\ReportCardResource\Pages;

use App\Enums\GradeType;
use App\Filament\Resources\ReportCardResource;
use App\Jobs\GenerateReportCardPdf;
use App\Models\ReportCard;
use App\Models\SchoolClass;
use App\Services\Grading\ReportCardGenerator;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;

/**
 * NILAI-03 / API 4.8 — POST /report-cards/generate (Wali Kelas only) dan
 * penerbitan sekelas sesuai bunyi user story "untuk semua siswa di kelas saya".
 */
class ListReportCards extends ListRecords
{
    protected static string $resource = ReportCardResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('generate')
                ->label(__('Generate Rapor Kelas'))
                ->icon('heroicon-o-sparkles')
                ->color('primary')
                ->form([
                    Forms\Components\Select::make('class_id')
                        ->label(__('Kelas'))
                        ->options(fn () => $this->classOptions())
                        ->required()
                        ->helperText(__('Draft rapor dibuat untuk seluruh siswa aktif di kelas ini.')),
                ])
                ->action(function (array $data): void {
                    $class = SchoolClass::query()->findOrFail($data['class_id']);

                    if (! Auth::user()?->can('generate', [ReportCard::class, $class])) {
                        Notification::make()
                            ->title(__('Tidak diizinkan'))
                            ->body(__('Hanya wali kelas dari kelas tersebut yang dapat men-generate rapor.'))
                            ->danger()
                            ->send();

                        return;
                    }

                    $summary = app(ReportCardGenerator::class)->generateForClass($class);

                    $body = [sprintf(
                        '%d dibuat, %d diperbarui, %d dilewati (sudah terbit).',
                        $summary['created'],
                        $summary['updated'],
                        $summary['skipped'],
                    )];

                    // Alasan per mapel ikut ditampilkan: "belum lengkap" bisa
                    // berarti komponennya memang belum diinput, tetapi bisa juga
                    // berarti bobot snapshot-nya tidak utuh karena kebijakan
                    // berganti versi di tengah semester — dua hal yang
                    // penanganannya berbeda bagi wali kelas.
                    if ($summary['incomplete'] !== []) {
                        $body[] = '<br>Belum lengkap:<br>'.collect($summary['incomplete'])
                            ->map(fn (array $reasons, string $name) => collect($reasons)
                                ->map(fn (string $reason, string $code) => "• {$name} — {$code}: {$reason}")
                                ->join('<br>'))
                            ->join('<br>');
                    }

                    // C-6 — nilai sumatif yang komponennya tidak ada di Grade
                    // Config tersimpan rapi tetapi tidak pernah ikut menghitung
                    // apa pun. Tanpa pesan ini guru tidak punya cara mengetahui
                    // bahwa penilaiannya tidak terpakai.
                    if ($summary['ignored'] !== []) {
                        $body[] = '<br>Tidak masuk nilai akhir karena komponennya tidak ada di Grade Config:<br>'
                            .collect($summary['ignored'])
                                ->map(fn (array $types, string $code) => sprintf(
                                    '• %s — %s',
                                    $code,
                                    collect($types)
                                        ->map(fn (string $type) => GradeType::tryFrom($type)?->label() ?? $type)
                                        ->join(', '),
                                ))
                                ->join('<br>');
                    }

                    $notification = Notification::make()
                        ->title(__('Draft rapor diproses'))
                        ->body(implode('', $body));

                    $summary['incomplete'] === [] && $summary['ignored'] === []
                        ? $notification->success()
                        : $notification->warning();

                    $notification->send();
                }),

            // Tech stack 3.1 — generate PDF sekelas berjalan di antrean.
            // Merendernya di dalam request akan menahan satu proses web selama
            // puluhan detik untuk kelas berisi tiga puluh siswa; VPS 2 core
            // tidak punya kemewahan itu.
            Actions\Action::make('generatePdfKelas')
                ->label(__('Generate PDF Kelas'))
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->form([
                    Forms\Components\Select::make('class_id')
                        ->label(__('Kelas'))
                        ->options(fn () => $this->classOptions())
                        ->required()
                        ->helperText(__('PDF dibuat di latar belakang. Kolom PDF akan berubah menjadi "Siap diunduh" bila selesai.')),
                ])
                ->action(function (array $data): void {
                    $class = SchoolClass::query()->findOrFail($data['class_id']);

                    if (! Auth::user()?->can('generate', [ReportCard::class, $class])) {
                        Notification::make()
                            ->title(__('Tidak diizinkan'))
                            ->body(__('Hanya wali kelas dari kelas tersebut yang dapat membuat PDF rapor sekelas.'))
                            ->danger()
                            ->send();

                        return;
                    }

                    $queued = $this->queuePdfsFor($class);

                    Notification::make()
                        ->title($queued === 0 ? 'Tidak ada rapor untuk diproses' : "{$queued} PDF rapor masuk antrean")
                        ->body($queued === 0
                            ? 'Kelas ini belum memiliki rapor. Jalankan Generate Rapor Kelas lebih dulu.'
                            : 'Berkas dibuat di latar belakang; halaman ini tidak perlu dibiarkan terbuka.')
                        ->{$queued === 0 ? 'warning' : 'success'}()
                        ->send();
                }),

            Actions\Action::make('publishClass')
                ->label(__('Terbitkan Sekelas'))
                ->icon('heroicon-o-paper-airplane')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription(__('Seluruh draft rapor kelas ini akan diterbitkan dan nilainya terkunci.'))
                ->form([
                    Forms\Components\Select::make('class_id')
                        ->label(__('Kelas'))
                        ->options(fn () => $this->classOptions())
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $class = SchoolClass::query()->findOrFail($data['class_id']);

                    if (! Auth::user()?->can('generate', [ReportCard::class, $class])) {
                        Notification::make()
                            ->title(__('Tidak diizinkan'))
                            ->body(__('Hanya wali kelas dari kelas tersebut yang dapat menerbitkan rapor.'))
                            ->danger()
                            ->send();

                        return;
                    }

                    $result = app(ReportCardGenerator::class)->publishClass($class, Auth::user());

                    $notification = Notification::make()
                        ->title("{$result['published']} rapor diterbitkan");

                    $result['blocked'] === []
                        ? $notification->success()
                        : $notification->warning()->body(
                            'Tertahan: '.collect($result['blocked'])
                                ->map(fn (array $codes, string $name) => $name.' ('.implode(', ', $codes).')')
                                ->join('; '),
                        );

                    $notification->send();
                }),
        ];
    }

    /**
     * Mengantrekan pembuatan PDF untuk seluruh rapor di satu kelas.
     *
     * Rapor draft ikut diproses, konsisten dengan unduhan satuan yang juga
     * melayaninya — berkasnya ditandai DRAFT oleh template.
     *
     * @return int jumlah rapor yang masuk antrean
     */
    protected function queuePdfsFor(SchoolClass $class): int
    {
        $reportCards = ReportCard::query()
            ->where('class_id', $class->getKey())
            ->where('academic_year_id', $class->academic_year_id)
            ->get();

        foreach ($reportCards as $reportCard) {
            $reportCard->markPdfQueued();

            GenerateReportCardPdf::dispatch($reportCard->getKey(), $reportCard->school_id);
        }

        return $reportCards->count();
    }

    /**
     * @return array<int, string>
     */
    protected function classOptions(): array
    {
        return SchoolClass::query()
            ->with('academicYear')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (SchoolClass $class) => [
                $class->id => $class->name.' — '.($class->academicYear?->name ?? '?'),
            ])
            ->all();
    }
}
