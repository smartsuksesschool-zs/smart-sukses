<?php

namespace App\Filament\Resources;

use App\Enums\AccountClaimStatus;
use App\Enums\AccountClaimType;
use App\Enums\AuthProvider;
use App\Enums\RoleName;
use App\Exceptions\AccountClaimException;
use App\Filament\Resources\AccountClaimResource\Pages;
use App\Models\AccountClaim;
use App\Services\Auth\AccountClaimReviewer;
use Filament\Actions;
use Filament\Actions\MountableAction;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * Permintaan Akun — antrean persetujuan pendaftaran mandiri lewat Google (M7).
 *
 * Hanya dibaca dan diputuskan; tidak ada satu pun field yang dapat disunting.
 * Yang boleh dilakukan admin persis dua: Setujui dan Tolak. Kalau ada yang
 * keliru pada permintaan — salah anak, salah peran — yang benar adalah
 * menolaknya dan meminta pemohon mengirim ulang, bukan menyunting pengakuan
 * orang lain sampai ia menjadi benar (butir 543).
 *
 * Surel disamarkan di daftar dan utuh di halaman rincian. Daftar dibuka untuk
 * menyapu antrean dan sering tampil di layar yang dilihat bersama-sama;
 * rinciannya dibuka justru untuk memutuskan, dan di sana alamat utuh memang
 * salah satu bahan keputusannya (butir 536).
 */
class AccountClaimResource extends Resource
{
    protected static ?string $model = AccountClaim::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-plus';

    protected static ?string $navigationGroup = 'Manajemen Akses';

    protected static ?string $navigationLabel = 'Permintaan Akun';

    protected static ?string $modelLabel = 'Permintaan Akun';

    protected static ?string $pluralModelLabel = 'Permintaan Akun';

