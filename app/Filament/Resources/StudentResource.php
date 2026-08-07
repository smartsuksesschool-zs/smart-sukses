<?php

namespace App\Filament\Resources;

use App\Enums\Gender;
use App\Enums\RoleName;
use App\Enums\StudentStatus;
use App\Filament\Resources\StudentResource\Pages;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
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
            Forms\Components\Section::make('Identitas Siswa')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('nis')
                        ->label('NIS')
                        ->required()
                        ->maxLength(20)
                        // SIS-01 poin 3: NIS unik dalam satu sekolah.
                        ->unique(
                            ignoreRecord: true,
                            modifyRuleUsing: fn (Unique $rule) => $rule->where('school_id', static::currentSchoolId()),
                        )
                        ->helperText('Nomor Induk Siswa, unik dalam satu cabang.'),

                    Forms\Components\TextInput::make('nisn')
                        ->label('NISN')
                        ->maxLength(10)
                        // SIS-01 poin 2: validasi format NISN (10 digit angka).
                        ->rule('digits:10')
                        ->helperText('10 digit angka (opsional).'),

                    Forms\Components\TextInput::make('full_name')
                        ->label('Nama Lengkap')
                        ->required()
                        ->maxLength(150)
                        ->columnSpanFull(),

                    Forms\Components\Select::make('gender')
                        ->label('Jenis Kelamin')
                        ->options(Gender::options())
                        ->required(),

                    Forms\Components\TextInput::make('religion')
                        ->label('Agama')
                        ->maxLength(30),

                    Forms\Components\TextInput::make('birth_place')
                        ->label('Tempat Lahir')
                        ->maxLength(100),

                    Forms\Components\DatePicker::make('birth_date')
                        ->label('Tanggal Lahir')
                        ->maxDate(now()),

                    Forms\Components\Textarea::make('address')
                        ->label('Alamat')
                        ->rows(3)
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Foto')
                ->schema([
                    // SIS-03: JPG/PNG/WEBP, maks 2 MB, auto-resize 400x400.
                    Forms\Components\FileUpload::make('photo_url')
                        ->label('Foto Siswa')
                        ->image()
                        ->avatar()
                        ->disk('public')
                        ->directory('students')
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                        ->maxSize(2048)
                        ->imageResizeMode('cover')
                        ->imageCropAspectRatio('1:1')
                        ->imageResizeTargetWidth('400')
                        ->imageResizeTargetHeight('400')
                        ->helperText('JPG/PNG/WEBP, maksimal 2 MB. Otomatis dipotong 400×400 px.'),
                ]),

            Forms\Components\Section::make('Data Orang Tua / Wali')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('parent_name')
                        ->label('Nama Orang Tua / Wali')
                        ->maxLength(150),

                    Forms\Components\TextInput::make('parent_phone')
                        ->label('No. HP Orang Tua')
                        ->tel()
                        ->maxLength(20),

                    Forms\Components\TextInput::make('parent_email')
                        ->label('Email Orang Tua')
                        ->email()
                        ->maxLength(150),

                    Forms\Components\Select::make('parent_user_id')
                        ->label('Akun Portal Orang Tua')
                        ->options(fn () => static::userOptions(RoleName::OrangTua))
                        ->searchable()
                        ->helperText('Opsional — hubungkan ke akun dengan peran Orang Tua.'),
                ]),

            Forms\Components\Section::make('Status Akademik')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('entry_year')
                        ->label('Tahun Masuk')
                        ->numeric()
                        ->minValue(1900)
                        ->maxValue((int) now()->addYear()->format('Y')),

                    Forms\Components\Select::make('status')
                        ->label('Status')
                        ->options(StudentStatus::options())
                        ->default(StudentStatus::Active->value)
                        ->required(),

                    Forms\Components\Select::make('user_id')
                        ->label('Akun Portal Siswa')
                        ->options(fn () => static::userOptions(RoleName::Siswa))
                        ->searchable()
                        ->helperText('Opsional — siswa tidak wajib punya akun portal.'),

                    Forms\Components\Textarea::make('notes')
                        ->label('Catatan')
                        ->rows(3)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('photo_url')
                    ->label('Foto')
                    ->circular()
                    ->disk('public')
                    ->defaultImageUrl(fn () => null),

                Tables\Columns\TextColumn::make('nis')
                    ->label('NIS')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('full_name')
                    ->label('Nama Lengkap')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('gender')
                    ->label('L/P')
                    ->formatStateUsing(fn (Gender $state) => $state->value),

                Tables\Columns\TextColumn::make('activeStudentClass.schoolClass.name')
                    ->label('Kelas')
                    ->placeholder('Belum ada kelas')
                    ->badge(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (StudentStatus $state) => $state->label())
                    ->color(fn (StudentStatus $state) => $state->color()),

                Tables\Columns\TextColumn::make('parent_phone')
                    ->label('HP Ortu')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(StudentStatus::options()),

                // API 4.5 — filter class_id.
                Tables\Filters\SelectFilter::make('class')
                    ->label('Kelas')
                    ->options(fn () => SchoolClass::query()->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->inClass((int) $data['value'])
                        : $query),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

                // API 4.5 — PATCH /students/{id}/status.
                Tables\Actions\Action::make('changeStatus')
                    ->label('Ubah Status')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (Student $record) => Auth::user()?->can('changeStatus', $record))
                    ->form([
                        Forms\Components\Select::make('status')
                            ->label('Status Baru')
                            ->options(StudentStatus::options())
                            ->required(),
                    ])
                    ->fillForm(fn (Student $record) => ['status' => $record->status->value])
                    ->action(function (Student $record, array $data) {
                        $record->update(['status' => $data['status']]);

                        Notification::make()
                            ->title('Status siswa diperbarui')
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([])
            ->defaultSort('full_name');
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

    protected static function currentSchoolId(): ?int
    {
        return Auth::user()?->school_id;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStudents::route('/'),
            'create' => Pages\CreateStudent::route('/create'),
            'edit' => Pages\EditStudent::route('/{record}/edit'),
        ];
    }
}
