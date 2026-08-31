<?php

namespace App\Filament\Resources;

use App\Enums\Gender;
use App\Enums\PpdbStatus;
use App\Enums\StudentStatus;
use App\Filament\Resources\PpdbRegistrationResource\Pages;
use App\Models\AcademicYear;
use App\Models\PpdbRegistration;
use App\Models\Student;
use App\Services\Ppdb\PpdbStatusUpdater;
use App\Support\PpdbWaTemplate;
use App\Support\WhatsAppLink;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Js;
use Illuminate\Validation\Rule;

/**
 * PPDB-03 s/d PPDB-05 dan API 4.7 — Review Admin, Status update,
 * wa.me link generator, dan Enroll siswa.
 */
class PpdbRegistrationResource extends Resource
{
    protected static ?string $model = PpdbRegistration::class;

    protected static ?string $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static ?string $navigationGroup = 'PPDB';

    protected static ?string $navigationLabel = 'Pendaftar PPDB';

    protected static ?string $modelLabel = 'Pendaftar PPDB';

    protected static ?string $pluralModelLabel = 'Pendaftar PPDB';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'full_name';

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            // API 4.7 — GET /admin/ppdb/{id}: detail pendaftar.
            Infolists\Components\Section::make(__('Data Pendaftar'))
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('reg_number')
                        ->label(__('No. Pendaftaran'))
                        ->copyable(),

                    Infolists\Components\TextEntry::make('status')
                        ->label(__('Status'))
                        ->badge()
                        ->formatStateUsing(fn (PpdbStatus $state) => $state->label())
                        ->color(fn (PpdbStatus $state) => $state->color()),

                    Infolists\Components\TextEntry::make('full_name')->label(__('Nama Lengkap')),

                    Infolists\Components\TextEntry::make('gender')
                        ->label(__('Jenis Kelamin'))
                        ->formatStateUsing(fn (Gender $state) => $state->label()),

                    Infolists\Components\TextEntry::make('birth_date')
                        ->label(__('Tanggal Lahir'))
                        ->date('d M Y')
                        ->placeholder('—'),

                    Infolists\Components\TextEntry::make('origin_school')
                        ->label(__('Asal Sekolah'))
                        ->placeholder('—'),

                    Infolists\Components\TextEntry::make('academicYear.name')
                        ->label(__('Tahun Ajaran Didaftar'))
                        ->placeholder('—'),

