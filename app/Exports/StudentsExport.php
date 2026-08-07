<?php

namespace App\Exports;

use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * SIS-05 — Export data siswa ke .xlsx.
 * "Ekspor mencakup semua field data siswa."
 */
class StudentsExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping
{
    public function __construct(protected Builder $query) {}

    public function query(): Builder
    {
        return $this->query->with(['activeStudentClass.schoolClass']);
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'NIS',
            'NISN',
            'Nama Lengkap',
            'Jenis Kelamin',
            'Tempat Lahir',
            'Tanggal Lahir',
            'Agama',
            'Alamat',
            'Nama Orang Tua',
            'HP Orang Tua',
            'Email Orang Tua',
            'Tahun Masuk',
            'Kelas',
            'Status',
            'Catatan',
        ];
    }

    /**
     * @param  Student  $student
     * @return array<int, mixed>
     */
    public function map($student): array
    {
        return [
            $student->nis,
            $student->nisn,
            $student->full_name,
            $student->gender->value,
            $student->birth_place,
            $student->birth_date?->format('Y-m-d'),
            $student->religion,
            $student->address,
            $student->parent_name,
            $student->parent_phone,
            $student->parent_email,
            $student->entry_year,
            $student->activeStudentClass?->schoolClass?->name,
            $student->status->value,
            $student->notes,
        ];
    }
}
