<?php

namespace App\Filament\Resources\ExamResource\Pages;

use App\Filament\Resources\ExamResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

/**
 * Halaman ini hanya terbuka untuk ujian yang masih draf dan belum dikerjakan
 * siapa pun — `ExamPolicy::update()` yang menolak sisanya, sehingga alamat
 * halaman ini pun tidak dapat dipakai memutar tombol yang tersembunyi
 * (butir 290).
 *
 * Soal dan pilihan jawabannya ditulis lewat relation manager di bawah form ini.
 */
class EditExam extends EditRecord
{
    protected static string $resource = ExamResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
        ];
    }

    /**
     * Tahun ajaran tetap mengikuti kelas-mapelnya walaupun kelas-mapel itu
     * diganti saat mengedit.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $academicYearId = ExamResource::academicYearIdFor($data['class_subject_id'] ?? null);

        if ($academicYearId !== null) {
            $data['academic_year_id'] = $academicYearId;
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
