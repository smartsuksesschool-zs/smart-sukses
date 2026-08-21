<?php

namespace App\Services\Finance;

use App\Enums\StudentClassStatus;
use App\Enums\StudentFeeStatus;
use App\Exports\StudentFeesExport;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Scopes\SchoolScope;
use App\Models\StudentFee;
use App\Models\User;
use App\Support\Finance\SchoolContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * SPP-05 — "Sebagai Bendahara, saya dapat mengekspor laporan tagihan per
 * periode ke Excel", dengan filter kelas, periode, dan status.
 *
 * Satu sumber untuk kewenangan, penyaringan, dan penamaan berkasnya, sehingga
 * endpoint `GET /student-fees/export` nanti (butir 101) tinggal memanggilnya
 * dan tidak dapat menghasilkan laporan yang berbeda dari tombol di panel.
 *
 * Laporan ini selalu **satu cabang**. Ekspor lintas cabang tidak diminta
 * requirement mana pun, dan KAS-03 sudah menjawab kebutuhan perbandingan
 * antarcabang di layar.
 */
class StudentFeeReportExporter
{
    /**
     * Mengunduh laporan tagihan sebagai .xlsx.
     *
     * @param  array<string, mixed>  $filters
     *
     * @throws AuthorizationException|ValidationException
     */
    public function download(User $actor, array $filters): BinaryFileResponse
    {
        $resolved = $this->resolveFilters($actor, $filters);

        return Excel::download(
            new StudentFeesExport($this->query($actor, $filters)),
            $this->fileName($resolved),
        );
    }

    /**
     * Query tagihan yang akan diekspor — terotorisasi dan terkunci pada satu
     * cabang.
     *
     * @param  array<string, mixed>  $filters
     *
     * @throws AuthorizationException|ValidationException
     */
    public function query(User $actor, array $filters): Builder
    {
        $resolved = $this->resolveFilters($actor, $filters);

        return StudentFee::query()
            // Global scope dilepas dan `school_id` disaring eksplisit: scope
            // bergantung pada sesi dan tidak membatasi apa pun bagi Super
            // Admin, sedangkan laporan ini harus terkunci pada satu cabang.
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $resolved['school_id'])
            ->where('period', $resolved['period'])
            ->when(
                $resolved['status'] !== null,
                fn (Builder $query) => $query->where('status', $resolved['status']),
            )
            ->when(
                $resolved['class_id'] !== null,
                // Penyaring kelas memakai penempatan pada tahun ajaran tagihan
                // itu sendiri — asosiasi yang sama dengan yang dicetak pada
                // kolom Kelas. Menyaring berdasarkan kelas siswa saat ini akan
                // membuat isi berkas tidak cocok dengan filternya (butir 99).
                fn (Builder $query) => $query->whereHas(
                    'student.studentClasses',
                    fn (Builder $inner) => $inner
                        ->where('class_id', $resolved['class_id'])
                        ->where('status', StudentClassStatus::Active->value)
                        ->whereColumn('student_classes.academic_year_id', 'student_fees.academic_year_id'),
                ),
            )
            ->orderBy('id');
    }

    /**
     * Kelas yang dapat dipilih sebagai filter, terbatas pada cabang laporan.
     *
     * @return array<int, string>
     */
    public function classOptions(?int $schoolId): array
    {
        if ($schoolId === null) {
            return [];
        }

        return SchoolClass::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $schoolId)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * SIS-05 poin 2 menetapkan pola `siswa_[kode_sekolah]_[tanggal].xlsx`;
     * laporan ini mengikutinya, dengan periode menggantikan tanggal karena
     * SPP-05 memang laporan per periode.
     *
     * Kode cabang di-slug lebih dulu: nama berkas tidak boleh ikut membawa
     * spasi maupun karakter yang tidak sah dari data cabang.
     *
     * @param  array<string, mixed>  $resolved
     */
    public function fileName(array $resolved): string
    {
        $code = School::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->whereKey($resolved['school_id'])
            ->value('code');

        $code = Str::slug((string) $code) ?: 'cabang';

        return 'tagihan_'.$code.'_'.$resolved['period'].'.xlsx';
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{school_id: int, period: string, class_id: int|null, status: string|null}
     *
     * @throws AuthorizationException|ValidationException
     */
    public function resolveFilters(User $actor, array $filters): array
    {
        $this->authorize($actor);

        return [
            'school_id' => $this->resolveSchoolId($filters['school_id'] ?? null, $actor),
            'period' => $this->resolvePeriod($filters['period'] ?? null),
            'class_id' => $this->resolveClassId($filters['class_id'] ?? null),
            'status' => $this->resolveStatus($filters['status'] ?? null),
        ];
    }

    /**
     * Tombol di panel dapat disembunyikan, dan penyembunyian bukan proteksi:
     * request Livewire tetap dapat dikirim langsung. Izinnya karena itu
     * diperiksa lagi di sini, pada jalur yang benar-benar menghasilkan berkas.
     *
     * @throws AuthorizationException
     */
    protected function authorize(User $actor): void
    {
        if (Gate::forUser($actor)->denies('export', StudentFee::class)) {
            throw new AuthorizationException('Anda tidak berwenang mengekspor laporan tagihan.');
        }
    }

    /**
     * Cabang yang diekspor — aturannya di App\Support\Finance\SchoolContext,
     * satu tempat yang dipakai bersama seluruh operasi keuangan satu-cabang
     * (butir 133).
     *
     * @throws ValidationException
     */
    protected function resolveSchoolId(mixed $formValue, User $actor): int
    {
        return SchoolContext::resolve($formValue, $actor);
    }

    /**
     * Periode wajib diisi.
     *
     * SPP-05 adalah "laporan tagihan **per periode**", dan tanpa periode satu
     * klik dapat menarik seluruh riwayat cabang ke dalam satu berkas tanpa
     * operator menyadarinya. Lihat butir 100.
     *
     * @throws ValidationException
     */
    protected function resolvePeriod(mixed $period): string
    {
        if (! is_string($period) || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            throw ValidationException::withMessages([
                'period' => 'Periode harus berformat YYYY-MM, misalnya 2026-08.',
            ]);
        }

        return $period;
    }

    protected function resolveClassId(mixed $classId): ?int
    {
        // Kelas cabang lain tidak perlu ditolak dengan pesan khusus: query-nya
        // sudah terkunci pada cabang laporan, sehingga id asing menghasilkan
        // laporan kosong, bukan kebocoran data.
        return is_numeric($classId) ? (int) $classId : null;
    }

    /**
     * @throws ValidationException
     */
    protected function resolveStatus(mixed $status): ?string
    {
        if (blank($status)) {
            return null;
        }

        $resolved = $status instanceof StudentFeeStatus
            ? $status
            : StudentFeeStatus::tryFrom(is_string($status) ? $status : '');

        if ($resolved === null) {
            throw ValidationException::withMessages([
                'status' => 'Status tagihan tidak dikenal.',
            ]);
        }

        return $resolved->value;
    }
}
