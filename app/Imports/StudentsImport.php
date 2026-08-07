<?php

namespace App\Imports;

use App\Enums\Gender;
use App\Enums\StudentStatus;
use App\Models\Student;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * SIS-05 / API 4.5 POST /students/import — import siswa massal dari Excel.
 * "Return: sukses + daftar error baris" (API 4.4 untuk import user, pola sama).
 *
 * Kolom yang dibaca (heading row, huruf kecil dengan garis bawah):
 * nis, nisn, nama_lengkap, jenis_kelamin, tempat_lahir, tanggal_lahir, agama,
 * alamat, nama_orang_tua, hp_orang_tua, email_orang_tua, tahun_masuk, status.
 */
class StudentsImport implements ToCollection, WithHeadingRow
{
    public int $imported = 0;

    /** @var array<int, string> */
    public array $errors = [];

    public function __construct(protected int $schoolId) {}

    public function collection(Collection $rows): void
    {
        foreach ($rows as $index => $row) {
            // +2 karena baris 1 adalah heading dan index Collection mulai dari 0.
            $line = $index + 2;
            $data = $this->normalise($row->toArray());

            if (blank($data['nis']) && blank($data['full_name'])) {
                continue;
            }

            $validator = Validator::make($data, [
                'nis' => [
                    'required',
                    'string',
                    'max:20',
                    Rule::unique('students', 'nis')->where('school_id', $this->schoolId),
                ],
                'nisn' => ['nullable', 'digits:10'],
                'full_name' => ['required', 'string', 'max:150'],
                'gender' => ['required', Rule::in(array_column(Gender::cases(), 'value'))],
                'birth_place' => ['nullable', 'string', 'max:100'],
                'birth_date' => ['nullable', 'date'],
                'religion' => ['nullable', 'string', 'max:30'],
                'parent_name' => ['nullable', 'string', 'max:150'],
                'parent_phone' => ['nullable', 'string', 'max:20'],
                'parent_email' => ['nullable', 'email', 'max:150'],
                'entry_year' => ['nullable', 'integer', 'min:1900', 'max:2200'],
                'status' => ['required', Rule::in(array_column(StudentStatus::cases(), 'value'))],
            ]);

            if ($validator->fails()) {
                $this->errors[] = "Baris {$line}: ".implode(' ', $validator->errors()->all());

                continue;
            }

            Student::create($validator->validated() + ['school_id' => $this->schoolId]);

            $this->imported++;
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function normalise(array $row): array
    {
        $gender = strtoupper(trim((string) ($row['jenis_kelamin'] ?? '')));
        $status = strtoupper(trim((string) ($row['status'] ?? '')));

        return [
            'nis' => $this->value($row, 'nis'),
            'nisn' => $this->value($row, 'nisn'),
            'full_name' => $this->value($row, 'nama_lengkap'),
            'gender' => $gender === '' ? null : $gender,
            'birth_place' => $this->value($row, 'tempat_lahir'),
            'birth_date' => $this->date($row, 'tanggal_lahir'),
            'religion' => $this->value($row, 'agama'),
            'address' => $this->value($row, 'alamat'),
            'parent_name' => $this->value($row, 'nama_orang_tua'),
            'parent_phone' => $this->value($row, 'hp_orang_tua'),
            'parent_email' => $this->value($row, 'email_orang_tua'),
            'entry_year' => $this->value($row, 'tahun_masuk'),
            'status' => $status === '' ? StudentStatus::Active->value : $status,
        ];
    }

    /**
     * Sel tanggal di .xlsx terbaca sebagai serial number PhpSpreadsheet,
     * sehingga perlu dikonversi sebelum divalidasi sebagai tanggal.
     *
     * @param  array<string, mixed>  $row
     */
    protected function date(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
        }

        return trim((string) $value);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function value(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
