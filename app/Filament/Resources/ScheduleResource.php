<?php

namespace App\Filament\Resources;

use App\Enums\DayOfWeek;
use App\Filament\Resources\ScheduleResource\Pages;
use App\Models\ClassSubject;
use App\Models\Schedule;
use App\Models\SchoolClass;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * KELAS-03 & KELAS-04 dan API 4.6 — /schedules.
 */
class ScheduleResource extends Resource
{
    protected static ?string $model = Schedule::class;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?string $navigationLabel = 'Jadwal Pelajaran';

    protected static ?string $modelLabel = 'Jadwal';

    protected static ?string $pluralModelLabel = 'Jadwal';

    protected static ?int $navigationSort = 5;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('class_subject_id')
                ->label('Kelas & Mata Pelajaran')
                ->options(fn () => static::classSubjectOptions())
                ->searchable()
                ->required()
                ->live()
                ->helperText('Daftar diambil dari mata pelajaran yang sudah ditugaskan ke kelas.'),

            Forms\Components\Select::make('day_of_week')
                ->label('Hari')
                ->options(DayOfWeek::options())
                ->required()
                ->live(),

            Forms\Components\TimePicker::make('start_time')
                ->label('Jam Mulai')
                ->seconds(false)
                ->required()
                ->live(onBlur: true),

            Forms\Components\TimePicker::make('end_time')
                ->label('Jam Selesai')
                ->seconds(false)
                ->required()
                ->after('start_time')
                ->live(onBlur: true)
                // KELAS-03 poin 2 — deteksi konflik guru / ruangan / kelas.
                // Dipasang pada field wajib agar selalu dievaluasi.
                ->rule(static fn (Forms\Get $get, ?Schedule $record): Closure => static function (
                    string $attribute,
                    $value,
                    Closure $fail,
                ) use ($get, $record): void {
                    static::assertNoConflict($get, $record, $fail);
                }),

            Forms\Components\TextInput::make('room')
                ->label('Ruang')
                ->maxLength(50)
                ->live(onBlur: true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('day_of_week')
                    ->label('Hari')
                    ->badge()
                    ->formatStateUsing(fn (DayOfWeek $state) => $state->label())
                    ->sortable(),

                Tables\Columns\TextColumn::make('start_time')
                    ->label('Mulai')
                    ->formatStateUsing(fn ($state) => substr((string) $state, 0, 5))
                    ->sortable(),

                Tables\Columns\TextColumn::make('end_time')
                    ->label('Selesai')
                    ->formatStateUsing(fn ($state) => substr((string) $state, 0, 5)),

                Tables\Columns\TextColumn::make('classSubject.schoolClass.name')
                    ->label('Kelas')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('classSubject.subject.name')
                    ->label('Mata Pelajaran')
                    ->searchable(),

                Tables\Columns\TextColumn::make('classSubject.teacher.name')
                    ->label('Guru')
                    ->searchable(),

                Tables\Columns\TextColumn::make('room')
                    ->label('Ruang')
                    ->placeholder('—'),
            ])
            ->filters([
                // API 4.6 — GET /schedules filter class_id, teacher_id, day_of_week.
                Tables\Filters\SelectFilter::make('class')
                    ->label('Kelas')
                    ->options(fn () => SchoolClass::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->forClass((int) $data['value'])
                        : $query),

                Tables\Filters\SelectFilter::make('day_of_week')
                    ->label('Hari')
                    ->options(DayOfWeek::options()),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([])
            ->defaultSort('day_of_week');
    }

    /**
     * Menjalankan pemeriksaan konflik dan menggagalkan validasi bila bentrok.
     */
    protected static function assertNoConflict(Forms\Get $get, ?Schedule $record, Closure $fail): void
    {
        $classSubjectId = $get('class_subject_id');
        $day = $get('day_of_week');
        $start = $get('start_time');
        $end = $get('end_time');

        if (blank($classSubjectId) || blank($day) || blank($start) || blank($end)) {
            return;
        }

        $classSubject = ClassSubject::find($classSubjectId);

        if ($classSubject === null) {
            return;
        }

        $conflicts = Schedule::conflictsFor(
            classSubject: $classSubject,
            dayOfWeek: (int) $day,
            startTime: static::normaliseTime($start),
            endTime: static::normaliseTime($end),
            room: $get('room'),
            ignoreId: $record?->getKey(),
        );

        if ($conflicts->isEmpty()) {
            return;
        }

        $fail('Jadwal bentrok dengan: '.$conflicts
            ->map(fn (Schedule $schedule) => $schedule->conflictSummary())
            ->implode('; '));
    }

    protected static function normaliseTime(string $time): string
    {
        return substr($time, 0, 5).':00';
    }

    /**
     * @return array<int, string>
     */
    protected static function classSubjectOptions(): array
    {
        return ClassSubject::query()
            ->with(['schoolClass', 'subject', 'teacher'])
            ->get()
            ->mapWithKeys(fn (ClassSubject $classSubject) => [
                $classSubject->id => sprintf(
                    '%s — %s (%s)',
                    $classSubject->schoolClass?->name ?? '-',
                    $classSubject->subject?->name ?? '-',
                    $classSubject->teacher?->name ?? '-',
                ),
            ])
            ->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageSchedules::route('/'),
        ];
    }
}
