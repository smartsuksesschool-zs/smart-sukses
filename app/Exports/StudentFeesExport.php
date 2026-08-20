<?php

namespace App\Exports;

use App\Enums\StudentClassStatus;
use App\Enums\StudentFeeStatus;
use App\Models\StudentFee;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * SPP-05 — "mengekspor laporan tagihan per periode ke Excel", dengan kolom
 * "nama siswa, kelas, periode, jumlah tagihan, jumlah bayar, sisa, status".
 *
 * Mengikuti pola StudentsExport (SIS-05): `FromQuery` supaya Maatwebsite
 * menelusuri hasilnya secara bertahap alih-alih memuat seluruh tagihan ke
 * memori sekaligus.
 */
class StudentFeesExport implements FromQuery, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithMapping
{
    public function __construct(protected Builder $query) {}

    public function query(): Builder
    {
        // Relasi dimuat sekali untuk seluruh hasil, bukan sekali per baris.
        // `studentClasses` dibatasi pada penempatan yang masih ACTIVE karena
        // hanya itu yang dipakai untuk menentukan kelas (lihat kelasUntuk()).
        return $this->query->with([
            'student',
            'student.studentClasses' => fn ($relation) => $relation
                ->where('status', StudentClassStatus::Active->value)
                ->with('schoolClass'),
        ]);
    }

    /**
     * Urutan persis seperti SPP-05 poin 1.
     *
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'Nama Siswa',
            'Kelas',
            'Periode',
            'Jumlah Tagihan',
            'Jumlah Bayar',
            'Sisa',
            'Status',
        ];
    }

    /**
     * Ketiga kolom nominal tetap sel angka, bukan teks "Rp ...": teks membuat
     * penjumlahan dan pengurutan di Excel berhenti bekerja, dan berkas laporan
     * memang dibuat untuk dihitung ulang penerimanya. Format rupiahnya dipasang
     * pada selnya lewat WithColumnFormatting.
     *
     * @param  StudentFee  $fee
     * @return array<int, mixed>
     */
    public function map($fee): array
    {
        return [
            $fee->student?->full_name,
            static::classNameFor($fee),
            $fee->period,
            (float) $fee->amount,
            (float) $fee->amount_paid,
            (float) $fee->remaining(),
            $fee->status instanceof StudentFeeStatus ? $fee->status->label() : (string) $fee->status,
        ];
    }

    /**
     * PhpSpreadsheet tidak menyediakan konstanta rupiah, jadi format selnya
     * ditulis langsung. Ini hanya tampilan — nilai yang tersimpan tetap angka,
     * sehingga tetap dapat dijumlahkan dan diurutkan di Excel.
     */
    public const CURRENCY_FORMAT = '"Rp" #,##0.00';

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return [
            'D' => self::CURRENCY_FORMAT,
            'E' => self::CURRENCY_FORMAT,
            'F' => self::CURRENCY_FORMAT,
        ];
    }

    /**
     * Kelas siswa **pada tahun ajaran tagihan ini**, bukan kelasnya sekarang.
     *
     * `student_fees` tidak menyimpan `class_id`, dan menampilkan penempatan
     * terakhir akan membuat laporan tagihan tahun lalu tertulis dengan kelas
     * tahun ini — siswa yang naik kelas akan terbaca seolah tagihan lamanya
     * milik rombel yang baru. `student_classes` sudah menyimpan
     * `academic_year_id`, jadi yang dicari adalah penempatan ACTIVE pada tahun
     * ajaran yang sama dengan tagihannya.
     *
     * Tagihan tanpa tahun ajaran (`academic_year_id` NULL — sah menurut ERD
     * untuk tagihan berulang) tidak punya tahun ajaran untuk dicocokkan, dan
     * kelasnya dibiarkan kosong alih-alih ditebak dari tahun lain. Lihat
     * butir 99.
     */
    public static function classNameFor(StudentFee $fee): ?string
    {
        if ($fee->academic_year_id === null) {
            return null;
        }

        return $fee->student?->studentClasses
            ->firstWhere('academic_year_id', $fee->academic_year_id)
            ?->schoolClass?->name;
    }
}
