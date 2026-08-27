<?php

namespace App\Filament\Resources\ExamResource\Pages;

use App\Enums\ExamStatus;
use App\Filament\Resources\ExamResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Ujian baru selalu lahir sebagai DRAF, dan tidak pernah dapat lahir terbit.
 *
 * Terbit menuntut isi yang lengkap — sedikitnya satu soal, dengan pilihan dan
 * kuncinya — dan pada detik pembuatan belum ada satu soal pun. Karena itu
 * status tidak pernah menjadi field: ia ditetapkan di sini, dan satu-satunya
 * jalan menuju PUBLISHED adalah ExamPublisher (butir 296).
 */
class CreateExam extends CreateRecord
{
    protected static string $resource = ExamResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = Auth::user();

        if ($user === null) {
            throw new RuntimeException('Pengguna belum terautentikasi.');
        }

        $schoolId = ExamResource::resolveSchoolId($data['school_id'] ?? null);

        if ($schoolId === null) {
            throw new RuntimeException('Cabang sekolah belum ditentukan untuk ujian ini.');
        }

        // Cabang dan tahun ajaran diturunkan dari kelas-mapelnya, tidak diambil
        // dari form. Keduanya wajib sesuai menurut ExamIntegrity, dan cara
        // paling sederhana menjamin kesesuaian adalah tidak pernah memberi
        // kesempatan mengirimkannya berbeda (butir 295).
        $data['school_id'] = $schoolId;
        $data['academic_year_id'] = ExamResource::academicYearIdFor($data['class_subject_id'] ?? null);
        $data['created_by'] = $user->getKey();
        $data['status'] = ExamStatus::Draft->value;

        if ($data['academic_year_id'] === null) {
            throw new RuntimeException('Kelas — mata pelajaran tidak memiliki tahun ajaran.');
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        // Diarahkan ke halaman edit, bukan ke daftar: ujian yang baru dibuat
        // belum punya soal, dan soalnya ditulis di sana.
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
