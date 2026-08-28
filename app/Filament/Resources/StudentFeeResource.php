<?php

namespace App\Filament\Resources;

use App\Enums\StudentFeeStatus;
use App\Filament\Resources\StudentFeeResource\Pages;
use App\Filament\Resources\StudentFeeResource\RelationManagers;
use App\Models\FeeType;
use App\Models\Payment;
use App\Models\StudentFee;
use App\Services\Finance\PaymentRecorder;
use App\Services\Finance\StudentFeeWaiver;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * API 4.9 — GET /student-fees dan GET /student-fees/{id} ("detail satu tagihan
 * + riwayat pembayaran"), serta POST /payments (SPP-03).
 *
 * Resource ini sengaja hanya membaca. Tagihan lahir dari penerbitan massal
 * (SPP-02) dan berubah hanya karena pembayaran (SPP-03) — tidak ada satu pun
 * user story yang meminta tagihan dibuat, diedit, atau dihapus satu per satu,
 * dan menyediakan jalur itu berarti membuat `amount`/`status` dapat menyimpang
 * dari riwayat `payments` yang seharusnya menjelaskannya.
 */
class StudentFeeResource extends Resource
{
    protected static ?string $model = StudentFee::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Keuangan';

    protected static ?string $navigationLabel = 'Tagihan Siswa';

    protected static ?string $modelLabel = 'Tagihan Siswa';

    protected static ?string $pluralModelLabel = 'Tagihan Siswa';

    protected static ?int $navigationSort = 3;

    /**
     * Sinyal Livewire dari halaman detail ke tabel riwayat pembayaran.
     */
    public const PAYMENT_RECORDED_EVENT = 'paymentRecorded';

    /**
     * Tidak ada jalur pembuatan satu-per-satu. `StudentFeePolicy::create()`
     * tetap dipakai, tetapi maknanya adalah kewenangan menerbitkan massal di
     * halaman Generate Tagihan — bukan tombol "New" di sini.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('student.full_name')
                    ->label(__('Siswa'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('student.nis')
                    ->label(__('NIS'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('feeType.name')
                    ->label(__('Jenis Tagihan'))
                    ->sortable(),

                Tables\Columns\TextColumn::make('period')
                    ->label(__('Periode'))
                    ->sortable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label(__('Nominal'))
                    ->money('IDR')
                    ->sortable(),

                Tables\Columns\TextColumn::make('amount_paid')
                    ->label(__('Terbayar'))
                    ->money('IDR')
                    ->sortable(),

                // Turunan, bukan kolom: menyimpannya berarti menambah angka
                // ketiga yang bisa menyimpang dari dua angka lainnya. Karena
                // itu pula ia tidak dapat diurutkan di database.
                Tables\Columns\TextColumn::make('remaining')
                    ->label(__('Sisa'))
                    ->money('IDR')
                    ->state(fn (StudentFee $record): string => PaymentRecorder::remainingFor($record)),

                Tables\Columns\TextColumn::make('due_date')
                    ->label(__('Jatuh Tempo'))
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (StudentFeeStatus $state): string => $state->label())
                    ->color(fn (StudentFeeStatus $state): string => $state->color())
                    ->sortable(),

                Tables\Columns\TextColumn::make('school.name')
                    ->label(__('Cabang'))
                    ->visible(fn (): bool => Auth::user()?->isSuperAdmin() ?? false)
                    ->toggleable(),
            ])
            ->filters([
                // API 4.9 menyebut filter yang didukung persis: student_id,
                // status, period, fee_type_id.
                Tables\Filters\SelectFilter::make('period')
                    ->label(__('Periode'))
                    ->options(fn (): array => static::periodOptions()),

                Tables\Filters\SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(StudentFeeStatus::options()),

                Tables\Filters\SelectFilter::make('fee_type_id')
                    ->label(__('Jenis Tagihan'))
                    ->options(fn (): array => static::feeTypeOptions()),

                Tables\Filters\SelectFilter::make('student_id')
                    ->label(__('Siswa'))
                    ->relationship('student', 'full_name')
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                static::recordPaymentAction(),
                static::waiveAction(),
            ])
            // Tagihan tidak dihapus dan tidak diubah massal.
            ->bulkActions([])
            // `id` sebagai pemutus: tagihan satu periode berbagi jatuh tempo
            // yang sama, dan tanpa urutan yang tetap paginasinya dapat
            // menampilkan baris yang sama dua kali.
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByDesc('due_date')
                ->orderByDesc('id'));
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make(__('Tagihan'))
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('student.full_name')->label(__('Siswa')),
                    Infolists\Components\TextEntry::make('student.nis')->label(__('NIS'))->placeholder('—'),
                    Infolists\Components\TextEntry::make('feeType.name')->label(__('Jenis Tagihan')),
                    Infolists\Components\TextEntry::make('period')->label(__('Periode')),
                    Infolists\Components\TextEntry::make('due_date')->label(__('Jatuh Tempo'))->date('d M Y'),
                    Infolists\Components\TextEntry::make('academicYear.name')
                        ->label(__('Tahun Ajaran'))
                        ->placeholder(__('Berulang')),
                    Infolists\Components\TextEntry::make('status')
                        ->label(__('Status'))
                        ->badge()
                        ->formatStateUsing(fn (StudentFeeStatus $state): string => $state->label())
                        ->color(fn (StudentFeeStatus $state): string => $state->color()),
                    Infolists\Components\TextEntry::make('school.name')
                        ->label(__('Cabang'))
                        ->visible(fn (): bool => Auth::user()?->isSuperAdmin() ?? false),
                ]),

            Infolists\Components\Section::make(__('Nominal'))
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('amount')->label(__('Nominal Tagihan'))->money('IDR'),
                    Infolists\Components\TextEntry::make('amount_paid')->label(__('Sudah Dibayar'))->money('IDR'),
                    Infolists\Components\TextEntry::make('remaining')
                        ->label(__('Sisa Tagihan'))
                        ->money('IDR')
                        ->state(fn (StudentFee $record): string => $record->remaining()),
                ]),

            // Bagiannya sendiri, dan hanya untuk tagihan yang memang
            // dibebaskan: alasan pembebasan adalah penjelasan mengapa tagihan
            // ini tidak akan pernah tertagih, bukan catatan tambahan pada
            // nominalnya.
            Infolists\Components\Section::make(__('Pembebasan Tagihan'))
                ->visible(fn (StudentFee $record): bool => $record->isWaived() || filled($record->waive_reason))
                ->schema([
                    Infolists\Components\TextEntry::make('waive_reason')
                        ->label(__('Alasan Pembebasan'))
                        ->placeholder('—')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /**
     * SPP-03 — "mencatat pembayaran siswa secara manual (cash atau transfer)".
     *
     * Formnya hanya mengumpulkan isian. Siapa yang mencatat, untuk siswa mana,
     * dan di cabang mana tidak pernah ikut dikirim: PaymentRecorder
     * menurunkannya dari tagihan dan dari sesi.
     */
    public static function recordPaymentAction(): Tables\Actions\Action
    {
        return static::configureRecordPaymentAction(Tables\Actions\Action::make('recordPayment'));
    }

    /**
     * Aksi yang sama untuk header halaman detail. Filament memisahkan aksi
     * tabel dan aksi halaman ke dua kelas berbeda; konfigurasinya tetap satu.
     */
    public static function recordPaymentPageAction(): Actions\Action
    {
        return static::configureRecordPaymentAction(Actions\Action::make('recordPayment'));
    }

    /**
     * @template T of Tables\Actions\Action|Actions\Action
     *
     * @param  T  $action
     * @return T
     */
    protected static function configureRecordPaymentAction(Tables\Actions\Action|Actions\Action $action): Tables\Actions\Action|Actions\Action
    {
        return $action
            ->label(__('Catat Pembayaran'))
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->modalHeading(__('Catat Pembayaran'))
            ->modalSubmitActionLabel(__('Simpan Pembayaran'))
            // Aksinya disembunyikan bila tidak berwenang, tetapi itu bukan
            // proteksinya: PaymentRecorder::authorize() memeriksa ulang izin
            // yang sama pada jalur tulis.
            ->visible(fn (StudentFee $record): bool => static::canRecordPaymentFor($record))
            ->form(fn (StudentFee $record): array => static::paymentFormSchema($record))
            ->action(fn (StudentFee $record, array $data) => static::handleRecordPayment($record, $data));
    }

    public static function handleRecordPayment(StudentFee $record, array $data): void
    {
        $user = Auth::user();

        if ($user === null) {
            return;
        }

        $payment = app(PaymentRecorder::class)->record($record->getKey(), $data, $user);

        $record->refresh();

        Notification::make()
            ->title(__('Pembayaran tercatat'))
            ->body(sprintf(
                'Rp %s dicatat untuk %s. Status tagihan sekarang: %s.',
                number_format((float) $payment->amount_paid, 0, ',', '.'),
                $record->student?->full_name ?? 'siswa',
                $record->status->label(),
            ))
            ->success()
            ->send();
    }

    /**
     * @return array<int, Forms\Components\Component>
     */
    public static function paymentFormSchema(StudentFee $record): array
    {
        $remaining = PaymentRecorder::remainingFor($record);

        return [
            // Konteks tagihan ditampilkan, bukan dikirim: nilai-nilai ini tidak
            // punya nama field dan karena itu tidak dapat diselundupkan sebagai
            // payload.
            Forms\Components\Placeholder::make('student_context')
                ->label(__('Siswa'))
                ->content(fn (): string => $record->student?->full_name ?? '—'),

            Forms\Components\Placeholder::make('period_context')
                ->label(__('Periode'))
                ->content(fn (): string => $record->feeType?->name
                    ? "{$record->feeType->name} — {$record->period}"
                    : $record->period),

            Forms\Components\Placeholder::make('remaining_context')
                ->label(__('Sisa Tagihan'))
                ->content(fn (): string => 'Rp '.number_format((float) $remaining, 0, ',', '.')),

            Forms\Components\Select::make('payment_method')
                ->label(__('Metode Pembayaran'))
                // SPP-03 Phase 1: "cash atau transfer". PAYMENT_GATEWAY ada di
                // ERD tetapi integrasinya Phase 2, sehingga tidak selectable.
                ->options(PaymentRecorder::methodOptions())
                ->required()
                // Opsi sudah terbatas, tetapi state Livewire bisa dikirim apa
                // adanya — pagar berikutnya di validasi, pagar terakhirnya di
                // PaymentRecorder::resolveMethod().
                ->rule(Rule::in(array_keys(PaymentRecorder::methodOptions()))),

            Forms\Components\TextInput::make('amount')
                ->label(__('Jumlah Dibayar'))
                ->prefix('Rp')
                ->numeric()
                ->required()
                ->step(0.01)
                ->rule('gt:0')
                ->rule("lte:{$remaining}")
                ->validationMessages([
                    'gt' => 'Jumlah pembayaran harus lebih besar dari 0.',
                    'lte' => 'Jumlah tidak boleh melebihi sisa tagihan.',
                ])
                ->helperText(__('Boleh dicicil: masukkan jumlah yang benar-benar diterima kali ini.')),

            Forms\Components\DatePicker::make('payment_date')
                ->label(__('Tanggal Pembayaran'))
                ->required()
                ->default(now())
                ->maxDate(now()),

            // ERD: `reference_number` VARCHAR(100) NULL. SPP-03 menyebutnya
            // sebagai salah satu isian form, bukan sebagai isian wajib — dan
            // pembayaran tunai memang tidak punya nomor transfer.
            Forms\Components\TextInput::make('reference_number')
                ->label(__('Nomor Referensi'))
                ->maxLength(100)
                ->helperText(__('Nomor transfer atau kwitansi, bila ada.')),

            Forms\Components\FileUpload::make('proof_url')
                ->label(__('Bukti Pembayaran'))
                // Disk privat: berkas ini tidak boleh punya URL statis.
                ->disk(PaymentRecorder::PROOF_DISK)
                ->directory(PaymentRecorder::proofDirectory((int) $record->school_id))
                ->visibility('private')
                ->acceptedFileTypes(PaymentRecorder::PROOF_MIME_TYPES)
                ->maxSize(PaymentRecorder::PROOF_MAX_KILOBYTES)
                ->helperText(__('JPG/PNG/PDF, maksimal 5 MB.'))
                ->columnSpanFull(),

            Forms\Components\Textarea::make('notes')
                ->label(__('Catatan'))
                ->rows(2)
                ->columnSpanFull(),
        ];
    }

    /**
     * Tagihan yang sudah lunas tidak punya sisa untuk dibayar, dan tagihan
     * yang dibebaskan tidak boleh diam-diam dihidupkan lagi lewat pembayaran.
     * Keduanya ditolak juga oleh PaymentRecorder.
     */
    public static function canRecordPaymentFor(StudentFee $record): bool
    {
        return (Auth::user()?->can('create', Payment::class) ?? false)
            && ! $record->isWaived()
            && bccomp($record->remaining(), '0', 2) > 0;
    }

    /**
     * API 4.9 — PATCH /student-fees/{id}/waive.
     *
     * Formnya hanya satu isian: alasannya. Status tidak pernah dipilih manual —
     * "status → WAIVED" adalah akibat aksinya, bukan masukan yang bisa dikirim
     * pengguna.
     */
    public static function waiveAction(): Tables\Actions\Action
    {
        return static::configureWaiveAction(Tables\Actions\Action::make('waive'));
    }

    public static function waivePageAction(): Actions\Action
    {
        return static::configureWaiveAction(Actions\Action::make('waive'));
    }

    /**
     * @template T of Tables\Actions\Action|Actions\Action
     *
     * @param  T  $action
     * @return T
     */
    protected static function configureWaiveAction(Tables\Actions\Action|Actions\Action $action): Tables\Actions\Action|Actions\Action
    {
        return $action
            ->label(__('Bebaskan Tagihan'))
            ->icon('heroicon-o-hand-raised')
            ->color('gray')
            ->modalHeading(__('Bebaskan Tagihan'))
            ->modalDescription('Tagihan akan berstatus DIBEBASKAN dan tidak akan tertagih lagi. '
                .'Nominal, riwayat pembayaran, dan jejak auditnya tidak berubah.')
            ->modalSubmitActionLabel(__('Bebaskan'))
            ->requiresConfirmation()
            // Disembunyikan bila tidak berwenang atau keadaannya tidak
            // memungkinkan — tetapi pagarnya ada di StudentFeeWaiver, yang
            // memeriksa keduanya lagi di jalur tulis.
            ->visible(fn (StudentFee $record): bool => static::canWaive($record))
            ->form(static::waiveFormSchema())
            ->action(fn (StudentFee $record, array $data) => static::handleWaive($record, $data));
    }

    /**
     * Satu isian saja. Status tidak pernah menjadi field: "status → WAIVED"
     * adalah akibat aksinya, dan menyediakannya sebagai masukan berarti
     * membiarkan pengguna memilih status tagihan secara sembarang.
     *
     * @return array<int, Forms\Components\Component>
     */
    public static function waiveFormSchema(): array
    {
        return [
            Forms\Components\Textarea::make('waive_reason')
                ->label(__('Alasan Pembebasan'))
                ->required()
                // ERD: `waive_reason` VARCHAR(200).
                ->maxLength(StudentFeeWaiver::REASON_MAX_LENGTH)
                ->rows(3)
                ->helperText('Maksimal '.StudentFeeWaiver::REASON_MAX_LENGTH.' karakter. '
                    .'Alasan ini tersimpan permanen pada tagihannya.'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function handleWaive(StudentFee $record, array $data): void
    {
        $user = Auth::user();

        if ($user === null) {
            return;
        }

        app(StudentFeeWaiver::class)->waive($record->getKey(), $data['waive_reason'] ?? null, $user);

        $record->refresh();

        Notification::make()
            ->title(__('Tagihan dibebaskan'))
            ->body(sprintf(
                'Tagihan %s untuk %s berstatus %s.',
                $record->period,
                $record->student?->full_name ?? 'siswa',
                $record->status->label(),
            ))
            ->success()
            ->send();
    }

    /**
     * Pembebasan hanya masuk akal untuk tagihan yang belum menerima uang, dan
     * hanya bagi peran yang memang berwenang (API 4.9: Admin).
     */
    public static function canWaive(StudentFee $record): bool
    {
        return (Auth::user()?->can('waive', $record) ?? false)
            && $record->status->canBeWaived()
            && bccomp((string) $record->amount_paid, '0', 2) <= 0;
    }

    /**
     * Periode yang benar-benar ada pada tagihan yang boleh dilihat pengguna.
     *
     * @return array<string, string>
     */
    protected static function periodOptions(): array
    {
        return StudentFee::query()
            ->distinct()
            ->orderByDesc('period')
            ->pluck('period', 'period')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected static function feeTypeOptions(): array
    {
        // Tanpa penyaringan `school_id` eksplisit: SchoolScope sudah memasangnya
        // pada setiap query FeeType, dan Super Admin memang melihat seluruh
        // cabang.
        return FeeType::query()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Daftar memuat nama siswa dan jenis tagihan pada setiap baris; tanpa eager
     * load keduanya menjadi dua query per baris.
     *
     * Riwayat pembayaran sengaja **tidak** ikut: halaman daftar hanya
     * menampilkan ringkasannya (`amount_paid`), dan memuat seluruh cicilan
     * setiap siswa untuk itu adalah pekerjaan yang tidak dipakai.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['student', 'feeType']);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\PaymentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStudentFees::route('/'),
            'view' => Pages\ViewStudentFee::route('/{record}'),
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
