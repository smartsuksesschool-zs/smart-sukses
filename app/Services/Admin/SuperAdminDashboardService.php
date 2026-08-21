<?php

namespace App\Services\Admin;

use App\Enums\PpdbStatus;
use App\Models\Payment;
use App\Models\PpdbRegistration;
use App\Models\Scopes\SchoolScope;
use App\Models\Student;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * API 4.3 — GET /admin/dashboard, Auth Level **Super**: "Dashboard ringkasan
 * semua cabang: total siswa, total SPP terkumpul, PPDB aktif".
 *
 * Tiga angka, tiga query agregat, tanpa satu pun perulangan per cabang. Ini
 * satu-satunya tempat ketiganya dihitung: widget di panel memanggil service
 * yang sama dengan endpoint API, sehingga layar dan API tidak dapat
 * menampilkan angka yang berbeda.
 *
 * Berbeda dari KAS-03 yang juga lintas cabang: KAS-03 mengukur keterkumpulan
 * tagihan **per cabang** dan menampilkannya sebagai tabel per baris, sedangkan
 * di sini yang diminta satu angka platform. Keduanya karena itu tidak berbagi
 * service — menggabungkannya akan membuat satu objek dengan dua arti
 * "terkumpul" (butir 142).
 */
class SuperAdminDashboardService
{
    /** Mengikuti DECIMAL(12,2) pada ERD. */
    protected const SCALE = 2;

    /**
     * @return array{total_students: int, total_spp_collected: string, active_ppdb: int}
     *
     * @throws AuthorizationException
     */
    public function summarize(User $actor): array
    {
        $this->authorize($actor);

        return [
            'total_students' => $this->totalStudents(),
            'total_spp_collected' => $this->totalSppCollected(),
            'active_ppdb' => $this->activePpdb(),
        ];
    }

    /**
     * "Total siswa" seluruh cabang — siswa berstatus ACTIVE.
     *
     * Definisinya sama persis dengan `student_count` pada statistik per cabang;
     * kalau berbeda, menjumlahkan seluruh cabang tidak akan pernah menghasilkan
     * angka dashboard ini dan keduanya akan saling membantah (butir 140).
     *
     * Cabang yang sudah dinonaktifkan tidak dikecualikan: dokumen menyebut
     * "semua cabang", dan menyaringnya berarti menambahkan aturan yang tidak
     * diminta. Siswa cabang yang tutup umumnya sudah tidak berstatus ACTIVE
     * dengan sendirinya (butir 144).
     */
    protected function totalStudents(): int
    {
        return Student::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->active()
            ->count();
    }

    /**
     * "Total SPP terkumpul" — seluruh penerimaan yang tercatat di `payments`,
     * lintas cabang dan lintas waktu.
     *
     * Kalimatnya menyebut "total" tanpa keterangan waktu apa pun, sedangkan
     * endpoint statistik cabang di tabel yang sama menulis "bulan ini" secara
     * eksplisit. Perbedaan itu dibaca apa adanya: yang satu sepanjang riwayat,
     * yang lain satu bulan. Menjadikannya diam-diam bulan berjalan akan
     * mengubah arti kalimat blueprint tanpa dasar (butir 143).
     *
     * Sumbernya `payments`, bukan `student_fees.amount_paid`: yang pertama
     * riwayat uang yang benar-benar diterima, yang kedua kolom posisi tagihan.
     * Tidak ada penyaringan berdasarkan nama jenis tagihan — modul SPP menaungi
     * berbagai jenis tagihan dan blueprint tidak menyediakan penanda "ini SPP",
     * sehingga mencocokkan string "SPP" berarti mengarang aturan.
     */
    protected function totalSppCollected(): string
    {
        $sum = Payment::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->sum('amount_paid');

        return number_format((float) $sum, self::SCALE, '.', '');
    }

    /**
     * "PPDB aktif" — pendaftaran yang masih berjalan di alur PPDB.
     *
     * Frasa ini yang paling kabur dari ketiganya, dan yang menentukan
     * bacaannya adalah apa yang benar-benar ada di skema: `ppdb_registrations`
     * hanya punya `status`, dan **tidak ada** konsep periode pendaftaran
     * dibuka/ditutup di mana pun — tidak ada kolom, tidak ada konfigurasi,
     * tidak ada penanda pada `schools`. Membuatnya sekarang berarti mengarang
     * skema.
     *
     * Yang dihitung karena itu pendaftarannya, bukan cabangnya: REGISTERED,
     * DOCUMENT_REVIEW, dan PASSED. PASSED masih ikut karena alur blueprint
     * berlanjut PASSED → enroll, sehingga calon yang lulus tetapi belum
     * didaftarkan masih menjadi pekerjaan yang tertunda. FAILED dan ENROLLED
     * adalah ujung alur dan tidak lagi aktif.
     *
     * Artinya "pendaftaran PPDB yang sedang berjalan", **bukan** "jumlah cabang
     * yang sedang membuka pendaftaran" (butir 142).
     */
    protected function activePpdb(): int
    {
        return PpdbRegistration::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->whereIn('status', [
                PpdbStatus::Registered->value,
                PpdbStatus::DocumentReview->value,
                PpdbStatus::Passed->value,
            ])
            ->count();
    }

    /**
     * Auth Level **Super**, dan diperiksa di service — bukan hanya di
     * middleware rute — karena widget panel memanggil jalur yang sama.
     *
     * @throws AuthorizationException
     */
    protected function authorize(User $actor): void
    {
        if (! $actor->isSuperAdmin()) {
            throw new AuthorizationException('Dashboard platform hanya untuk Super Admin.');
        }
    }
}
