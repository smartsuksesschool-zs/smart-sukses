<?php

namespace App\Filament\Resources;

use App\Enums\TransactionType;
use App\Filament\Resources\TransactionResource\Pages;
use App\Models\School;
use App\Models\Transaction;
use App\Services\Finance\TransactionRecorder;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * KAS-01 — "Sebagai Bendahara, saya dapat mencatat pemasukan dan pengeluaran
 * kas sekolah"; API 4.9 — GET/POST /transactions dan PUT /transactions/{id}.
 *
 * Tidak ada aksi hapus, dan itu bukan kelalaian: API 4.9 memang menyebut
 * DELETE /transactions/{id} sebagai "soft delete", tetapi ERD tidak memuat satu
 * pun kolom yang dapat menyimpannya dan blueprint tidak menjelaskan
 * mekanismenya. Lihat docs/implementation-notes.md butir 74.
 *
 * Buku kas ini juga tidak terhubung ke `payments`: ERD menyebutnya kas
 * "**di luar** tagihan SPP", dan rekonsiliasinya belum dijelaskan (butir 75).
 */
class TransactionResource extends Resource
{
    protected static ?string $model = Transaction::class;

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationGroup = 'Keuangan';

    protected static ?string $navigationLabel = 'Buku Kas';

    protected static ?string $modelLabel = 'Transaksi Kas';

    protected static ?string $pluralModelLabel = 'Transaksi Kas';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'category';