    protected static ?int $navigationSort = 8;

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Jumlah yang menunggu, di sebelah menunya.
     *
     * Antrean persetujuan yang tidak terlihat adalah antrean yang tidak
     * dikerjakan — dan yang menunggu di ujung sana adalah orang tua yang tidak
     * dapat membuka tagihan anaknya.
     */
    public static function getNavigationBadge(): ?string
    {
        $pending = static::getModel()::query()->pending()->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            /*
             * Keadaan kosong yang menerangkan langkah berikutnya.
             *
             * Antrean yang kosong di sini berarti belum ada yang mendaftar, bukan ada
             * yang belum disiapkan admin. Bedanya perlu dikatakan (butir 569).
             */
            ->emptyStateHeading(__('Belum ada permintaan akun'))
            ->emptyStateDescription(__('Permintaan muncul sendiri ketika siswa, orang tua, atau staf menekan "Masuk dengan Google" di halaman masuk. Tidak ada yang perlu dibuat dari halaman ini.'))
            ->defaultSort('requested_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('requested_at')
                    ->label(__('Waktu Permintaan'))
                    ->dateTime('d M Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('requested_type')
                    ->label(__('Jenis Permintaan'))
                    ->badge()
                    ->formatStateUsing(fn (AccountClaimType $state) => $state->label())
                    ->color(fn (AccountClaimType $state) => $state->color()),

                // Peran yang **diberikan**, bukan yang diminta. Kosong selama
                // belum disetujui, dan bagi permintaan staf itulah satu-satunya
                // tempat peran pernah muncul (butir 544).
                Tables\Columns\TextColumn::make('approved_role')
                    ->label(__('Peran Diberikan'))
                    ->badge()
                    ->formatStateUsing(fn (RoleName $state) => $state->label())
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('email')
                    ->label(__('Akun Google'))
                    // Disamarkan; alamat utuhnya ada di halaman rincian.
                    ->formatStateUsing(fn (AccountClaim $record) => $record->maskedEmail())
                    ->description(fn (AccountClaim $record) => $record->name)
                    ->searchable(),

                Tables\Columns\TextColumn::make('student.full_name')
                    ->label(__('Siswa yang Dirujuk'))
                    ->placeholder(__('— permintaan staf'))
                    ->searchable(),

                Tables\Columns\TextColumn::make('student.activeStudentClass.schoolClass.name')
                    ->label(__('Kelas'))
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (AccountClaimStatus $state) => $state->label())
                    ->color(fn (AccountClaimStatus $state) => $state->color()),

                Tables\Columns\TextColumn::make('reviewer.name')
                    ->label(__('Ditinjau Oleh'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(AccountClaimStatus::options())
                    ->default(AccountClaimStatus::Pending->value),

                Tables\Filters\SelectFilter::make('requested_type')
                    ->label(__('Jenis Permintaan'))
                    ->options(fn () => collect(AccountClaim::SELF_SERVICE_TYPES)
                        ->mapWithKeys(fn (AccountClaimType $type) => [$type->value => $type->label()])
                        ->all()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                static::approveAction(),
                static::rejectAction(),
            ])
            // Tanpa aksi massal. Menyetujui berarti membuat akun dan menaut
            // seseorang ke seorang anak; itu keputusan satu per satu, dan
            // tombol "setujui 40 yang tercentang" adalah cara paling murah
            // untuk melewatkan seluruhnya tanpa membaca satu pun.
            ->bulkActions([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make(__('Permintaan'))
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('status')
                        ->label(__('Status'))
                        ->badge()
                        ->formatStateUsing(fn (AccountClaimStatus $state) => $state->label())
                        ->color(fn (AccountClaimStatus $state) => $state->color()),

                    Infolists\Components\TextEntry::make('requested_type')
                        ->label(__('Jenis Permintaan'))
                        ->badge()
                        ->formatStateUsing(fn (AccountClaimType $state) => $state->label())
                        ->color(fn (AccountClaimType $state) => $state->color()),

                    /*
                     * Peran yang diberikan, bukan yang diminta.
                     *
                     * Untuk permintaan staf ia kosong sampai admin memilihnya —
                     * dan itulah keseluruhan maksud alur ini: pemohon menyatakan
                     * identitas, admin menentukan kewenangan (butir 547).
                     */
                    Infolists\Components\TextEntry::make('approved_role')
                        ->label(__('Peran Diberikan'))
                        ->formatStateUsing(fn (RoleName $state) => $state->label())
                        ->placeholder(__('Belum ditentukan')),

                    Infolists\Components\TextEntry::make('requested_at')
                        ->label(__('Waktu Permintaan'))
                        ->dateTime('d M Y H:i'),

                    Infolists\Components\TextEntry::make('provider')
                        ->label(__('Penyedia'))
                        ->formatStateUsing(fn (AuthProvider $state) => $state->label()),
                ]),

            Infolists\Components\Section::make(__('Akun Google'))
                ->columns(2)
                ->description(__('Alamat surel ini sudah diverifikasi Google. Verifikasi itu membuktikan penguasaan kotak surel, bukan hubungan dengan siswa di bawah.'))
                ->schema([
                    Infolists\Components\TextEntry::make('email')
                        ->label(__('Surel Terverifikasi'))
                        ->copyable(),

                    Infolists\Components\TextEntry::make('name')
                        ->label(__('Nama pada Akun Google'))
                        ->placeholder('—'),
                ]),

            /*
             * Bagian ini tidak ada artinya bagi permintaan staf: ia tidak
             * merujuk siswa mana pun, dan menampilkan enam baris "—" hanya
             * membuat halaman keputusan lebih sulit dibaca.
             */
            Infolists\Components\Section::make(__('Siswa yang Dirujuk'))
                ->columns(2)
                ->visible(fn (AccountClaim $record) => $record->student_id !== null)
                ->schema([
                    Infolists\Components\TextEntry::make('student.full_name')
                        ->label(__('Nama Siswa')),

                    Infolists\Components\TextEntry::make('student.activeStudentClass.schoolClass.name')
                        ->label(__('Kelas'))
                        ->placeholder('—'),

                    Infolists\Components\TextEntry::make('school.name')
                        ->label(__('Cabang')),

                    Infolists\Components\TextEntry::make('student.parent_name')
                        ->label(__('Nama Orang Tua pada Data Induk'))
                        ->placeholder('—'),

                    /*
                     * Keadaan tautan yang berlaku sekarang. Inilah yang paling
                     * menentukan: sebuah permintaan atas siswa yang sudah punya
                     * pemilik akun tidak dapat disetujui, dan admin sebaiknya
                     * mengetahuinya sebelum menekan tombol, bukan sesudahnya.
                     */
                    Infolists\Components\TextEntry::make('student.user.email')
                        ->label(__('Akun Siswa Saat Ini'))
                        ->placeholder(__('Belum ada')),

                    Infolists\Components\TextEntry::make('student.parentUser.email')
                        ->label(__('Akun Orang Tua Saat Ini'))
                        ->placeholder(__('Belum ada')),
                ]),

            Infolists\Components\Section::make(__('Cabang yang Dituju'))
                ->columns(2)
                ->visible(fn (AccountClaim $record) => $record->student_id === null)
                ->description(__('Permintaan staf tidak dicocokkan dengan data induk apa pun: sekolah ini tidak memegang pengenal pegawai. Yang terbukti hanya kepemilikan surel di atas.'))
                ->schema([
                    Infolists\Components\TextEntry::make('school.name')
                        ->label(__('Cabang')),
                ]),

            Infolists\Components\Section::make(__('Peninjauan'))
                ->columns(2)
                ->visible(fn (AccountClaim $record) => ! $record->isReviewable())
                ->schema([
                    Infolists\Components\TextEntry::make('reviewer.name')
                        ->label(__('Ditinjau Oleh'))
                        ->placeholder('—'),

                    Infolists\Components\TextEntry::make('reviewed_at')
                        ->label(__('Waktu Peninjauan'))
                        ->dateTime('d M Y H:i')
                        ->placeholder('—'),

                    Infolists\Components\TextEntry::make('review_notes')
                        ->label(__('Catatan Internal'))
                        ->placeholder('—')
                        ->columnSpanFull(),

                    Infolists\Components\TextEntry::make('approvedUser.email')
                        ->label(__('Akun yang Dibuat'))
                        ->placeholder('—'),
                ]),
        ]);
    }

