<?php

namespace App\Jobs;

use App\Services\Finance\StudentFeeGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Tech stack 3.1 — "Background jobs (**generate tagihan massal**, PDF rapor)",
 * dan Deployment 3.3 — "Worker untuk background jobs (PDF generate, bulk fee)".
 * Penerbitan massal karena itu wajib lewat antrean, bukan di dalam request.
 *
 * Identifikasinya sengaja hanya skalar, bukan model ter-serialize: tidak ada
 * state besar yang bisa basi antara saat dispatch dan saat worker menjalankan,
 * dan `schoolId` yang dibawa eksplisit menjadi pagar cabang yang tidak
 * bergantung pada SchoolScope — scope itu tidak membatasi apa pun di dalam
 * worker karena di sana tidak ada pengguna terautentikasi. Polanya sama dengan
 * GenerateReportCardPdf.
 */
class GenerateStudentFees implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    /**
     * Lama kunci keunikan ditahan bila job tidak pernah selesai.
     */
    public int $uniqueFor = 600;

    public function __construct(
        public readonly int $schoolId,
        public readonly int $feeTypeId,
        public readonly string $period,
        public readonly string $dueDate,
    ) {}

    /**
     * Dua permintaan generate identik yang masih menunggu di antrean dilipat
     * menjadi satu. Ini lapis pertama pengamanan terhadap klik ganda; lapis
     * keduanya — dan yang menutup retry serta eksekusi bersamaan — ada di
     * StudentFeeGenerator: lock aplikasi plus pelewatan kombinasi yang sudah
     * ada. Lihat docs/implementation-notes.md butir 52.
     */
    public function uniqueId(): string
    {
        return "{$this->schoolId}:{$this->feeTypeId}:{$this->period}";
    }

    /**
     * Idempoten: menjalankannya berkali-kali menghasilkan keadaan akhir yang
     * sama karena tagihan yang sudah ada dilewati, bukan dibuat ulang.
     */
    public function handle(StudentFeeGenerator $generator): void
    {
        $generator->generate($this->schoolId, $this->feeTypeId, $this->period, $this->dueDate);
    }
}
