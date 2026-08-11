<?php

namespace App\Filament\Resources\GradeResource\Pages;

use App\Filament\Resources\GradeResource;
use App\Models\ClassSubject;
use App\Models\Grade;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CreateGrade extends CreateRecord
{
    protected static string $resource = GradeResource::class;

    /**
     * `academic_year_id` mengikuti class_subject yang dipilih, dan hak "guru
     * hanya boleh menilai kelas yang dia ampu" (API 4.8) diperiksa di sini.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $classSubject = ClassSubject::query()->findOrFail($data['class_subject_id']);

        // Policy di-resolve dari Grade::class; ClassSubject dikirim sebagai argumen.
        if (! Auth::user()?->can('gradeClassSubject', [Grade::class, $classSubject])) {
            throw ValidationException::withMessages([
                'data.class_subject_id' => 'Anda hanya dapat menginput nilai untuk kelas yang Anda ampu.',
            ]);
        }

        $data['academic_year_id'] = $classSubject->academic_year_id;
        $data['graded_by'] = Auth::id();
        $data['graded_at'] = now();

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
