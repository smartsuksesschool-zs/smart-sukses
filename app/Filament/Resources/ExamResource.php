<?php

namespace App\Filament\Resources;

use App\Enums\ExamStatus;
use App\Filament\Resources\ExamResource\Pages;
use App\Filament\Resources\ExamResource\RelationManagers\AttemptsRelationManager;
use App\Filament\Resources\ExamResource\RelationManagers\QuestionsRelationManager;
use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\Exam;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Scopes\SchoolScope;
use App\Models\Subject;
use App\Services\Cbt\ExamPublisher;
use Closure;
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
use Illuminate\Validation\ValidationException;

/**
 * Ujian Online (CBT) — permukaan penulisan soal untuk guru dan admin cabang.
 *
 * Di luar blueprint Phase 1; lihat docs/owner-scope-changes.md dan
 * docs/cbt-mvp-scope.md. Batch C2 hanya membangun sisi penulis — belum ada
 * satu pun halaman siswa, penilaian, maupun jembatan ke nilai akademik.
 *
 * Menu ini tidak muncul bagi peran yang tidak berwenang: Filament menyembunyikan
 * navigasi resource ketika `viewAny` ditolak, dan `ExamPolicy::viewAny()`
 * menuntut `grade.view`. Bendahara karena itu tidak melihat menunya sama sekali,
 * bukan melihatnya lalu menerima 403 (butir 293).
 */
class ExamResource extends Resource
{
    protected static ?string $model = Exam::class;

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationGroup = 'Akademik';

    protected static ?string $navigationLabel = 'Ujian Online';

    protected static ?string $modelLabel = 'Ujian Online';

    protected static ?string $pluralModelLabel = 'Ujian Online';

