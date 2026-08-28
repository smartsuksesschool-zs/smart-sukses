<?php

namespace App\Filament\Resources\TransactionResource\Pages;

use App\Enums\TransactionType;
use App\Filament\Resources\TransactionResource;
use App\Models\School;
use App\Models\Transaction;
use App\Services\Finance\CashLedgerExporter;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * API 4.9 — GET /transactions, dan GET /finance/export.
 */
class ListTransactions extends ListRecords
{
    protected static string $resource = TransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label(__('Catat Transaksi')),
            static::exportAction(),
        ];
    }

    /**
     * API 4.9.2 — GET /finance/export.
     *
     * Filternya dipilih di modal, bukan diambil dari filter tabel: laporan ini
     * adalah berkas yang keluar dari sistem, dan operator harus melihat persis
     * rentang serta cabang yang sedang diekspor sebelum menekan tombolnya.
     * Pola sama dengan ekspor laporan tagihan (SPP-05).
     */
    public static function exportAction(): Actions\Action
    {
        return Actions\Action::make('export')
            ->label(__('Export Excel'))
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->modalHeading(__('Export Buku Kas'))
            ->modalDescription(__('Berkas .xlsx berisi tanggal, jenis, kategori, jumlah, keterangan, nomor referensi, dan pencatat. Tagihan SPP tidak termasuk — laporan itu diekspor dari halaman Tagihan Siswa.'))
            ->modalSubmitActionLabel(__('Unduh'))
            // Disembunyikan bila tidak berwenang — tetapi pagarnya ada di
            // CashLedgerExporter, yang memeriksa izin yang sama pada jalur yang
            // benar-benar menghasilkan berkas.
            ->visible(fn (): bool => Auth::user()?->can('export', Transaction::class) ?? false)
            ->form(static::exportFormSchema())
            ->action(fn (array $data): ?BinaryFileResponse => static::handleExport($data));
    }

    /**
     * @return array<int, Forms\Components\Component>
     */
    public static function exportFormSchema(): array
    {
        return [
            // KAS-03 sudah menjawab kebutuhan lintas cabang; laporan ini satu
            // cabang, jadi Super Admin memilih cabangnya lebih dulu alih-alih
            // mengunduh seluruh cabang tanpa menyadarinya.
            Forms\Components\Select::make('school_id')
                ->label(__('Cabang Sekolah'))
                ->options(fn (): array => School::query()
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->preload()
                ->required()
                ->live()
                ->afterStateUpdated(fn (Forms\Set $set) => $set('category', null))
                ->visible(fn (): bool => Auth::user()?->isSuperAdmin() ?? false)
                ->helperText(__('Laporan berisi satu cabang.')),

            Forms\Components\DatePicker::make('date_from')
                ->label(__('Dari Tanggal'))
                ->required()
                ->default(now()->startOfMonth())
                ->helperText(__('Rentang wajib diisi supaya tidak seluruh riwayat ikut terunduh.')),

            Forms\Components\DatePicker::make('date_until')
                ->label(__('Sampai Tanggal'))
                ->required()
                ->default(now()->endOfMonth())
                ->afterOrEqual('date_from')
                ->validationMessages([
                    'after_or_equal' => 'Tanggal akhir tidak boleh mendahului tanggal mulai.',
                ]),

            Forms\Components\Select::make('type')
                ->label(__('Jenis'))
                ->options(TransactionType::options())
                ->placeholder(__('Pemasukan dan pengeluaran'))
                ->rule(Rule::enum(TransactionType::class)),

            // ERD: `category` VARCHAR bebas. Daftarnya hanya saran dari
            // kategori yang benar-benar dipakai cabang ini.
            Forms\Components\Select::make('category')
                ->label(__('Kategori'))
                ->options(fn (Forms\Get $get): array => app(CashLedgerExporter::class)
                    ->categoryOptions(static::resolveSchoolId($get('school_id'))))
                ->searchable()
                ->placeholder(__('Semua kategori')),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function handleExport(array $data): ?BinaryFileResponse
    {
        $user = Auth::user();

        if ($user === null) {
            return null;
        }

        return app(CashLedgerExporter::class)->download($user, $data);
    }

    /**
     * Cabang laporan. Nilai form hanya dipercaya dari Super Admin — bagi peran
     * School Level field itu tidak pernah dirender, sehingga apa pun yang
     * muncul di state Livewire diabaikan. CashLedgerExporter memeriksanya ulang
     * di jalur yang menghasilkan berkas.
     */
    public static function resolveSchoolId(mixed $formValue = null): ?int
    {
        $user = Auth::user();

        if ($user?->isSuperAdmin()) {
            return filled($formValue) && is_numeric($formValue) ? (int) $formValue : null;
        }

        return $user?->school_id;
    }
}
