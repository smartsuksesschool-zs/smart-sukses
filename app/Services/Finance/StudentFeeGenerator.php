<?php

namespace App\Services\Finance;

use App\Enums\StudentFeeStatus;
use App\Enums\StudentStatus;
use App\Models\AcademicYear;
use App\Models\FeeType;
use App\Models\Scopes\SchoolScope;
use App\Models\Student;
use App\Models\StudentFee;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * SPP-02 — "men-generate tagihan SPP bulanan untuk semua siswa aktif dalam
 * satu klik", dengan "preview daftar tagihan ditampilkan sebelum konfirmasi".
 *
 * Pratinjau dan penerbitan memakai satu sumber logika yang sama supaya
 * keduanya tidak pernah berbeda: apa yang dilihat operator adalah apa yang
 * dikerjakan worker.
 *
 * Seluruh query di sini melepas SchoolScope dan menyaring `school_id` secara
 * eksplisit. Itu bukan pelonggaran isolasi melainkan syaratnya: di dalam
 * worker antrean tidak ada pengguna terautentikasi, sehingga scope global
 * tidak memasang batasan apa pun (lihat SchoolScope::currentSchoolId()).
 * Cabangnya dibawa sebagai argumen dan menjadi satu-satunya pagar.
 */
class StudentFeeGenerator
{
    /**
     * Lama lock penerbitan ditahan, dan lama pemanggil menunggu giliran.
     */
    protected const LOCK_SECONDS = 120;

    protected const LOCK_WAIT_SECONDS = 10;

    /**
     * Rencana penerbitan untuk satu jenis tagihan pada satu periode.
     *
     * Tidak menulis apa pun. `targets` adalah siswa yang akan dibuatkan
     * tagihan, `skipped` siswa aktif yang sudah memilikinya untuk kombinasi
     * yang sama.
     *
     * @return array{targets: Collection<int, Student>, skipped: Collection<int, Student>}
     */
    public function preview(FeeType $feeType, string $period): array
    {
        $schoolId = (int) $feeType->school_id;

        $students = $this->activeStudents($schoolId);
        $billed = $this->alreadyBilledStudentIds($schoolId, (int) $feeType->getKey(), $period);

        return [
            'targets' => $students->reject(fn (Student $s) => in_array($s->getKey(), $billed, true))->values(),
            'skipped' => $students->filter(fn (Student $s) => in_array($s->getKey(), $billed, true))->values(),
        ];
    }

    /**
     * Menerbitkan tagihan untuk seluruh siswa aktif yang belum memilikinya.
     *
     * Idempoten terhadap pengulangan: kombinasi
     * `school_id + student_id + fee_type_id + period` yang sudah ada dilewati,
     * sehingga menjalankan ulang job — karena retry, klik ganda, maupun
     * permintaan generate identik kedua — tidak pernah menghasilkan baris
     * kedua. Lihat docs/implementation-notes.md butir 52.
     *
     * @return array{created: int, skipped: int}
     */
    public function generate(int $schoolId, int $feeTypeId, string $period, string $dueDate): array
    {
        // Lock aplikasi, bukan unique index: blueprint tidak menetapkan
        // keunikan bisnis pada student_fees, dan menambahkannya berarti
        // mengarang aturan. Lock memakai cache driver database yang sudah
        // dipakai project (tabel `cache_locks` sudah ada) — tanpa infrastruktur
        // baru. Ia menutup celah baca-lalu-tulis antara dua worker yang
        // memproses kombinasi yang sama secara bersamaan.
        return Cache::lock($this->lockKey($schoolId, $feeTypeId, $period), self::LOCK_SECONDS)
            ->block(self::LOCK_WAIT_SECONDS, fn () => $this->issue($schoolId, $feeTypeId, $period, $dueDate));
    }

    /**
     * @return array{created: int, skipped: int}
     */
    protected function issue(int $schoolId, int $feeTypeId, string $period, string $dueDate): array
    {
        $feeType = $this->feeType($schoolId, $feeTypeId);

        // Jenis tagihan sudah tidak ada, atau bukan milik cabang yang
        // mengantrekan. Keduanya bukan kegagalan yang perlu diulang.
        if ($feeType === null) {
            return ['created' => 0, 'skipped' => 0];
        }

        $students = $this->activeStudents($schoolId);
        $billed = $this->alreadyBilledStudentIds($schoolId, $feeTypeId, $period);

        $targets = $students->reject(fn (Student $s) => in_array($s->getKey(), $billed, true));

        if ($targets->isEmpty()) {
            return ['created' => 0, 'skipped' => $students->count()];
        }

        $academicYearId = $this->academicYearIdFor($feeType);

        DB::transaction(function () use ($targets, $feeType, $schoolId, $period, $dueDate, $academicYearId): void {
            foreach ($targets as $student) {
                // Sengaja satu `create()` per siswa, bukan satu bulk insert:
                // jejak audit ditulis listener `eloquent.created`, dan bulk
                // insert lewat query builder tidak memicu model event sama
                // sekali (butir 46). Sisi bacanya tetap konstan — siswa dan
                // tagihan yang sudah ada masing-masing diambil sekali.
                StudentFee::query()->create([
                    'school_id' => $schoolId,
                    'student_id' => $student->getKey(),
                    'fee_type_id' => $feeType->getKey(),
                    'academic_year_id' => $academicYearId,
                    // Snapshot nominal saat penerbitan: perubahan nominal
                    // jenis tagihan setelah ini tidak boleh mengubah tagihan
                    // yang sudah terbit.
                    'amount' => $feeType->amount,
                    'amount_paid' => 0,
                    'due_date' => $dueDate,
                    'period' => $period,
                    'status' => StudentFeeStatus::Unpaid->value,
                ]);
            }
        });

        return [
            'created' => $targets->count(),
            'skipped' => $students->count() - $targets->count(),
        ];
    }

    /**
     * Tahun ajaran tagihan mengikuti jenis tagihannya bila terikat; bila tidak
     * (ERD: "NULL untuk tagihan berulang"), dipakai tahun ajaran aktif cabang.
     * Lihat docs/implementation-notes.md butir 53.
     */
    protected function academicYearIdFor(FeeType $feeType): ?int
    {
        if ($feeType->academic_year_id !== null) {
            return (int) $feeType->academic_year_id;
        }

        return AcademicYear::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $feeType->school_id)
            ->where('is_active', true)
            ->value('id');
    }

    protected function feeType(int $schoolId, int $feeTypeId): ?FeeType
    {
        return FeeType::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $schoolId)
            ->find($feeTypeId);
    }

    /**
     * SPP-02 poin 1 — "record student_fees untuk setiap siswa aktif".
     * Hanya status ACTIVE; GRADUATED, DROPPED_OUT, dan TRANSFERRED tidak
     * ditagih.
     *
     * @return Collection<int, Student>
     */
    protected function activeStudents(int $schoolId): Collection
    {
        return Student::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $schoolId)
            ->where('status', StudentStatus::Active->value)
            ->orderBy('full_name')
            ->get();
    }

    /**
     * Satu query untuk seluruh siswa — bukan satu pemeriksaan per siswa.
     *
     * @return array<int, int>
     */
    protected function alreadyBilledStudentIds(int $schoolId, int $feeTypeId, string $period): array
    {
        return StudentFee::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $schoolId)
            ->where('fee_type_id', $feeTypeId)
            ->where('period', $period)
            ->pluck('student_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function lockKey(int $schoolId, int $feeTypeId, string $period): string
    {
        return "student-fees:generate:{$schoolId}:{$feeTypeId}:{$period}";
    }
}
