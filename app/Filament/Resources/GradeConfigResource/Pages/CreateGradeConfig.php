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
        // `Auth::user()`, bukan `Filament::auth()->user()`: panel admin memakai
        // `->authGuard('web')` yang sama dengan guard bawaan aplikasi, sehingga
        // keduanya menghasilkan pengguna yang sama. Yang dipilih adalah pola
        // yang dipakai seluruh resource/page lain — termasuk
        // GradeConfigResource::resolveSchoolId() yang dipanggil di bawah ini,
        // sehingga satu alur tidak lagi memakai dua guard berbeda.
        $user = Auth::user();

        if ($user === null) {
            throw new \RuntimeException('Pengguna belum terautentikasi.');
        }

        // Super Admin memang tidak memiliki school_id — itu yang membuatnya bisa
        // melihat seluruh cabang. Cabangnya diambil dari field "Cabang Sekolah"
        // yang hanya dirender untuk mereka; Admin Sekolah tetap terikat akunnya.
        $schoolId = GradeConfigResource::resolveSchoolId($data['school_id'] ?? null);

        if ($schoolId === null) {
            throw new \RuntimeException('Cabang sekolah belum ditentukan untuk konfigurasi ini.');
        }

        $data['school_id'] = $schoolId;
        $data['created_by'] = $user->getKey();
        $data['status'] = GradeConfigStatus::Draft->value;
        $data['components'] = array_values($data['components'] ?? []);

        $data['version'] = app(GradeConfigVersionManager::class)->nextVersionNumber(
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
