<?php

namespace App\Filament\Resources;

use App\Enums\AssessmentType;
use App\Enums\GradeType;
use App\Filament\Resources\GradeResource\Pages;
use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\Grade;
use App\Models\Student;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * NILAI-01 / API 4.8 — /grades.
 *
 * Halaman ini untuk melihat & menyunting nilai satuan; input massal per
 * class_subject ada di App\Filament\Pages\InputNilai.
 */
class GradeResource extends Resource
{
    protected static ?string $model = Grade::class;

    protected static ?string $navigationIcon = 'heroicon-o-pencil-square';

    protected static ?string $navigationGroup = 'Akademik';

    protected static ?string $navigationLabel = 'Daftar Nilai';

    protected static ?string $modelLabel = 'Nilai';

    protected static ?string $pluralModelLabel = 'Nilai';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('class_subject_id')
                ->label('Kelas — Mata Pelajaran')
                ->options(fn () => static::classSubjectOptions())
                ->searchable()
                ->required()
                ->disabledOn('edit'),

            Forms\Components\Select::make('student_id')
                ->label('Siswa')
                ->options(fn () => Student::query()->active()->orderBy('full_name')->pluck('full_name', 'id'))
                ->searchable()
                ->required()
                ->disabledOn('edit'),

            Forms\Components\Select::make('grade_type')
                ->label('Komponen')
                ->options(GradeType::options())
                ->required()
                ->disabledOn('edit'),

            Forms\Components\Select::make('assessment_type')
                ->label('Jenis Penilaian')
                ->options(AssessmentType::options())
                ->default(AssessmentType::Summative->value)
                ->required()
                ->helperText('Hanya penilaian sumatif yang dihitung ke rapor.'),

            Forms\Components\TextInput::make('score')
                ->label('Nilai')
                ->numeric()
                // NILAI-01 poin 1 — skala 0–100.
                ->minValue(Grade::MIN_SCORE)
                ->maxValue(Grade::MAX_SCORE)
                ->step(0.01)
                ->required(),

            Forms\Components\TextInput::make('description')
                ->label('Keterangan')
                ->maxLength(200)
                ->placeholder('Ulangan Harian Bab 3')
                ->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('student.full_name')
                    ->label('Siswa')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('classSubject.subject.name')
                    ->label('Mata Pelajaran'),

                Tables\Columns\TextColumn::make('classSubject.schoolClass.name')
                    ->label('Kelas')
                    ->badge(),

                Tables\Columns\TextColumn::make('grade_type')
                    ->label('Komponen')
                    ->badge()
                    ->formatStateUsing(fn (GradeType $state) => $state->label()),

                Tables\Columns\TextColumn::make('assessment_type')
                    ->label('Jenis')
                    ->badge()
                    ->formatStateUsing(fn (AssessmentType $state) => $state->label())
                    ->color(fn (AssessmentType $state) => $state->color()),

                Tables\Columns\TextColumn::make('score')
                    ->label('Nilai')
                    ->numeric(2)
                    ->sortable(),

                Tables\Columns\TextColumn::make('weight')
                    ->label('Bobot (snapshot)')
                    ->placeholder('—')
                    ->numeric(2)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('graded_at')
                    ->label('Diinput')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            // API 4.8 — filter: student_id, class_subject_id, academic_year_id, grade_type.
            ->filters([
                Tables\Filters\SelectFilter::make('class_subject_id')
                    ->label('Kelas — Mapel')
                    ->options(fn () => static::classSubjectOptions()),

                Tables\Filters\SelectFilter::make('student_id')
                    ->label('Siswa')
                    ->options(fn () => Student::query()->orderBy('full_name')->pluck('full_name', 'id'))
                    ->searchable(),

                Tables\Filters\SelectFilter::make('academic_year_id')
                    ->label('Tahun Ajaran')
                    ->options(fn () => AcademicYear::query()->orderByDesc('start_date')->pluck('name', 'id')),

                Tables\Filters\SelectFilter::make('grade_type')
                    ->label('Komponen')
                    ->options(GradeType::options()),

                Tables\Filters\SelectFilter::make('assessment_type')
                    ->label('Jenis Penilaian')
                    ->options(AssessmentType::options()),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([])
            ->defaultSort('graded_at', 'desc');
    }

    /**
     * @return array<int, string>
     */
    protected static function classSubjectOptions(): array
    {
        return ClassSubject::query()
            ->with(['schoolClass', 'subject'])
            ->get()
            ->mapWithKeys(fn (ClassSubject $cs) => [
                $cs->id => trim(($cs->schoolClass?->name ?? '?').' — '.($cs->subject?->name ?? '?')),
            ])
            ->all();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['student', 'classSubject.subject', 'classSubject.schoolClass']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGrades::route('/'),
            'create' => Pages\CreateGrade::route('/create'),
            'edit' => Pages\EditGrade::route('/{record}/edit'),
        ];
    }
}