    public static function form(Form $form): Form
    {
        return $form->schema([
            // Super Admin tidak memiliki school_id (Arsitektur 3.2.2), sehingga
            // cabang harus dipilih eksplisit. Pola sama dengan FeeTypeResource
            // dan GenerateTagihan.
            Forms\Components\Select::make('school_id')
                ->label(__('Cabang Sekolah'))
                ->options(fn (): array => School::query()
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->preload()
                ->required()
                ->visible(fn (): bool => Auth::user()?->isSuperAdmin() ?? false)
                // Memindahkan transaksi antar cabang berarti memindahkan uang
                // yang sudah tercatat di buku kas cabang lain.
                ->disabledOn('edit')
                ->columnSpanFull()
                ->helperText(__('Transaksi tercatat pada buku kas cabang ini.')),

            Forms\Components\Select::make('type')
                ->label(__('Jenis'))
                ->options(TransactionType::options())
                ->default(TransactionType::Income->value)
                ->required()
                ->native(false)
                // Opsi sudah terbatas, tetapi state Livewire bisa dikirim apa
                // adanya — pagar berikutnya di validasi, pagar terakhirnya di
                // TransactionRecorder.
                ->rule(Rule::enum(TransactionType::class)),

            // ERD: `category` VARCHAR(100) — teks bebas, bukan enum. Contoh
            // pada ERD ditawarkan sebagai saran, bukan sebagai batasan.
            Forms\Components\TextInput::make('category')
                ->label(__('Kategori'))
                ->required()
                ->maxLength(100)
                ->datalist(static::categorySuggestions())
                ->helperText(__('Boleh kategori lain di luar saran, maksimal 100 karakter.')),

            Forms\Components\TextInput::make('amount')
                ->label(__('Jumlah'))
                ->prefix('Rp')
                ->numeric()
                ->required()
                ->step(0.01)
                ->rule('gt:0')
                // ERD: DECIMAL(12,2).
                ->maxValue(9999999999.99)
                ->validationMessages([
                    'gt' => 'Jumlah harus lebih besar dari 0.',
                ])
                ->helperText(__('Selalu positif; pemasukan atau pengeluaran ditentukan oleh Jenis.')),

            Forms\Components\DatePicker::make('transaction_date')
                ->label(__('Tanggal Transaksi'))
                ->required()
                ->default(now()),

            // Wajib menurut aturan validasi KAS-01, walaupun kolomnya NULL di
            // ERD: nullability database dan aturan alur kerja adalah dua hal
            // berbeda, dan skemanya tidak diubah untuk ini (butir 81).
            Forms\Components\TextInput::make('reference_number')
                ->label(__('Nomor Referensi'))
                ->required()
                ->maxLength(100)
                ->helperText(__('Nomor nota atau kuitansi yang menjadi dasar transaksi ini.')),

            Forms\Components\FileUpload::make('proof_url')
                ->label(__('Bukti Transaksi'))
                // Disk privat: berkas ini tidak boleh punya URL statis.
                ->disk(TransactionRecorder::PROOF_DISK)
                ->directory(fn (?Transaction $record): string => TransactionRecorder::proofDirectory(
                    (int) ($record?->school_id ?? Auth::user()?->school_id ?? 0),
                ))
                ->visibility('private')
                ->acceptedFileTypes(TransactionRecorder::PROOF_MIME_TYPES)
                ->maxSize(TransactionRecorder::PROOF_MAX_KILOBYTES)
                ->helperText(__('Scan nota/kwitansi — JPG/PNG/PDF, maksimal 5 MB.'))
                ->columnSpanFull(),

            Forms\Components\Textarea::make('description')
                ->label(__('Keterangan'))
                ->required()
                ->rows(3)
                ->columnSpanFull()
                ->helperText(__('Penjelasan detail transaksi — wajib diisi.')),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('transaction_date')
                    ->label(__('Tanggal'))
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('type')
                    ->label(__('Jenis'))
                    ->badge()
                    ->formatStateUsing(fn (TransactionType $state): string => $state->label())
                    ->color(fn (TransactionType $state): string => $state->color())
                    ->sortable(),

                Tables\Columns\TextColumn::make('category')
                    ->label(__('Kategori'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label(__('Jumlah'))
                    ->money('IDR')
                    ->sortable(),

                Tables\Columns\TextColumn::make('reference_number')
                    ->label(__('Referensi'))
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('description')
                    ->label(__('Keterangan'))
                    ->placeholder('—')
                    ->limit(40)
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('createdBy.name')
                    ->label(__('Pencatat'))
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('school.name')
                    ->label(__('Cabang'))
                    ->visible(fn (): bool => Auth::user()?->isSuperAdmin() ?? false)
                    ->toggleable(),
            ])
            ->filters([
                // API 4.9 — GET /transactions, "Filter: type, category,
                // date_from, date_to".
                Tables\Filters\SelectFilter::make('type')
                    ->label(__('Jenis'))
                    ->options(TransactionType::options()),

                Tables\Filters\Filter::make('transaction_date')
                    ->label(__('Rentang Tanggal'))
                    ->form([
                        Forms\Components\DatePicker::make('from')->label(__('Dari Tanggal')),
                        Forms\Components\DatePicker::make('until')->label(__('Sampai Tanggal')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->betweenDates(
                        $data['from'] ?? null,
                        $data['until'] ?? null,
                    )),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                static::deleteAction(),
            ])
            // Tetap tidak ada penghapusan massal. Yang diminta API adalah
            // menghapus satu transaksi; satu klik yang menghapus sekaligus
            // seluruh halaman buku kas adalah kesalahan yang jauh lebih mahal,
            // dan tidak ada satu pun dokumen yang memintanya (butir 131).
            ->bulkActions([])
            // `id` sebagai pemutus: banyak transaksi berbagi tanggal yang sama,
            // dan tanpa urutan yang tetap paginasinya dapat menampilkan baris
            // yang sama dua kali.
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByDesc('transaction_date')
                ->orderByDesc('id'));
    }

    /**
     * API 4.9.2 — DELETE /transactions/{id}: "hapus transaksi (soft delete)".
     *
     * Tombolnya hanya muncul bagi yang benar-benar berwenang, dan itu lebih
     * sempit daripada yang boleh mencatat: Bendahara mencatat dan mengoreksi,
     * Admin Sekolah yang menghapus (butir 129). Penyembunyian tombol bukan
     * proteksinya — TransactionRecorder::delete() memeriksa ulang izin yang
     * sama pada jalur yang benar-benar mengubah data.
     *
     * Penghapusannya melalui service, bukan `DeleteAction` bawaan, supaya
     * jalur panel dan jalur API menghapus dengan aturan yang sama persis.
     */
    protected static function deleteAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('softDelete')
            ->label(__('Hapus'))
            ->icon('heroicon-m-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('Hapus transaksi kas'))
            ->modalDescription(
                'Transaksi tidak akan lagi muncul di buku kas, saldo, laporan, maupun ekspor. '
                .'Datanya tetap tersimpan untuk keperluan audit, tetapi tidak ada cara '
                .'mengembalikannya sendiri dari halaman ini.'
            )
            ->modalSubmitActionLabel(__('Hapus transaksi'))
            ->visible(fn (Transaction $record): bool => Auth::user()?->can('delete', $record) ?? false)
            ->action(function (Transaction $record): void {
                try {
                    app(TransactionRecorder::class)->delete($record->getKey(), Auth::user());
                } catch (AuthorizationException|ValidationException $e) {
                    Notification::make()
                        ->title(__('Transaksi tidak dapat dihapus'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('Transaksi dihapus'))
                    ->success()
                    ->send();
            });
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make(__('Transaksi'))
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('transaction_date')
                        ->label(__('Tanggal'))
                        ->date('d M Y'),
                    Infolists\Components\TextEntry::make('type')
                        ->label(__('Jenis'))
                        ->badge()
                        ->formatStateUsing(fn (TransactionType $state): string => $state->label())
                        ->color(fn (TransactionType $state): string => $state->color()),
                    Infolists\Components\TextEntry::make('category')->label(__('Kategori')),
                    Infolists\Components\TextEntry::make('amount')->label(__('Jumlah'))->money('IDR'),
                    Infolists\Components\TextEntry::make('reference_number')
                        ->label(__('Nomor Referensi'))
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('createdBy.name')
                        ->label(__('Dicatat Oleh'))
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('created_at')
                        ->label(__('Dicatat Pada'))
                        ->dateTime('d M Y H:i'),
                    Infolists\Components\TextEntry::make('school.name')
                        ->label(__('Cabang'))
                        ->visible(fn (): bool => Auth::user()?->isSuperAdmin() ?? false),
                    Infolists\Components\TextEntry::make('description')
                        ->label(__('Keterangan'))
                        ->placeholder('—')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /**
     * Bukti transaksi tidak punya URL statis (disk privat, Security 3.4), dan
     * jalur penyimpanannya tidak dibocorkan ke klien: yang dikirim hanya isi
     * berkasnya, kepada pengguna yang memang berwenang melihat transaksi ini di
     * cabangnya sendiri.
     */
    public static function downloadProofAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('downloadProof')
            ->label(__('Unduh Bukti'))
            ->icon('heroicon-o-arrow-down-tray')
            ->visible(fn (Transaction $record): bool => $record->hasDownloadableProof()
                && (Auth::user()?->can('downloadProof', $record) ?? false))
            ->action(fn (Transaction $record): StreamedResponse => Storage::disk(TransactionRecorder::PROOF_DISK)
                ->download($record->proof_url, static::proofFilenameFor($record)));
    }

    /**
     * Nama berkas unduhan dibentuk dari data transaksi, bukan dari nama
     * unggahan asli: nama itu berasal dari pengguna dan tidak pernah disimpan.
     */
    public static function proofFilenameFor(Transaction $transaction): string
    {
        $extension = pathinfo((string) $transaction->proof_url, PATHINFO_EXTENSION);

        return 'bukti-transaksi-'.$transaction->getKey().($extension === '' ? '' : ".{$extension}");
    }

    /**
     * ERD: "Kategori: Gaji, Pembelian Alat, Dana BOS, Sumbangan, dll." — "dll."
     * yang menentukan. Daftar ini hanya saran ketik; kategori lain tetap
     * diterima (butir 77).
     *
     * @return array<int, string>
     */
    public static function categorySuggestions(): array
    {
        return ['Gaji', 'Pembelian Alat', 'Dana BOS', 'Sumbangan'];
    }

    /**
     * Setiap baris menampilkan nama pencatatnya; tanpa eager load itu menjadi
     * satu query per baris. `school` ikut hanya karena kolom cabang dirender
     * untuk Super Admin.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['createdBy', 'school']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTransactions::route('/'),
            'create' => Pages\CreateTransaction::route('/create'),
            'view' => Pages\ViewTransaction::route('/{record}'),
            'edit' => Pages\EditTransaction::route('/{record}/edit'),
        ];
    }

    public static function getModelLabel(): string
    {
        return __(static::$modelLabel);
    }

    public static function getNavigationGroup(): ?string
    {
        return __(static::$navigationGroup);
    }

    public static function getNavigationLabel(): string
    {
        return __(static::$navigationLabel);
    }

    public static function getPluralModelLabel(): string
    {
        return __(static::$pluralModelLabel);
    }
}
