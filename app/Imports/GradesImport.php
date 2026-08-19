<?php

namespace App\Imports;

use App\Enums\AssessmentType;
use App\Enums\GradeType;
use App\Models\ClassSubject;
use App\Models\Grade;
use App\Models\Student;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * NILAI-01 poin 2 / API 4.8 `POST /grades/import` — "Import nilai dari Excel
 * template". Pelaporannya mengikuti pola "Return: sukses + daftar error baris"
 * (API 4.4), sama seperti App\Imports\StudentsImport.
 *
 * Berkasnya hanya membawa dua kolom, mengikuti bentuk `POST /grades/bulk` yang
 * berbunyi "untuk satu class_subject (array of {student_id, score})": komponen,
 * jenis penilaian, dan kelas-mapel adalah konteks yang dipilih di form, bukan
 * isi berkas. Dengan begitu satu berkas tidak dapat diam-diam menulis nilai ke
 * kelas atau komponen lain.
 *
 * Kolom yang dibaca (heading row, huruf kecil dengan garis bawah): `nis`, `nilai`.
 * `nis` dipakai sebagai kunci alami siswa — sama seperti StudentsImport, dan
 * memang unik per cabang.
 */
class GradesImport implements ToCollection, WithHeadingRow
{
    public int $imported = 0;

    /** @var array<int, string> */
    public array $errors = [];

    public function __construct(
        protected ClassSubject $classSubject,
        protected GradeType $gradeType,
        protected AssessmentType $assessmentType,
        protected ?string $description = null,
    ) {}

    public function collection(Collection $rows): void
    {
        $students = $this->studentsByNis();

        foreach ($rows as $index => $row) {
            // +2 karena baris 1 adalah heading dan index Collection mulai dari 0.
            $line = $index + 2;
            $data = $this->normalise($row->toArray());

            // Baris kosong di akhir berkas bukan kesalahan.
            if (blank($data['nis']) && blank($data['score'])) {
                continue;
            }

            $validator = Validator::make($data, [
                'nis' => ['required', 'string', 'max:20'],
                // NILAI-01 poin 1 — skala 0–100.
                'score' => ['required', 'numeric', 'min:'.Grade::MIN_SCORE, 'max:'.Grade::MAX_SCORE],
            ]);

            if ($validator->fails()) {
                $this->errors[] = "Baris {$line}: ".implode(' ', $validator->errors()->all());

                continue;
            }

            $studentId = $students[$data['nis']] ?? null;

            // Nilai hanya boleh masuk untuk siswa yang benar-benar ada di kelas
            // ini — daftar yang sama dengan yang ditampilkan halaman Input Nilai.
            if ($studentId === null) {
                $this->errors[] = "Baris {$line}: NIS {$data['nis']} bukan siswa aktif di kelas ini.";

                continue;
            }

            // Lewat model, bukan query builder, supaya hook snapshot bobot
            // (keputusan butir 2) berlaku sama seperti jalur input lain.
            Grade::query()->create([
                'school_id' => $this->classSubject->school_id,
                'student_id' => $studentId,
                'class_subject_id' => $this->classSubject->getKey(),
                'academic_year_id' => $this->classSubject->academic_year_id,
                'grade_type' => $this->gradeType->value,
                'assessment_type' => $this->assessmentType->value,
                'score' => $data['score'],
                'description' => $this->description,
                'graded_by' => Auth::id(),
                'graded_at' => now(),
            ]);

            $this->imported++;
        }
    }

    /**
     * Siswa aktif di kelas yang diampu class_subject ini, dipetakan NIS → id.
     *
     * @return array<string, int>
     */
    protected function studentsByNis(): array
    {
        return Student::query()
            ->active()
            ->inClass((int) $this->classSubject->class_id)
            ->pluck('id', 'nis')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function normalise(array $row): array
    {
        return [
            'nis' => $this->value($row, 'nis'),
            'score' => $this->value($row, 'nilai'),
        ];
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
