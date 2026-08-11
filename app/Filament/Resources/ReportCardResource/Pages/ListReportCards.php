<?php

namespace App\Filament\Resources\ReportCardResource\Pages;

use App\Filament\Resources\ReportCardResource;
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
                ->label('Generate Rapor Kelas')
                ->icon('heroicon-o-sparkles')
                ->color('primary')
                ->form([
                    Forms\Components\Select::make('class_id')
                        ->label('Kelas')
                        ->options(fn () => $this->classOptions())
                        ->required()
                        ->helperText('Draft rapor dibuat untuk seluruh siswa aktif di kelas ini.'),
                ])
                ->action(function (array $data): void {
                    $class = SchoolClass::query()->findOrFail($data['class_id']);

                    if (! Auth::user()?->can('generate', [ReportCard::class, $class])) {
                        Notification::make()
                            ->title('Tidak diizinkan')
                            ->body('Hanya wali kelas dari kelas tersebut yang dapat men-generate rapor.')
                            ->danger()
                            ->send();

                        return;
                    }

                    $summary = app(ReportCardGenerator::class)->generateForClass($class);

                    $notification = Notification::make()
                        ->title('Draft rapor diproses')
                        ->body(sprintf(
                            '%d dibuat, %d diperbarui, %d dilewati (sudah terbit).',
                            $summary['created'],
                            $summary['updated'],
                            $summary['skipped'],
                        ));

                    // Alasan per mapel ikut ditampilkan: "belum lengkap" bisa
                    // berarti komponennya memang belum diinput, tetapi bisa juga
                    // berarti bobot snapshot-nya tidak utuh karena kebijakan
                    // berganti versi di tengah semester — dua hal yang
                    // penanganannya berbeda bagi wali kelas.
                    $summary['incomplete'] === []
                        ? $notification->success()
                        : $notification->warning()->body(
                            sprintf(
                                '%d dibuat, %d diperbarui. Belum lengkap:<br>%s',
                                $summary['created'],
                                $summary['updated'],
                                collect($summary['incomplete'])
                                    ->map(fn (array $reasons, string $name) => collect($reasons)
                                        ->map(fn (string $reason, string $code) => "• {$name} — {$code}: {$reason}")
                                        ->join('<br>'))
                                    ->join('<br>'),
                            ),
                        );

                    $notification->send();
                }),

            Actions\Action::make('publishClass')
                ->label('Terbitkan Sekelas')
                ->icon('heroicon-o-paper-airplane')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('Seluruh draft rapor kelas ini akan diterbitkan dan nilainya terkunci.')
                ->form([
                    Forms\Components\Select::make('class_id')
                        ->label('Kelas')
                        ->options(fn () => $this->classOptions())
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $class = SchoolClass::query()->findOrFail($data['class_id']);

                    if (! Auth::user()?->can('generate', [ReportCard::class, $class])) {
                        Notification::make()
                            ->title('Tidak diizinkan')
                            ->body('Hanya wali kelas dari kelas tersebut yang dapat menerbitkan rapor.')
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
