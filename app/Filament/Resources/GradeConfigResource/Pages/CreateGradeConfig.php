<?php

namespace App\Filament\Resources\GradeConfigResource\Pages;

use App\Enums\GradeConfigStatus;
use App\Filament\Resources\GradeConfigResource;
use App\Services\Grading\GradeConfigVersionManager;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

/**
 * Konfigurasi baru selalu lahir sebagai DRAFT (keputusan Sprint 4 butir 4),
 * dengan nomor versi berikutnya untuk pasangan mapel + tahun ajaran.
 */
class CreateGradeConfig extends CreateRecord
{
    protected static string $resource = GradeConfigResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = Auth::user();
        $schoolId = $data['school_id'] ?? $user?->school_id;

        $data['created_by'] = $user?->getKey();
        $data['status'] = GradeConfigStatus::Draft->value;
        $data['components'] = array_values($data['components'] ?? []);

        $data['version'] = $schoolId === null
            ? 1
            : app(GradeConfigVersionManager::class)->nextVersionNumber(
                (int) $schoolId,
                (int) $data['subject_id'],
                (int) $data['academic_year_id'],
            );

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
