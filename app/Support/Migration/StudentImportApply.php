<?php

namespace App\Support\Migration;

use App\Enums\StudentClassStatus;
use App\Enums\StudentStatus;
use App\Models\AcademicYear;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentClass;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * M3 — mode terap, hanya untuk basis data uji.
 *
 * Ia tidak menilai apa pun sendiri: keputusannya seluruhnya datang dari
 * StudentImportPlan, dan yang dikerjakan di sini cuma menuliskan baris yang
 * rencananya sudah menyatakan siap. Baris tertunda dan baris ditolak dilewati
 * tanpa pernah disentuh (butir 492).
 *
 * Yang boleh berubah sengaja sempit:
 *
 * - baris baru  : school_id, nis, nisn, full_name, gender, status
 * - baris cocok : **hanya** nisn, dan hanya bila kolomnya masih kosong
 *
 * Nama dan jenis kelamin siswa yang sudah ada tidak pernah ditimpa dari berkas.
 * Data yang sudah masuk sistem sudah melewati mata manusia; berkas lama bukan
 * sumber yang lebih benar. Menimpanya akan menghapus perbaikan tata usaha tanpa
 * jejak (butir 493).
 *
 * Idempotent: menjalankannya dua kali menghasilkan jumlah baris yang sama.
 * Setiap siswa ditulis di dalam satu transaksi bersama penempatan kelasnya,
 * sehingga tidak ada siswa yang separuh jadi.
 */
class StudentImportApply
{
    /**
     * Otorisasi impor produksi, bila ada.
     *
     * Sengaja objek, bukan boolean. Bendera `true` dapat tersalin dari contoh
     * kode atau tertinggal dari eksperimen; objek ini hanya bisa lahir lewat
     * ProductionImportAuthorization::grant() yang memeriksa ulang setiap pagar
     * (butir 520).
     */
    protected ?ProductionImportAuthorization $authorization = null;

    public function __construct(
        protected School $school,
        protected ?AcademicYear $year = null,
    ) {}

    /**
     * Jalur kedua: impor produksi yang sudah diotorisasi.
     *
     * MigrationWriteGuard **tidak dilemahkan** — ia tetap utuh dan tetap menolak
     * `migrasi:terapkan-uji` di luar basis data uji. Yang ada di sini jalur
     * terpisah yang jauh lebih sempit: hanya terbuka di `production`, hanya
     * dengan otorisasi yang membawa rencananya sendiri, dan hanya lewat
     * `migrasi:terapkan-produksi`.
     */
    public function authorizedForProduction(ProductionImportAuthorization $authorization): self
    {
        $this->authorization = $authorization;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    public function run(array $plan): array
    {
        $this->assertMayWrite($plan);

        $result = [
            'created' => 0,
            'matched' => 0,
            'nisn_backfilled' => 0,
            'nisn_conflicts' => [],
            'placed' => 0,
            'placement_skipped' => 0,
            'skipped' => 0,
        ];

        foreach ($plan['rows'] as $row) {
            if (! in_array($row['outcome'], StudentImportPlan::WRITABLE, true)) {
                $result['skipped']++;

                continue;
            }

            DB::transaction(function () use ($row, &$result): void {
                $student = $row['outcome'] === StudentImportPlan::READY_CREATE
                    ? $this->create($row, $result)
                    : $this->match($row, $result);

                $this->place($student, $row, $result);
            });
        }

        return $result;
    }

    /**
     * Dua jalur tulis, dan tidak ada jalur ketiga.
     *
     * Tanpa otorisasi produksi: pagar basis data uji seperti sebelumnya.
     * Dengan otorisasi: diperiksa ulang bahwa otorisasinya memang menyahkan
     * rencana **ini** — bukan rencana lain yang kebetulan lewat. Otorisasi yang
     * dapat dipakai ulang untuk berkas berbeda sama saja dengan tidak ada
     * otorisasi.
     *
     * @param  array<string, mixed>  $plan
     */
    protected function assertMayWrite(array $plan): void
    {
        if ($this->authorization === null) {
            $refusal = MigrationWriteGuard::refusal();

            if ($refusal !== null) {
                throw new RuntimeException($refusal);
            }

            return;
        }

        if (! app()->environment('production')) {
            throw new RuntimeException(
                'Otorisasi impor produksi dipakai di luar lingkungan `production`.'
            );
        }

        $fingerprint = ImportFingerprint::of(
            $plan['source_path'] ?? '',
            $this->school,
            $this->year,
            $plan,
        );

        if (! $this->authorization->covers($plan, $fingerprint)) {
            throw new RuntimeException(
                'Otorisasi tidak cocok dengan rencana yang hendak ditulis. '
                .'Jalankan ulang analisis kering dan otorisasinya.'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $result
     */
    protected function create(array $row, array &$result): Student
    {
        // `firstOrCreate` dan bukan `create`: pemanggilan kedua atas berkas yang
        // sama harus menemukan barisnya, bukan menabrak indeks unik.
        $student = Student::query()->firstOrCreate(
            [
                'school_id' => $this->school->id,
                'nis' => $row['nis'],
            ],
            [
                'nisn' => $row['nisn'],
                'full_name' => $row['full_name'],
                'gender' => $row['gender'],
                'status' => StudentStatus::Active->value,
            ],
        );

        $student->wasRecentlyCreated ? $result['created']++ : $result['matched']++;

        return $student;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $result
     */
    protected function match(array $row, array &$result): Student
    {
        $student = Student::query()
            ->where('school_id', $this->school->id)
            ->where('nis', $row['nis'])
            ->firstOrFail();

        $result['matched']++;

        if ($row['nisn'] === null) {
            return $student;
        }

        if ($student->nisn === null || $student->nisn === '') {
            $student->forceFill(['nisn' => $row['nisn']])->save();
            $result['nisn_backfilled']++;

            return $student;
        }

        // NISN tersimpan yang berbeda dari NISN sumber bukan perkara importer.
        // Ia dilaporkan dan dibiarkan apa adanya.
        if ($student->nisn !== $row['nisn']) {
            $result['nisn_conflicts'][] = $row['sheet'].':'.$row['line'];
        }

        return $student;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $result
     */
    protected function place(Student $student, array $row, array &$result): void
    {
        if ($row['placement'] !== StudentImportPlan::PLACEMENT_READY || $this->year === null) {
            $result['placement_skipped']++;

            return;
        }

        $classId = $row['class_id'];

        if ($classId === null) {
            $result['placement_skipped']++;

            return;
        }

        $placement = StudentClass::query()->firstOrCreate(
            [
                'school_id' => $this->school->id,
                'student_id' => $student->id,
                'academic_year_id' => $this->year->id,
                'status' => StudentClassStatus::Active->value,
            ],
            [
                'class_id' => $classId,
            ],
        );

        $placement->wasRecentlyCreated ? $result['placed']++ : $result['placement_skipped']++;
    }
}
