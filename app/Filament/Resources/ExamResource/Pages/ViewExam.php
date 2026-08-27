<?php

namespace App\Filament\Resources\ExamResource\Pages;

use App\Filament\Resources\ExamResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

/**
 * Halaman baca. Inilah pintu Kepala Sekolah — yang berwenang mengawasi tetapi
 * tidak mengelola: ia melihat identitas ujian, jadwalnya, dan daftar soalnya,
 * tetapi tidak kunci jawabannya (butir 292).
 */
class ViewExam extends ViewRecord
{
    protected static string $resource = ExamResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
