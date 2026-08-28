<?php

namespace App\Filament\Resources\StudentFeeResource\RelationManagers;

use App\Enums\PaymentMethod;
use App\Filament\Resources\StudentFeeResource;
use App\Models\Payment;
use App\Services\Finance\PaymentProofAttacher;
use App\Services\Finance\PaymentRecorder;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * API 4.9 — GET /student-fees/{id}: "detail satu tagihan + riwayat
 * pembayaran"; SPP-04 poin 3: "riwayat pembayaran mencantumkan tanggal dan
 * metode bayar".
 *
 * Hanya membaca. `payments` adalah riwayat — ERD memberinya `created_at` saja,
 * dan API 4.9 tidak menyediakan PUT maupun DELETE untuknya — sehingga tidak ada
 * aksi ubah, hapus, maupun aksi massal di sini. Pencatatan pembayaran barunya
 * lewat aksi "Catat Pembayaran" pada tagihannya, satu-satunya jalur yang
 * menghitung ulang akumulasi dan status.
 */
class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Riwayat Pembayaran';

    protected static ?string $modelLabel = 'Pembayaran';

    protected static ?string $pluralModelLabel = 'Pembayaran';

    /**
     * @var array<string, string>
     */
    protected $listeners = [
        StudentFeeResource::PAYMENT_RECORDED_EVENT => '$refresh',
    ];

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('reference_number')
            ->columns([
                Tables\Columns\TextColumn::make('payment_date')
                    ->label(__('Tanggal'))
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('payment_method')
                    ->label(__('Metode'))
                    ->badge()
                    ->formatStateUsing(fn (PaymentMethod $state): string => $state->label()),

                Tables\Columns\TextColumn::make('amount_paid')
                    ->label(__('Jumlah'))
                    ->money('IDR'),

                Tables\Columns\TextColumn::make('reference_number')
                    ->label(__('Referensi'))
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('receivedBy.name')
                    ->label(__('Dicatat Oleh'))
                    ->placeholder('—'),

                Tables\Columns\IconColumn::make('proof_url')
                    ->label(__('Bukti'))
                    ->boolean()
                    ->state(fn (Payment $record): bool => filled($record->proof_url)),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('Dicatat Pada'))
                    ->dateTime('d M Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([])
            ->actions([
                static::downloadProofAction(),
                static::attachProofAction(),
            ])
            ->bulkActions([])
            // Cicilan terbaru di atas. `created_at` dipakai, bukan
            // `payment_date`: dua cicilan bisa bertanggal sama, dan urutan
            // pencatatannya yang menjelaskan bagaimana sisa tagihan bergerak.
            // `id` menjadi pemutus terakhir — dua cicilan yang tercatat pada
            // detik yang sama tetap harus punya urutan yang tetap.
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByDesc('created_at')
                ->orderByDesc('id'))
            ->emptyStateHeading(__('Belum ada pembayaran'))
            ->emptyStateDescription(__('Tagihan ini belum pernah menerima pembayaran.'));
    }

    /**
     * Bukti pembayaran tidak punya URL statis (disk privat, Security 3.4), dan
     * jalur penyimpanannya tidak pernah dibocorkan ke klien: yang dikirim hanya
     * isi berkasnya, kepada pengguna yang memang berwenang melihat pembayaran
     * ini di cabangnya sendiri.
     */
    /**
     * Melengkapi bukti pada pembayaran yang sudah tercatat.
     *
     * Keputusan implementasi Phase 1 yang disetujui (butir 113 nomor 9). Hanya
     * muncul bila pembayarannya memang **belum** punya bukti: bukti yang sudah
     * ada tidak dapat diganti, dan tidak ada aksi "Ganti Bukti" — baris yang
     * sudah lengkap hanya menawarkan Unduh Bukti.
     *
     * Formnya satu isian saja. Nominal, metode, tanggal, referensi, dan catatan
     * tidak dapat disentuh dari sini maupun dari mana pun (butir 119).
     */
    public static function attachProofAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('attachProof')
            ->label(__('Lampirkan Bukti'))
            ->icon('heroicon-o-paper-clip')
            ->color('gray')
            ->modalHeading(__('Lampirkan Bukti Pembayaran'))
            ->modalDescription(__('Hanya berkas buktinya yang ditambahkan. Nominal, metode, dan tanggal pembayaran tidak berubah.'))
            ->modalSubmitActionLabel(__('Lampirkan'))
            ->visible(fn (Payment $record): bool => blank($record->proof_url)
                && (Auth::user()?->can('attachProof', $record) ?? false))
            ->form([
                Forms\Components\FileUpload::make('proof')
                    ->label(__('Bukti Pembayaran'))
                    ->required()
                    // Disk privat, aturan yang sama dengan pengunggahan saat
                    // pencatatan pembayaran.
                    ->disk(PaymentRecorder::PROOF_DISK)
                    ->directory(fn (Payment $record): string => PaymentRecorder::proofDirectory((int) $record->school_id))
                    ->visibility('private')
                    ->acceptedFileTypes(PaymentRecorder::PROOF_MIME_TYPES)
                    ->maxSize(PaymentRecorder::PROOF_MAX_KILOBYTES)
                    ->helperText(__('JPG/PNG/PDF, maksimal 5 MB.')),
            ])
            ->action(function (Payment $record, array $data): void {
                $user = Auth::user();

                if ($user === null) {
                    return;
                }

                app(PaymentProofAttacher::class)->attachStoredPath(
                    $record->getKey(),
                    $data['proof'] ?? null,
                    $user,
                );

                Notification::make()
                    ->title(__('Bukti pembayaran dilampirkan'))
                    ->success()
                    ->send();
            });
    }

    public static function downloadProofAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('downloadProof')
            ->label(__('Unduh Bukti'))
            ->icon('heroicon-o-arrow-down-tray')
            ->visible(fn (Payment $record): bool => $record->hasDownloadableProof()
                && (Auth::user()?->can('downloadProof', $record) ?? false))
            ->action(fn (Payment $record): StreamedResponse => Storage::disk(PaymentRecorder::PROOF_DISK)
                ->download($record->proof_url, static::proofFilenameFor($record)));
    }

    /**
     * Nama berkas unduhan dibentuk dari data pembayaran, bukan dari nama
     * unggahan asli: nama itu berasal dari pengguna dan tidak pernah disimpan.
     */
    public static function proofFilenameFor(Payment $payment): string
    {
        $extension = pathinfo((string) $payment->proof_url, PATHINFO_EXTENSION);

        return 'bukti-pembayaran-'.$payment->getKey().($extension === '' ? '' : ".{$extension}");
    }

    /**
     * Menutup seluruh aksi bawaan Filament yang menulis — create, edit,
     * delete, dan padanan massalnya — tanpa bergantung pada opsi panel yang
     * kebetulan aktif.
     */
    public function isReadOnly(): bool
    {
        return true;
    }

    protected function canCreate(): bool
    {
        return false;
    }

    public static function getModelLabel(): string
    {
        return __(static::$modelLabel);
    }

    public static function getPluralModelLabel(): string
    {
        return __(static::$pluralModelLabel);
    }
}