    protected static ?int $navigationSort = 6;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identitas Ujian')
                ->columns(2)
                ->schema([
                    // Hanya Super Admin yang melihat pemilih cabang — pola dan
                    // alasan yang sama dengan GradeConfigResource (butir 41):
                    // justru merekalah yang `school_id`-nya NULL.
                    Forms\Components\Select::make('school_id')
                        ->label('Cabang Sekolah')
                        ->options(fn () => static::schoolOptions())
                        ->searchable()
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn (Forms\Set $set) => $set('class_subject_id', null))
                        ->visible(fn () => Auth::user()?->isSuperAdmin())
                        ->disabledOn('edit')
                        ->columnSpanFull(),

                    Forms\Components\Select::make('class_subject_id')
                        ->label('Kelas — Mata Pelajaran')
                        ->options(fn (Forms\Get $get) => static::classSubjectOptions(
                            static::resolveSchoolId($get('school_id')),
                        ))
                        ->searchable()
                        ->required()
                        ->live()
                        // Daftar pilihannya sudah disaring, tetapi payload
                        // Livewire dapat dikirim apa adanya. Pagar terakhirnya
                        // policy — bukan daftar pilihan (butir 294).
                        ->rules([fn (): Closure => static::authorisedClassSubjectRule()])
                        ->columnSpanFull(),

                    // Tahun ajaran **diturunkan**, tidak dipilih: ia sudah
                    // melekat pada kelas-mapelnya, dan membiarkannya dipilih
                    // hanya menciptakan satu kombinasi tidak sah yang harus
                    // ditolak di tempat lain (butir 295).
                    Forms\Components\Placeholder::make('academic_year_preview')
                        ->label('Tahun Ajaran')
                        ->content(fn (Forms\Get $get) => static::academicYearLabel($get('class_subject_id')))
                        ->columnSpanFull(),

                    Forms\Components\TextInput::make('title')
                        ->label('Judul')
                        ->required()
                        ->maxLength(200)
                        ->columnSpanFull(),

                    Forms\Components\Textarea::make('description')
                        ->label('Deskripsi')
                        ->rows(3)
                        ->columnSpanFull()
                        ->helperText('Opsional — petunjuk singkat untuk siswa.'),
                ]),

            Forms\Components\Section::make('Waktu Pengerjaan')
                ->columns(3)
                ->description('Seluruhnya ditentukan server. Jam pada perangkat siswa tidak dipakai.')
                ->schema([
                    Forms\Components\TextInput::make('duration_minutes')
                        ->label('Durasi (menit)')
                        ->numeric()
                        ->required()
                        ->minValue(1)
                        ->maxValue(1440)
                        ->default(60),

                    Forms\Components\DateTimePicker::make('available_from')
                        ->label('Dibuka')
                        ->seconds(false)
                        ->required(),

                    Forms\Components\DateTimePicker::make('available_until')
                        ->label('Ditutup')
                        ->seconds(false)
                        ->required()
                        ->after('available_from')
                        ->helperText('Harus setelah waktu buka.'),
                ]),
        ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Identitas')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('title')->label('Judul')->columnSpanFull(),
                    Infolists\Components\TextEntry::make('classSubject.schoolClass.name')->label('Kelas'),
                    Infolists\Components\TextEntry::make('classSubject.subject.name')->label('Mata Pelajaran'),
                    Infolists\Components\TextEntry::make('academicYear.name')->label('Tahun Ajaran'),
                    Infolists\Components\TextEntry::make('status')
                        ->label('Status')
                        ->badge()
                        ->formatStateUsing(fn (ExamStatus $state) => $state->label())
                        ->color(fn (ExamStatus $state) => $state->color()),
                    Infolists\Components\TextEntry::make('creator.name')->label('Dibuat Oleh')->placeholder('—'),
                    Infolists\Components\TextEntry::make('description')->label('Deskripsi')->placeholder('—')
                        ->columnSpanFull(),
                ]),

            Infolists\Components\Section::make('Waktu & Bobot')
                ->columns(4)
                ->schema([
                    Infolists\Components\TextEntry::make('available_from')->label('Dibuka')->dateTime('d M Y H:i'),
                    Infolists\Components\TextEntry::make('available_until')->label('Ditutup')->dateTime('d M Y H:i'),
                    Infolists\Components\TextEntry::make('duration_minutes')->label('Durasi')
                        ->formatStateUsing(fn (int $state) => "{$state} menit"),
                    Infolists\Components\TextEntry::make('total_points')
                        ->label('Total Bobot')
                        ->state(fn (Exam $record) => $record->totalPoints()),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Judul')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('classSubject.subject.name')
                    ->label('Mata Pelajaran')
                    ->description(fn (Exam $record) => $record->classSubject?->schoolClass?->name),

                Tables\Columns\TextColumn::make('academicYear.name')
                    ->label('Tahun Ajaran')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('creator.name')
                    ->label('Dibuat Oleh')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (ExamStatus $state) => $state->label())
                    ->color(fn (ExamStatus $state) => $state->color()),

                Tables\Columns\TextColumn::make('available_from')
                    ->label('Dibuka')
                    ->dateTime('d M Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('available_until')
                    ->label('Ditutup')
                    ->dateTime('d M Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('duration_minutes')
                    ->label('Durasi')
                    ->formatStateUsing(fn (int $state) => "{$state}'")
                    ->toggleable(),

                // Dihitung database lewat withCount, bukan satu query per baris.
                Tables\Columns\TextColumn::make('questions_count')
                    ->label('Soal')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('attempts_count')
                    ->label('Dikerjakan')
                    ->badge()
                    ->color(fn (int $state) => $state > 0 ? 'warning' : 'gray'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(ExamStatus::options()),

                Tables\Filters\SelectFilter::make('academic_year_id')
                    ->label('Tahun Ajaran')
                    ->options(fn () => AcademicYear::query()->orderByDesc('start_date')->pluck('name', 'id')),

                Tables\Filters\SelectFilter::make('class_id')
                    ->label('Kelas')
                    ->options(fn () => SchoolClass::query()->orderBy('name')->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $q) => $q->whereHas(
                            'classSubject',
                            fn (Builder $cs) => $cs->where('class_id', $data['value']),
                        ),
                    )),

                Tables\Filters\SelectFilter::make('subject_id')
                    ->label('Mata Pelajaran')
                    ->options(fn () => Subject::query()->orderBy('name')->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $q) => $q->whereHas(
                            'classSubject',
                            fn (Builder $cs) => $cs->where('subject_id', $data['value']),
                        ),
                    )),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                static::publishAction(),
                static::unpublishAction(),
                static::closeAction(),
                Tables\Actions\DeleteAction::make()
                    ->modalDescription('Ujian beserta seluruh soal dan pilihan jawabannya akan dihapus.'),
            ])
            ->bulkActions([])
            ->defaultSort('id', 'desc');
    }

    // ------------------------------------------------------------- aksi siklus

    public static function publishAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('publish')
            ->label('Terbitkan')
            ->icon('heroicon-o-paper-airplane')
            ->color('success')
            ->authorize('publish')
            ->requiresConfirmation()
            ->modalDescription('Setelah terbit, ujian dapat dikerjakan siswa pada rentang waktunya. '
                .'Soal masih dapat diubah selama belum ada yang mengerjakan.')
            ->action(fn (Exam $record) => static::runLifecycle(
                fn () => app(ExamPublisher::class)->publish($record, Auth::user()),
                'Ujian belum dapat diterbitkan',
                'Ujian diterbitkan',
            ));
    }

    public static function unpublishAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('unpublish')
            ->label('Tarik Kembali')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->authorize('unpublish')
            ->requiresConfirmation()
            ->modalDescription('Ujian kembali menjadi draf dan tidak lagi terlihat siswa.')
            ->action(fn (Exam $record) => static::runLifecycle(
                fn () => app(ExamPublisher::class)->unpublish($record, Auth::user()),
                'Ujian tidak dapat ditarik kembali',
                'Ujian ditarik kembali menjadi draf',
            ));
    }

    public static function closeAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('close')
            ->label('Tutup')
            ->icon('heroicon-o-lock-closed')
            ->color('danger')
            ->authorize('close')
            ->requiresConfirmation()
            ->modalDescription('Ujian yang ditutup tidak dapat dibuka kembali. Tidak ada data yang dihapus.')
            ->action(fn (Exam $record) => static::runLifecycle(
                fn () => app(ExamPublisher::class)->close($record, Auth::user()),
                'Ujian tidak dapat ditutup',
                'Ujian ditutup',
            ));
    }

    /**
     * Pola yang sama dengan ReportCardResource::publishAction() — kegagalan
     * validasi menjadi notifikasi, bukan halaman error.
     */
    protected static function runLifecycle(Closure $transition, string $failureTitle, string $successTitle): void
    {
        try {
            $transition();
        } catch (ValidationException $exception) {
            Notification::make()
                ->title($failureTitle)
                ->body((string) collect($exception->errors())->flatten()->first())
                ->danger()
                ->send();

            return;
        }

        Notification::make()->title($successTitle)->success()->send();
    }

    // ------------------------------------------------------------- penunjang

    /**
     * Cabang tempat ujian ini dibuat.
     *
     * Nilai form hanya dipercaya dari Super Admin; bagi peran lain field-nya
     * tidak pernah dirender, sehingga apa pun yang muncul di state Livewire
     * adalah selundupan dan diabaikan (butir 41).
     */
    public static function resolveSchoolId(mixed $formValue = null): ?int
    {
        $user = Auth::user();

        if ($user?->isSuperAdmin() && filled($formValue)) {
            return (int) $formValue;
        }

        return $user?->school_id;
    }

    /**
     * Kelas-mapel yang boleh ditulisi ujian oleh pengguna ini.
     *
     * Guru dan Wali Kelas hanya melihat kelas yang mereka ampu — penyaring yang
     * sama dengan InputNilai (API 4.8: "Guru hanya bisa input nilai untuk kelas
     * yang dia ampu"). Administrator cabang melihat seluruh kelas-mapel
     * cabangnya.
     *
     * @return array<int, string>
     */
    public static function classSubjectOptions(?int $schoolId): array
    {
        $user = Auth::user();

        // Tanpa cabang tidak ada yang dapat ditawarkan. Bagi Super Admin ini
        // berarti "pilih cabangnya dulu": global scope tidak membatasi apa pun
        // bagi mereka, sehingga tanpa penyaring ini daftarnya berisi kelas
        // seluruh cabang.
        if ($schoolId === null || $user === null) {
            return [];
        }

        return ClassSubject::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $schoolId)
            ->when(
                ! $user->isSuperAdmin() && ! $user->isSchoolAdmin(),
                fn (Builder $query) => $query->where('teacher_id', $user->getKey()),
            )
            ->with(['schoolClass', 'subject'])
            ->get()
            ->mapWithKeys(fn (ClassSubject $cs) => [
                $cs->getKey() => trim(($cs->schoolClass?->name ?? '?').' — '.($cs->subject?->name ?? '?')),
            ])
            ->all();
    }

    /**
     * Aturan terakhir atas `class_subject_id`: policy, bukan daftar pilihan.
     *
     * Menutup dua hal sekaligus — kelas-mapel cabang lain, dan kelas-mapel yang
     * tidak diampu pengguna ini — tanpa menuliskan ulang satu pun syaratnya
     * (butir 294).
     */
    protected static function authorisedClassSubjectRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $classSubject = ClassSubject::query()
                ->withoutGlobalScope(SchoolScope::class)
                ->find($value);

            if ($classSubject === null) {
                $fail('Kelas — mata pelajaran tidak ditemukan.');

                return;
            }

            if (Auth::user()?->can('author', [Exam::class, $classSubject]) !== true) {
                $fail('Anda tidak berwenang membuat ujian untuk kelas — mata pelajaran ini.');
            }
        };
    }

    /**
     * Tahun ajaran kelas-mapel yang sedang dipilih, sebagai keterangan.
     */
    public static function academicYearLabel(mixed $classSubjectId): string
    {
        $classSubject = blank($classSubjectId)
            ? null
            : ClassSubject::query()
                ->withoutGlobalScope(SchoolScope::class)
                ->with('academicYear')
                ->find($classSubjectId);

        return $classSubject?->academicYear?->name
            ?? 'Mengikuti kelas — mata pelajaran yang dipilih.';
    }

    /**
     * Tahun ajaran yang wajib dipakai ujian pada kelas-mapel ini.
     */
    public static function academicYearIdFor(mixed $classSubjectId): ?int
    {
        $value = ClassSubject::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->whereKey($classSubjectId)
            ->value('academic_year_id');

        return $value === null ? null : (int) $value;
    }

    /**
     * @return array<int, string>
     */
    protected static function schoolOptions(): array
    {
        return School::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * NFR 1.4 — daftar ujian tidak boleh menanyakan mata pelajaran, kelas,
     * pembuat, jumlah soal, dan jumlah pengerjaan sekali per baris. Kelimanya
     * dimuat di sini, dan `attempts_count` juga yang dipakai `hasAttempts()`
     * saat menimbang aksi per baris (butir 289).
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['classSubject.subject', 'classSubject.schoolClass', 'academicYear', 'creator'])
            ->withCount(['questions', 'attempts']);
    }

    public static function getRelations(): array
    {
        return [
            QuestionsRelationManager::class,
            AttemptsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListExams::route('/'),
            'create' => Pages\CreateExam::route('/create'),
            'view' => Pages\ViewExam::route('/{record}'),
            'edit' => Pages\EditExam::route('/{record}/edit'),
        ];
    }
}
