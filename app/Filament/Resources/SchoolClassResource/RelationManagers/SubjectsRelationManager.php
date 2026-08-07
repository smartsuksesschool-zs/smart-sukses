<?php

namespace App\Filament\Resources\SchoolClassResource\RelationManagers;

use App\Enums\RoleName;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * ERD 2.2 — class_subjects: mata pelajaran yang diajarkan di kelas ini
 * oleh guru tertentu. Dasar untuk jadwal (schedules).
 */
class SubjectsRelationManager extends RelationManager
{
    protected static string $relationship = 'classSubjects';

    protected static ?string $title = 'Mata Pelajaran & Pengajar';

    protected static ?string $modelLabel = 'Mata Pelajaran Kelas';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('subject_id')
                ->label('Mata Pelajaran')
                ->options(fn () => Subject::query()->active()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->required(),

            Forms\Components\Select::make('teacher_id')
                ->label('Guru Pengajar')
                ->options(fn () => static::teacherOptions())
                ->searchable()
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('subject.code')
                    ->label('Kode')
                    ->badge(),

                Tables\Columns\TextColumn::make('subject.name')
                    ->label('Mata Pelajaran')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('teacher.name')
                    ->label('Guru Pengajar')
                    ->searchable(),

                Tables\Columns\TextColumn::make('schedules_count')
                    ->label('Jadwal')
                    ->counts('schedules'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Tambahkan Mata Pelajaran')
                    ->mutateFormDataUsing(function (array $data): array {
                        /** @var SchoolClass $class */
                        $class = $this->getOwnerRecord();

                        $data['class_id'] = $class->id;
                        $data['academic_year_id'] = $class->academic_year_id;
                        $data['school_id'] = $class->school_id;

                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([])
            ->defaultSort('id');
    }

    /**
     * Guru mata pelajaran mencakup peran GURU dan WALI_KELAS
     * (PRD 1.1.1: "Wali Kelas = semua akses guru + …").
     *
     * @return array<int, string>
     */
    protected static function teacherOptions(): array
    {
        return User::query()
            ->active()
            ->whereHas('roles', fn (Builder $query) => $query->whereIn('name', [
                RoleName::Guru->value,
                RoleName::WaliKelas->value,
            ]))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