                    Infolists\Components\TextEntry::make('registered_at')
                        ->label(__('Waktu Daftar'))
                        ->dateTime('d M Y H:i'),
                ]),

            Infolists\Components\Section::make(__('Orang Tua / Wali'))
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('parent_name')->label(__('Nama'))->placeholder('—'),
                    Infolists\Components\TextEntry::make('parent_phone')->label(__('No. HP'))->placeholder('—'),
                    Infolists\Components\TextEntry::make('parent_email')->label(__('Email'))->placeholder('—'),
                ]),

            Infolists\Components\Section::make(__('Berkas & Catatan'))
                ->schema([
                    // Berkas privat: tidak ada URL penyimpanan yang dikirim ke
                    // peramban, hanya tautan ke rute berwenang panel.
                    Infolists\Components\ViewEntry::make('documents')
                        ->label(__('Dokumen'))
                        ->view('filament.ppdb.documents'),

                    Infolists\Components\TextEntry::make('status_notes')
                        ->label(__('Catatan Status'))
                        ->placeholder('—'),

                    Infolists\Components\TextEntry::make('convertedStudent.nis')
                        ->label(__('NIS Siswa Hasil Enroll'))
                        ->placeholder(__('Belum di-enroll')),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // PPDB-03 poin 1 — kolom: no. daftar, nama, asal sekolah, status, tanggal daftar.
            ->columns([
                Tables\Columns\TextColumn::make('reg_number')
                    ->label(__('No. Daftar'))
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('full_name')
                    ->label(__('Nama'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('origin_school')
                    ->label(__('Asal Sekolah'))
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (PpdbStatus $state) => $state->label())
                    ->color(fn (PpdbStatus $state) => $state->color()),

                Tables\Columns\TextColumn::make('registered_at')
                    ->label(__('Tanggal Daftar'))
                    ->dateTime('d M Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('parent_phone')
                    ->label(__('HP Ortu'))
                    ->placeholder('—')
                    ->toggleable(),
            ])
            // API 4.7 — GET /admin/ppdb. Filter: status, academic_year_id.
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(PpdbStatus::options()),

                Tables\Filters\SelectFilter::make('academic_year_id')
                    ->label(__('Tahun Ajaran'))
                    ->options(fn () => AcademicYear::query()->orderByDesc('start_date')->pluck('name', 'id')),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                static::changeStatusAction(),
                static::waLinkAction(),
                static::enrollAction(),
            ])
            ->bulkActions([])
            ->defaultSort('registered_at', 'desc');
    }

    /**
     * PPDB-03 poin 3 / API 4.7 — PATCH /admin/ppdb/{id}/status:
     * "Update status pendaftaran + catatan alasan".
     */
    public static function changeStatusAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('changeStatus')
            ->label(__('Ubah Status'))
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->authorize('changeStatus')
            ->form([
                Forms\Components\Select::make('status')
                    ->label(__('Status Baru'))
                    ->options(PpdbStatus::options())
                    ->required(),

                Forms\Components\Textarea::make('status_notes')
                    ->label(__('Catatan Alasan'))
                    ->rows(3)
                    ->required()
                    ->helperText(__('Wajib diisi — perubahan status disimpan bersama alasannya.')),
            ])
            ->fillForm(fn (PpdbRegistration $record) => [
                'status' => $record->status->value,
                'status_notes' => $record->status_notes,
            ])
            ->action(function (PpdbRegistration $record, array $data): void {
                // Status dan notifikasi otomatisnya ditulis satu jalur, satu
                // transaksi (butir 247).
                app(PpdbStatusUpdater::class)->update(
                    $record,
                    PpdbStatus::from($data['status']),
                    $data['status_notes'],
                );

                // NOTIF-03 poin 1 — trigger tersedia untuk "PPDB status berubah";
                // Phase 1 memakai wa.me manual link (Ringkasan Eksekutif).
                $notification = Notification::make()
                    ->title(__('Status pendaftaran diperbarui'))
                    ->body("{$record->full_name} — {$record->status->label()}")
                    ->success();

                if ($link = $record->refresh()->waLink()) {
                    $notification->actions([
                        NotificationAction::make('wa')
                            ->label(__('Buka WhatsApp'))
                            ->url($link, shouldOpenInNewTab: true),
                    ]);
                }

                $notification->send();
            });
    }

    /**
     * PPDB-04 / API 4.7 — GET /admin/ppdb/{id}/wa-link:
     * "Generate wa.me link notifikasi siap kirim untuk pendaftar ini".
     */
    public static function waLinkAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('waLink')
            ->label(__('Link WhatsApp'))
            ->icon('heroicon-o-chat-bubble-left-right')
            ->color('success')
            ->authorize('generateWaLink')
            ->visible(fn (PpdbRegistration $record) => WhatsAppLink::normalizePhone($record->parent_phone) !== null)
            ->modalSubmitActionLabel(__('Buka WhatsApp'))
            ->form([
                Forms\Components\Textarea::make('message')
                    ->label(__('Teks Notifikasi'))
                    ->rows(6)
                    ->required()
                    ->helperText('Template mengikuti status terkini. Placeholder tersedia: '
                        .implode(', ', PpdbWaTemplate::placeholders())),
            ])
            ->fillForm(fn (PpdbRegistration $record) => ['message' => $record->waMessage()])
            ->action(function (PpdbRegistration $record, array $data, $livewire): void {
                $link = $record->waLink(PpdbWaTemplate::fill($data['message'], $record));

                if ($link === null) {
                    Notification::make()
                        ->title(__('Nomor HP orang tua tidak valid'))
                        ->danger()
                        ->send();

                    return;
                }

                $livewire->js('window.open('.Js::from($link).", '_blank')");
            });
    }

    /**
     * PPDB-05 / API 4.7 — POST /admin/ppdb/{id}/enroll:
     * "Konversi pendaftar menjadi siswa aktif".
     */
    public static function enrollAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('enroll')
            ->label(__('Enroll Siswa'))
            ->icon('heroicon-o-user-plus')
            ->color('primary')
            ->authorize('enroll')
            ->modalHeading(__('Enroll Pendaftar Menjadi Siswa'))
            ->modalSubmitActionLabel(__('Konfirmasi Enroll'))
            // PPDB-05 poin 2 — Admin dapat melengkapi data sebelum konfirmasi.
            ->form(fn (PpdbRegistration $record) => [
                Forms\Components\TextInput::make('nis')
                    ->label(__('NIS'))
                    ->required()
                    ->maxLength(20)
                    // SIS-01 poin 3 — NIS unik dalam satu sekolah.
                    ->rule(fn () => Rule::unique('students', 'nis')->where('school_id', $record->school_id))
                    ->helperText(__('Nomor Induk Siswa, unik dalam satu cabang.')),

                Forms\Components\TextInput::make('nisn')
                    ->label(__('NISN'))
                    ->maxLength(10)
                    ->rule('digits:10'),

                Forms\Components\TextInput::make('full_name')
                    ->label(__('Nama Lengkap'))
                    ->required()
                    ->maxLength(150),

                Forms\Components\Select::make('gender')
                    ->label(__('Jenis Kelamin'))
                    ->options(Gender::options())
                    ->required(),

                Forms\Components\TextInput::make('birth_place')
                    ->label(__('Tempat Lahir'))
                    ->maxLength(100),

                Forms\Components\DatePicker::make('birth_date')
                    ->label(__('Tanggal Lahir')),

                Forms\Components\TextInput::make('religion')
                    ->label(__('Agama'))
                    ->maxLength(30),

                Forms\Components\TextInput::make('entry_year')
                    ->label(__('Tahun Masuk'))
                    ->numeric()
                    ->minValue(1900)
                    ->maxValue((int) now()->addYear()->format('Y')),

                Forms\Components\Textarea::make('address')
                    ->label(__('Alamat'))
                    ->rows(2)
                    ->columnSpanFull(),

                Forms\Components\TextInput::make('parent_name')
                    ->label(__('Nama Orang Tua / Wali'))
                    ->maxLength(150),

                Forms\Components\TextInput::make('parent_phone')
                    ->label(__('No. HP Orang Tua'))
                    ->tel()
                    ->maxLength(20),

                Forms\Components\TextInput::make('parent_email')
                    ->label(__('Email Orang Tua'))
                    ->email()
                    ->maxLength(150),
            ])
            ->modalWidth('3xl')
            // PPDB-05 poin 1 — data formulir PPDB otomatis mengisi form siswa baru.
            ->fillForm(fn (PpdbRegistration $record) => [
                'full_name' => $record->full_name,
                'gender' => $record->gender->value,
                'birth_date' => $record->birth_date?->format('Y-m-d'),
                'parent_name' => $record->parent_name,
                'parent_phone' => $record->parent_phone,
                'parent_email' => $record->parent_email,
                'entry_year' => (int) ($record->academicYear?->start_date?->format('Y')
                    ?? $record->registered_at?->format('Y')
                    ?? now()->format('Y')),
            ])
            ->action(function (PpdbRegistration $record, array $data): void {
                $student = DB::transaction(function () use ($record, $data): Student {
                    $student = Student::query()->create([
                        ...$data,
                        'school_id' => $record->school_id,
                        'status' => StudentStatus::Active,
                    ]);

                    // PPDB-05 poin 3 — status berubah menjadi ENROLLED.
                    $record->update([
                        'converted_student_id' => $student->id,
                        'status' => PpdbStatus::Enrolled,
                    ]);

                    return $student;
                });

                Notification::make()
                    ->title(__('Pendaftar berhasil menjadi siswa aktif'))
                    ->body("{$student->full_name} — NIS {$student->nis}")
                    ->success()
                    ->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPpdbRegistrations::route('/'),
            'view' => Pages\ViewPpdbRegistration::route('/{record}'),
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
