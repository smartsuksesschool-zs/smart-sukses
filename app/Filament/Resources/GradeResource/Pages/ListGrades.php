<?php

namespace App\Filament\Resources\GradeResource\Pages;

use App\Enums\AssessmentType;
use App\Enums\GradeType;
use App\Exports\GradeTemplateExport;
use App\Filament\Resources\GradeResource;
use App\Imports\GradesImport;
use App\Models\ClassSubject;
use App\Models\Grade;
use App\Services\Grading\ConfigurationGapWarner;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ListGrades extends ListRecords
{
    protected static string $resource = GradeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label(__('Input Nilai Satuan')),

            // API 4.8 menyebut "Import nilai dari Excel template" tanpa
            // menentukan isinya; berkas ini hanya membawa baris heading yang
            // dibaca importer, sehingga tidak ada format kedua yang perlu
            // dijaga tetap sinkron. Kewenangannya sama dengan import itu
            // sendiri — hanya berguna bagi yang boleh mengimpor.
            Actions\Action::make('template')
                ->label(__('Unduh Template'))
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->visible(fn () => Auth::user()?->can('import', Grade::class))
                ->action(fn () => Excel::download(new GradeTemplateExport, 'template_nilai.xlsx')),

            // NILAI-01 poin 2 / API 4.8 — POST /grades/import.
            Actions\Action::make('import')
                ->label(__('Import Excel'))
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->visible(fn () => Auth::user()?->can('import', Grade::class))
                ->modalDescription(__('Berkas cukup memuat dua kolom: nis dan nilai — tombol "Unduh Template" menyediakan berkas kosongnya. Kelas, komponen, dan jenis penilaian diambil dari pilihan di bawah.'))
                ->form([
                    Forms\Components\Select::make('class_subject_id')
                        ->label(__('Kelas — Mata Pelajaran'))
                        ->options(fn () => $this->classSubjectOptions())
                        ->searchable()
                        ->required(),

                    Forms\Components\Select::make('grade_type')
                        ->label(__('Komponen'))
                        ->options(GradeType::options())
                        ->default(GradeType::Daily->value)
                        ->required(),

                    Forms\Components\Select::make('assessment_type')
                        ->label(__('Jenis Penilaian'))
                        ->options(AssessmentType::options())
                        ->default(AssessmentType::Summative->value)
                        ->required()
                        ->helperText(__('Hanya penilaian sumatif yang dihitung ke rapor.')),

                    Forms\Components\TextInput::make('description')
                        ->label(__('Keterangan'))
                        ->maxLength(200)
                        ->placeholder(__('Ulangan Harian Bab 3')),

                    Forms\Components\FileUpload::make('file')
                        ->label(__('Berkas Excel (.xlsx)'))
                        ->required()
                        ->acceptedFileTypes([
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/vnd.ms-excel',
                        ])
                        ->maxSize(5120)
                        ->disk('local')
                        ->directory('imports'),
                ])
                ->action(fn (array $data) => $this->runImport($data)),
        ];
    }

    /**
     * Guru mata pelajaran hanya melihat kelas yang dia ampu; administrator
     * cabang melihat seluruhnya (API 4.8 baris 8, matriks PRD 1.1.2).
     *
     * @return array<int, string>
     */
    protected function classSubjectOptions(): array
    {
        $user = Auth::user();

        return ClassSubject::query()
            ->with(['schoolClass', 'subject'])
            ->when(
                ! $user?->isSchoolAdmin() && ! $user?->isSuperAdmin(),
                fn ($query) => $query->where('teacher_id', $user?->getKey()),
            )
            ->get()
            ->mapWithKeys(fn (ClassSubject $cs) => [
                $cs->id => trim(($cs->schoolClass?->name ?? '?').' — '.($cs->subject?->name ?? '?')),
            ])
            ->all();
    }

    /**
     * Import massal; mengembalikan jumlah sukses beserta daftar error per baris
     * (API 4.4 — "Return: sukses + daftar error baris").
     *
     * @param  array<string, mixed>  $data
     */
    protected function runImport(array $data): void
    {
        // Berkas unggahan hanya perantara, jadi penghapusannya diletakkan di
        // `finally` yang mencakup seluruh method: berkas yang rusak membuat
        // Excel::import melempar exception, dan payload yang ditolak policy
        // keluar lebih awal — keduanya dulu meninggalkan berkas di
        // storage/app/imports selamanya. Exception-nya sendiri sengaja tidak
        // ditangkap; penanganan galat di luar per-baris tetap milik Filament,
        // seperti sebelumnya.
        try {
            $classSubject = ClassSubject::query()->find($data['class_subject_id']);

            // Pagar yang sama dengan halaman Input Nilai: pilihan di form sudah
            // disaring, tetapi payload-nya tetap diperiksa policy.
            if ($classSubject === null
                || ! Auth::user()?->can('gradeClassSubject', [Grade::class, $classSubject])) {
                Notification::make()
                    ->title(__('Tidak diizinkan'))
                    ->body(__('Anda hanya dapat menginput nilai untuk kelas yang Anda ampu.'))
                    ->danger()
                    ->send();

                return;
            }

            $gradeType = GradeType::from((string) $data['grade_type']);
            $assessmentType = AssessmentType::from((string) $data['assessment_type']);

            $import = new GradesImport(
                $classSubject,
                $gradeType,
                $assessmentType,
                $data['description'] ?? null,
            );

            Excel::import($import, Storage::disk('local')->path($data['file']));

            $notification = Notification::make()
                ->title("{$import->imported} nilai berhasil diimport");

            if ($import->errors !== []) {
                $notification
                    ->body(implode("\n", array_slice($import->errors, 0, 10))
                        .(count($import->errors) > 10 ? "\n… dan ".(count($import->errors) - 10).' baris lain.' : ''))
                    ->warning()
                    ->persistent();
            } else {
                $notification->success();
            }

            $notification->send();

            // Komponen di luar Grade Config dan konfigurasi yang sudah LOCKED
            // sama senyapnya lewat import seperti lewat input massal.
            if ($import->imported > 0) {
                app(ConfigurationGapWarner::class)->warn($classSubject, $gradeType, $assessmentType);
            }
        } finally {
            Storage::disk('local')->delete($data['file']);
        }
    }
}