    /**
     * Setujui — membuat/memakai ulang akun dan menaut siswanya.
     *
     * Aksinya disusun sekali dan dipakai dua kali: sebagai aksi baris di daftar,
     * dan sebagai aksi header di halaman rincian. Keduanya kelas yang berbeda —
     * `Tables\Actions\Action` dan `Actions\Action` — tetapi keduanya turunan
     * `MountableAction`, jadi yang perlu digandakan hanya pembuatan objeknya,
     * bukan satu pun aturannya. Tanpa ini, tombol yang sama akan punya dua
     * salinan syarat izin yang perlahan berbeda (butir 543).
     */
    public static function approveAction(): Tables\Actions\Action
    {
        return static::configureApprove(Tables\Actions\Action::make('approve'));
    }

    public static function headerApproveAction(): Actions\Action
    {
        return static::configureApprove(Actions\Action::make('approve'));
    }

    public static function rejectAction(): Tables\Actions\Action
    {
        return static::configureReject(Tables\Actions\Action::make('reject'));
    }

    public static function headerRejectAction(): Actions\Action
    {
        return static::configureReject(Actions\Action::make('reject'));
    }

    protected static function configureApprove(MountableAction $action): MountableAction
    {
        return $action
            ->label(__('Setujui'))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('Setujui permintaan akun'))
            ->modalDescription(fn (AccountClaim $record) => $record->requiresRoleChoice()
                ? __('Pemohon hanya menyatakan bahwa ia staf sekolah. Andalah yang menentukan perannya, dan pilihan itu berlaku seketika.')
                : __('Akun akan dibuat dan ditautkan ke siswa yang dirujuk. Pastikan Anda yakin pemohon memang berhak.'))
            /*
             * Formulirnya hanya muncul bagi permintaan yang memang menuntut
             * pilihan peran. Bagi siswa dan orang tua, perannya sudah
             * ditentukan jenis permintaannya — dan menawarkan pemilih peran di
             * sana berarti memberi kesempatan menyetujui permintaan siswa
             * sebagai bendahara (butir 547).
             */
            ->form(fn (AccountClaim $record) => $record->requiresRoleChoice() ? [
                Forms\Components\Select::make('approved_role')
                    ->label(__('Peran yang Diberikan'))
                    // Daftar putih. SCHOOL_ADMIN dan SUPER_ADMIN tidak ada di
                    // dalamnya, dan tidak dapat diketikkan ke dalamnya: nilai
                    // di luar daftar ditolak validasi Filament dan, sekali
                    // lagi, oleh AccountClaimReviewer.
                    ->options(AccountClaim::assignableRoleOptions())
                    ->required()
                    ->native(false)
                    ->helperText(__('Admin Sekolah dan Super Admin tidak dapat diberikan lewat jalur ini; keduanya dibuat dari menu Pengguna.')),
            ] : [])
            ->visible(fn (AccountClaim $record) => Auth::user()?->can('approve', $record) ?? false)
            ->action(function (AccountClaim $record, array $data): void {
                $chosen = isset($data['approved_role'])
                    ? RoleName::tryFrom((string) $data['approved_role'])
                    : null;

                try {
                    $user = app(AccountClaimReviewer::class)->approve($record, Auth::user(), $chosen);
                } catch (AccountClaimException $e) {
                    // Pesannya untuk admin, dan boleh spesifik: tanpa itu ia
                    // tidak dapat memperbaiki apa pun.
                    Notification::make()
                        ->danger()
                        ->title(__('Permintaan tidak dapat disetujui'))
                        ->body($e->getMessage())
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('Permintaan disetujui'))
                    ->body(__('Akun :email sekarang aktif sebagai :peran.', [
                        'email' => $user->email,
                        'peran' => $user->primaryRole()?->label() ?? '—',
                    ]))
                    ->send();
            });
    }

    protected static function configureReject(MountableAction $action): MountableAction
    {
        return $action
            ->label(__('Tolak'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('Tolak permintaan akun'))
            ->form([
                Forms\Components\Textarea::make('review_notes')
                    ->label(__('Catatan Internal'))
                    ->helperText(__('Tidak ditampilkan kepada pemohon. Ia hanya melihat satu kalimat yang sama untuk semua penolakan.'))
                    ->maxLength(255)
                    ->rows(3),
            ])
            ->visible(fn (AccountClaim $record) => Auth::user()?->can('reject', $record) ?? false)
            ->action(function (AccountClaim $record, array $data): void {
                try {
                    app(AccountClaimReviewer::class)->reject(
                        $record,
                        Auth::user(),
                        $data['review_notes'] ?? null,
                    );
                } catch (AccountClaimException $e) {
                    Notification::make()
                        ->danger()
                        ->title(__('Permintaan tidak dapat ditolak'))
                        ->body($e->getMessage())
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('Permintaan ditolak'))
                    ->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAccountClaims::route('/'),
            'view' => Pages\ViewAccountClaim::route('/{record}'),
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
