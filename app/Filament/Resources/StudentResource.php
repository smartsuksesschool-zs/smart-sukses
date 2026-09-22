<?php

namespace App\Filament\Resources;

use App\Enums\Gender;
use App\Enums\RoleName;
use App\Enums\StudentStatus;
use App\Filament\Resources\StudentResource\Pages;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Support\StudentPhoto;
use App\Support\TeacherClassVisibility;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Unique;

/**
 * SIS-01 s/d SIS-05 dan API 4.5 — Sistem Informasi Siswa.
 */
class StudentResource extends Resource
{
    protected static ?string $model = Student::class;

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?string $navigationLabel = 'Data Siswa';

    protected static ?string $modelLabel = 'Siswa';

    protected static ?string $pluralModelLabel = 'Siswa';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'full_name';

    public static function form(Form $form): Form
    {
        return $form->schema([
            // Super Admin tidak memiliki school_id (SchoolScope::currentSchoolId()
            // sengaja NULL untuk mereka), sehingga cabang harus dipilih di sini.
            // Tanpa field ini penyimpanan berakhir sebagai INSERT dengan
            // school_id NULL dan galat basis data mentah (butir 588).
            //
            // Polanya sama persis dengan FeeTypeResource dan GradeConfigResource:
            // field hanya dirender untuk Super Admin, sedangkan peran School
            // Level tetap terikat cabang akunnya sendiri di server.
            Forms\Components\Select::make('school_id')
                ->label(__('Cabang Sekolah'))
                ->relationship(
                    name: 'school',
                    titleAttribute: 'name',
                    modifyQueryUsing: fn ($query) => $query->where('is_active', true),
                )
                ->searchable()
                ->preload()
                ->required()
                ->visible(fn () => Auth::user()?->isSuperAdmin())
                // Memindahkan siswa antar cabang akan memutusnya dari kelas,
                // nilai, rapor, dan tagihannya sendiri.
                ->disabledOn('edit')
                ->columnSpanFull()
                ->helperText(__('Siswa terdaftar pada cabang ini.')),

            Forms\Components\Section::make(__('Identitas Siswa'))
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('nis')
                        ->label(__('NIS'))
                        ->required()
                        ->maxLength(20)
                        // SIS-01 poin 3: NIS unik dalam satu sekolah. Cabangnya
                        // diambil dari field di atas bila Super Admin yang
                        // mengisi — `currentSchoolId()` NULL untuk mereka, dan
                        // aturan unik yang menyaring `school_id IS NULL` tidak
                        // menguji apa pun (butir 588).
                        /*
                         * Aturan unik membaca tabelnya langsung, sehingga siswa
                         * yang **diarsipkan** tetap memesan NIS-nya — dan itu
                         * memang yang diinginkan: dua siswa tidak boleh berbagi
                         * satu NIS historis, dan indeks unik di basis data pun
                         * akan menolaknya. Yang perlu diperbaiki hanya
                         * kalimatnya: siswa itu tidak terlihat di daftar mana
                         * pun, sehingga "NIS sudah digunakan" terdengar seperti
                         * kesalahan sistem (butir 589).
                         */
                        ->unique(
                            ignoreRecord: true,
                            modifyRuleUsing: fn (Unique $rule, Forms\Get $get) => $rule
                                ->where('school_id', static::resolveSchoolId($get('school_id'))),
                        )
                        ->validationMessages([
                            'unique' => __('NIS sudah digunakan oleh siswa yang aktif atau diarsipkan di cabang ini. Pulihkan data siswa lama bila itu siswa yang sama.'),
                        ])
                        ->helperText(__('Nomor Induk Siswa, unik dalam satu cabang.')),

                    Forms\Components\TextInput::make('nisn')
                        ->label(__('NISN'))
                        ->maxLength(10)
                        // SIS-01 poin 2: validasi format NISN (10 digit angka).
                        ->rule('digits:10')
                        ->helperText(__('10 digit angka (opsional).')),

                    Forms\Components\TextInput::make('full_name')
                        ->label(__('Nama Lengkap'))
                        ->required()
                        ->maxLength(150)
                        ->columnSpanFull(),

                    Forms\Components\Select::make('gender')
                        ->label(__('Jenis Kelamin'))
                        ->options(Gender::options())
                        ->required(),

                    Forms\Components\TextInput::make('religion')
                        ->label(__('Agama'))
                        ->maxLength(30),

                    Forms\Components\TextInput::make('birth_place')
                        ->label(__('Tempat Lahir'))
                        ->maxLength(100),

                    Forms\Components\DatePicker::make('birth_date')
                        ->label(__('Tanggal Lahir'))
                        ->maxDate(now()),

                    Forms\Components\Textarea::make('address')
                        ->label(__('Alamat'))
                        ->rows(3)
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make(__('Foto'))
                ->schema([
                    // SIS-03: JPG/PNG/WEBP, maks 2 MB, auto-resize 400x400.
                    //
                    // Disk privat; pratinjaunya dimuat lewat rute berwenang,
                    // bukan URL disk dan bukan URL bertanda tangan (butir 587).
                    Forms\Components\FileUpload::make('photo_url')
                        ->label(__('Foto Siswa'))
                        ->image()
                        ->avatar()
                        ->disk(fn () => StudentPhoto::disk())
                        ->directory(StudentPhoto::DIRECTORY)
                        ->visibility('private')
                        ->getUploadedFileUsing(fn (?Student $record, string $file): ?array => static::storedPhotoPreview($record, $file))
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                        ->maxSize(2048)
                        ->imageResizeMode('cover')
                        ->imageCropAspectRatio('1:1')
                        ->imageResizeTargetWidth('400')
                        ->imageResizeTargetHeight('400')
                        ->helperText(__('JPG/PNG/WEBP, maksimal 2 MB. Otomatis dipotong 400×400 px.')),
                ]),

            Forms\Components\Section::make(__('Data Orang Tua / Wali'))
                ->columns(2)
                ->schema([
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

                    Forms\Components\Select::make('parent_user_id')
                        ->label(__('Akun Portal Orang Tua'))
                        ->options(fn () => static::userOptions(RoleName::OrangTua))
                        ->searchable()
                        ->helperText(__('Opsional — hubungkan ke akun dengan peran Orang Tua.')),
                ]),

            Forms\Components\Section::make(__('Status Akademik'))
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('entry_year')
                        ->label(__('Tahun Masuk'))
                        ->numeric()
                        ->minValue(1900)
                        ->maxValue((int) now()->addYear()->format('Y')),

                    Forms\Components\Select::make('status')
                        ->label(__('Status'))
                        ->options(StudentStatus::options())
                        ->default(StudentStatus::Active->value)
                        ->required(),

                    Forms\Components\Select::make('user_id')
                        ->label(__('Akun Portal Siswa'))
                        ->options(fn () => static::userOptions(RoleName::Siswa))
                        ->searchable()
                        ->helperText(__('Opsional — siswa tidak wajib punya akun portal.')),

                    Forms\Components\Textarea::make('notes')
                        ->label(__('Catatan'))
                        ->rows(3)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /**
     * Detail siswa, hanya baca.
     *
     * Isinya kolom yang memang sudah dimiliki `students` dan sudah tampil di
     * form-nya sendiri. Tidak ada kolom internal yang ditambahkan di sini:
     * `school_id` dirender sebagai nama cabang dan hanya untuk Super Admin,
     * dan foto siswa memakai rute berwenang yang sama dengan tabelnya
     * (butir 587) — tidak pernah URL disk.
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make(__('Identitas Siswa'))
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('nis')
                        ->label(__('NIS'))
                        ->copyable(),

                    Infolists\Components\TextEntry::make('nisn')
                        ->label(__('NISN'))
                        ->placeholder('—'),

                    Infolists\Components\TextEntry::make('full_name')
                        ->label(__('Nama Lengkap'))
                        ->columnSpanFull(),

                    Infolists\Components\TextEntry::make('gender')
                        ->label(__('Jenis Kelamin'))
                        ->formatStateUsing(fn (Gender $state) => $state->label()),

                    Infolists\Components\TextEntry::make('religion')
                        ->label(__('Agama'))
                        ->placeholder('—'),

                    Infolists\Components\TextEntry::make('birth_place')
                        ->label(__('Tempat Lahir'))
                        ->placeholder('—'),

                    Infolists\Components\TextEntry::make('birth_date')
                        ->label(__('Tanggal Lahir'))
                        ->date('d M Y')
                        ->placeholder('—'),

                    Infolists\Components\TextEntry::make('address')
                        ->label(__('Alamat'))
                        ->placeholder('—')
                        ->columnSpanFull(),

                    Infolists\Components\TextEntry::make('school.name')
                        ->label(__('Cabang Sekolah'))
                        ->visible(fn () => Auth::user()?->isSuperAdmin())
                        ->columnSpanFull(),
                ]),

            Infolists\Components\Section::make(__('Foto'))
                ->schema([
                    Infolists\Components\ImageEntry::make('photo_url')
                        ->label(__('Foto Siswa'))
                        ->circular()
                        // Rute berwenang, bukan URL disk: fotonya privat.
                        ->getStateUsing(fn (Student $record): ?string => StudentPhoto::url($record))
                        ->placeholder(__('Belum ada foto')),
                ]),

            Infolists\Components\Section::make(__('Status Akademik'))
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('status')
                        ->label(__('Status'))
                        ->badge()
                        ->formatStateUsing(fn (StudentStatus $state) => $state->label())
                        ->color(fn (StudentStatus $state) => $state->color()),

                    Infolists\Components\TextEntry::make('entry_year')
                        ->label(__('Tahun Masuk'))
                        ->placeholder('—'),

                    Infolists\Components\TextEntry::make('activeStudentClass.schoolClass.name')
                        ->label(__('Kelas'))
                        ->badge()
                        ->placeholder(__('Belum ada kelas')),

                    Infolists\Components\TextEntry::make('user.name')
                        ->label(__('Akun Portal Siswa'))
                        ->placeholder(__('Belum terhubung')),

                    Infolists\Components\TextEntry::make('notes')
                        ->label(__('Catatan'))
                        ->placeholder('—')
                        ->columnSpanFull(),
                ]),

            Infolists\Components\Section::make(__('Data Orang Tua / Wali'))
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('parent_name')
                        ->label(__('Nama Orang Tua / Wali'))
                        ->placeholder('—'),

                    Infolists\Components\TextEntry::make('parent_phone')
                        ->label(__('No. HP Orang Tua'))
                        ->placeholder('—'),

                    Infolists\Components\TextEntry::make('parent_email')
                        ->label(__('Email Orang Tua'))
                        ->placeholder('—'),

                    Infolists\Components\TextEntry::make('parentUser.name')
                        ->label(__('Akun Portal Orang Tua'))
                        ->placeholder(__('Belum terhubung')),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Status kolomnya URL rute berwenang, sehingga ImageColumn
                // tidak pernah membangun URL dari disk (butir 587).
                Tables\Columns\ImageColumn::make('photo_url')
                    ->label(__('Foto'))
                    ->circular()
                    ->getStateUsing(fn (Student $record): ?string => StudentPhoto::url($record))
                    ->defaultImageUrl(fn () => null),

                Tables\Columns\TextColumn::make('nis')
                    ->label(__('NIS'))
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('full_name')
                    ->label(__('Nama Lengkap'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('gender')
                    ->label(__('L/P'))
                    ->formatStateUsing(fn (Gender $state) => $state->value),

                Tables\Columns\TextColumn::make('activeStudentClass.schoolClass.name')
                    ->label(__('Kelas'))
                    ->placeholder(__('Belum ada kelas'))
                    ->badge(),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (StudentStatus $state) => $state->label())
                    ->color(fn (StudentStatus $state) => $state->color()),

                Tables\Columns\TextColumn::make('parent_phone')
                    ->label(__('HP Ortu'))
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(StudentStatus::options()),

                // Arsip disembunyikan secara bawaan; "Dengan arsip" dan "Hanya
                // arsip" ada di sini supaya siswa yang diarsipkan dapat
                // ditemukan kembali untuk dipulihkan (butir 589).
                Tables\Filters\TrashedFilter::make()
                    ->label(__('Arsip')),

                // API 4.5 — filter class_id.
                Tables\Filters\SelectFilter::make('class')
                    ->label(__('Kelas'))
                    ->options(fn () => SchoolClass::query()->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->inClass((int) $data['value'])
                        : $query),
            ])
            ->actions([
                // Siswa terarsip hanya menawarkan "Pulihkan": halaman detail dan
                // form-nya mengambil record lewat query yang memang tidak
                // memuat arsip, dan menawarkan tombol yang berakhir 404 bukan
                // pilihan yang jujur (butir 589).
                Tables\Actions\ViewAction::make()
                    ->label(__('Lihat'))
                    ->hidden(fn (Student $record): bool => $record->trashed()),

                Tables\Actions\EditAction::make()
                    ->label(__('Ubah'))
                    ->hidden(fn (Student $record): bool => $record->trashed()),

                // API 4.5 — PATCH /students/{id}/status.
                Tables\Actions\Action::make('changeStatus')
                    ->label(__('Ubah Status'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (Student $record) => ! $record->trashed()
                        && Auth::user()?->can('changeStatus', $record))
                    ->form([
                        Forms\Components\Select::make('status')
                            ->label(__('Status Baru'))
                            ->options(StudentStatus::options())
                            ->required(),
                    ])
                    ->fillForm(fn (Student $record) => ['status' => $record->status->value])
                    ->action(function (Student $record, array $data) {
                        $record->update(['status' => $data['status']]);

                        Notification::make()
                            ->title(__('Status siswa diperbarui'))
                            ->success()
                            ->send();
                    }),

                static::archiveAction(),

                Tables\Actions\RestoreAction::make()
                    ->label(__('Pulihkan'))
                    ->modalHeading(__('Pulihkan siswa dari arsip'))
                    ->modalDescription(__('Siswa akan kembali muncul di daftar siswa beserta seluruh histori dan fotonya.'))
                    ->successNotificationTitle(__('Siswa dipulihkan')),
            ])
            // Baris terarsip tidak punya halaman detail, jadi barisnya tidak
            // dapat diklik menuju 404.
            ->recordUrl(fn (Student $record): ?string => $record->trashed()
                ? null
                : static::getUrl('view', ['record' => $record]))
            /*
             * Tidak ada satu pun aksi massal, dan itu disengaja.
             *
             * Mengarsipkan siswa harus satu per satu dengan konfirmasi yang
             * menyebut nama dan NIS-nya; aksi massal menghilangkan justru
             * bagian yang membuat keputusannya dapat diperiksa. Tidak ada
             * `DeleteBulkAction`, tidak ada `ForceDeleteBulkAction`, dan tidak
             * ada `RestoreBulkAction` (butir 589).
             */
            ->bulkActions([])
            ->defaultSort('full_name');
    }

    /**
     * "Arsipkan" — soft delete, dan kata itu dihindari di layar.
     *
     * Yang dilakukan tombol ini hanya mengisi `deleted_at`: barisnya tetap ada,
     * nilai, rapor, tagihan, pembayaran, kelas, percobaan ujian, dan permintaan
     * akun miliknya tidak tersentuh, dan fotonya tetap di penyimpanan privat
     * supaya pemulihan mengembalikan siswa yang utuh. Karena itu modalnya
     * menyebut "diarsipkan", bukan "dihapus permanen" — tidak ada jalur
     * penghapusan permanen di panel mana pun (butir 589).
     *
     * Tidak ada pagar "harus tanpa histori": justru histori itulah yang
     * dipertahankan arsip.
     */
    protected static function archiveAction(): Tables\Actions\Action
    {
        return Tables\Actions\DeleteAction::make('archive')
            ->label(__('Arsipkan'))
            ->icon('heroicon-m-archive-box-arrow-down')
            ->color('danger')
            ->modalHeading(__('Arsipkan siswa'))
            // Satu literal utuh, bukan sambungan: kunci terjemahan dipindai
            // dari kode, dan sambungan membuat yang terbaca hanya penggalan
            // pertamanya (butir 589).
            ->modalDescription(fn (Student $record): string => __('Siswa :name (NIS :nis) akan diarsipkan, bukan dihapus permanen. Data siswa dan seluruh histori akademik, kelas, tagihan, pembayaran, rapor, serta ujiannya tetap tersimpan dan dapat dipulihkan.', [
                'name' => $record->full_name,
                'nis' => $record->nis,
            ]))
            ->modalSubmitActionLabel(__('Arsipkan siswa'))
            ->successNotificationTitle(__('Siswa diarsipkan'));
    }

    /**
     * Opsi akun portal, dibatasi peran tertentu dan tenant aktif.
     *
     * @return array<int, string>
     */
    protected static function userOptions(RoleName $role): array
    {
        return User::query()
            ->whereHas('roles', fn (Builder $query) => $query->where('name', $role->value))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Cabang yang berlaku untuk validasi form ini.
     *
     * Super Admin memilihnya sendiri; peran School Level selalu memakai cabang
     * akunnya, apa pun yang dikirim klien.
     */
    protected static function resolveSchoolId(mixed $formValue = null): ?int
    {
        $user = Auth::user();

        if ($user?->isSuperAdmin() && filled($formValue)) {
            return (int) $formValue;
        }

        return $user?->school_id;
    }

    /**
     * Pratinjau foto tersimpan pada form edit.
     *
     * Pengganti bawaan Filament, yang untuk visibilitas privat membuat URL
     * bertanda tangan lima menit — tautan yang dapat dibuka siapa pun yang
     * memegangnya. Di sini URL-nya rute berwenang, dan hanya untuk berkas yang
     * memang milik record ini.
     *
     * @return array{name: string, size: int, type: ?string, url: string}|null
     */
    protected static function storedPhotoPreview(?Student $record, string $file): ?array
    {
        if ($record === null || $file !== $record->photo_url) {
            return null;
        }

        $path = StudentPhoto::storedPath($record);
        $url = StudentPhoto::url($record);

        if ($path === null || $url === null) {
            return null;
        }

        $disk = Storage::disk(StudentPhoto::disk());

        return [
            'name' => basename($path),
            'size' => $disk->size($path),
            'type' => $disk->mimeType($path) ?: null,
            'url' => $url,
        ];
    }

    /**
     * Guru dan wali kelas hanya melihat siswa kelas ajarnya.
     *
     * PRD 1.1.1 mendefinisikan perannya sebagai "Input nilai, **lihat daftar
     * siswa kelas ajar**, jadwal mengajar", dan SIS-04 menyebut "daftar siswa
     * di kelas **yang saya ampu**". Matriks 1.1.2 memberi GURU/WALI ⭕ pada
     * modul Data Siswa, tetapi ⭕ menyatakan boleh membaca modulnya — bukan
     * boleh membaca setiap barisnya. Yang spesifik menang, dan tanpa pagar ini
     * seorang guru melihat seluruh siswa cabang (butir 176).
     *
     * Kelas perwalian ikut, karena wali kelas memang bertanggung jawab atas
     * rapor dan absensi kelas itu (PRD 1.1.1) walaupun tidak mengajar mata
     * pelajaran di sana.
     *
     * Peran lain melewatinya tanpa perubahan sama sekali.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = Auth::user();

        return TeacherClassVisibility::constrainStudents($query, $user);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStudents::route('/'),
            'create' => Pages\CreateStudent::route('/create'),
            'view' => Pages\ViewStudent::route('/{record}'),
            'edit' => Pages\EditStudent::route('/{record}/edit'),
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
