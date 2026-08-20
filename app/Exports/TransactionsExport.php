<?php

namespace App\Exports;

use App\Enums\TransactionType;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * API 4.9.2 — GET /finance/export: "Export laporan keuangan ke Excel".
 *
 * Isinya buku kas (`transactions`) satu cabang, bukan gabungan dengan tagihan
 * SPP: endpoint ini berada di bagian "Akuntansi & Kas" pada API map, sedangkan
 * ekspor tagihan punya endpointnya sendiri di bagian "Tagihan & Pembayaran SPP"
 * (SPP-05). Lihat docs/implementation-notes.md butir 106.
 *
 * Mengikuti pola StudentFeesExport dan StudentsExport: `FromQuery` supaya
 * Maatwebsite menelusuri hasilnya bertahap alih-alih memuat seluruh buku kas ke
 * memori sekaligus.
 */
class TransactionsExport implements FromQuery, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithMapping
{
    /**
     * PhpSpreadsheet tidak menyediakan konstanta rupiah, jadi format selnya
     * ditulis langsung. Ini hanya tampilan — nilai yang tersimpan tetap angka.
     */
    public const CURRENCY_FORMAT = '"Rp" #,##0.00';

    public function __construct(protected Builder $query) {}

    public function query(): Builder
    {
        // Pencatatnya dimuat sekali untuk seluruh hasil, bukan sekali per baris.
        return $this->query->with('createdBy');
    }

    /**
     * Kolom minimal yang memang sudah ada di ERD `transactions` dan berguna
     * sebagai buku kas. Tidak ada saldo berjalan, nomor jurnal, maupun akun
     * debit/kredit — blueprint tidak memuat satu pun dari ketiganya (butir 107).
     *
     * `proof_url` sengaja tidak diekspor: jalur penyimpanan berkas privat tidak
     * boleh keluar dari sistem di dalam berkas yang dapat diteruskan ke siapa
     * pun (butir 108).
     *
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'Tanggal',
            'Jenis',
            'Kategori',
            'Jumlah',
            'Keterangan',
            'Nomor Referensi',
            'Dicatat Oleh',
        ];
    }

    /**
     * Nominal tetap positif untuk INCOME maupun EXPENSE; arah kasnya dibaca
     * dari kolom Jenis, persis seperti yang tersimpan (butir 78).
     *
     * @param  Transaction  $transaction
     * @return array<int, mixed>
     */
    public function map($transaction): array
    {
        return [
            // Ditulis sebagai teks `Y-m-d`, mengikuti StudentsExport (SIS-05):
            // formatnya sudah tidak ambigu dan terurut secara leksikal, dan
            // tidak ada pola sel tanggal Excel di project ini yang perlu
            // diikuti.
            $transaction->transaction_date?->format('Y-m-d'),
            $transaction->type instanceof TransactionType
                ? $transaction->type->label()
                : (string) $transaction->type,
            $transaction->category,
            (float) $transaction->amount,
            $transaction->description,
            $transaction->reference_number,
            $transaction->createdBy?->name,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return [
            'D' => self::CURRENCY_FORMAT,
        ];
    }
}
