<?php

namespace App\Filament\Resources;

use App\Enums\RoleName;
use App\Filament\Resources\SchoolClassResource\Pages;
use App\Filament\Resources\SchoolClassResource\RelationManagers;
use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

/**
 * KELAS-01 dan API 4.6 — /classes. Rombongan belajar per tahun ajaran.
 */
class SchoolClassResource extends Resource
{
    protected static ?string $model = SchoolClass::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-group';

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?string $navigationLabel = 'Kelas (Rombel)';

    protected static ?string $modelLabel = 'Kelas';

    protected static ?string $pluralModelLabel = 'Kelas';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('academic_year_id')
                ->label('Tahun Ajaran')
                ->relationship('academicYear', 'name')
                ->default(fn () => AcademicYear::current()?->id)
                ->required()
                ->preload()
                ->searchable(),

            Forms\Components\TextInput::make('name')
                ->label('Nama Kelas')
                ->required()
                ->maxLength(50)
                ->placeholder('X-A'),

            Forms\Components\Select::make('grade_level')
                ->label('Tingkat')
                // ERD 2.2: "Tingkat: 10, 11, atau 12".
                ->options([10 => '10', 11 => '11', 12 => '12'])
                ->required(),

            Forms\Components\Select::make('homeroom_teacher_id')
                ->label('Wali Kelas')
                ->options(fn () => static::homeroomTeacherOptions())
                ->searchable()
                // KELAS-01 poin 3: satu guru hanya boleh menjadi wali kelas
                // satu kelas per tahun ajaran.
                ->unique(
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule, Forms\Get $get) => $rule
                        ->where('academic_year_id', $get('academic_year_id')),
                )
                ->validationMessages([
                    'unique' => 'Guru ini sudah menjadi wali kelas lain pada tahun ajaran tersebut.',
                ])
                ->helperText('Dipilih dari pengguna aktif dengan peran Wali Kelas.'),

            Forms\Components\TextInput::make('room')
                ->label('Ruang')
                ->maxLength(50),

            Forms\Components\TextInput::make('capacity')
                ->label('Kapasitas')
                ->numeric()
                ->minValue(1)
                ->default(35)
                ->required(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Kelas')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('grade_level')
                    ->label('Tingkat')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('academicYear.name')
                    ->label('Tahun Ajaran')
                    ->sortable(),

                Tables\Columns\TextColumn::make('homeroomTeacher.name')
                    ->label('Wali Kelas')
                    ->placeholder('Belum ditentukan'),

                Tables\Columns\TextColumn::make('student_classes_count')
                    ->label('Siswa')
                    ->counts([
                        'studentClasses' => fn (Builder $query) => $query->where('status', 'ACTIVE'),
                    ])
                    ->suffix(fn (SchoolClass $record) => ' / '.$record->capacity),

                Tables\Columns\TextColumn::make('room')
                    ->label('Ruang')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('academic_year_id')
                    ->label('Tahun Ajaran')
                    ->relationship('academicYear', 'name'),

                Tables\Filters\SelectFilter::make('grade_level')
                    ->label('Tingkat')
                    ->options([10 => '10', 11 => '11', 12 => '12']),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([])
            ->defaultSort('name');
    }

    /**
     * ERD 2.2: homeroom_teacher_id → "guru dengan role WALI_KELAS".
     *
     * @return array<int, string>
     */
    protected static function homeroomTeacherOptions(): array
    {
        return User::query()
            ->active()
            ->whereHas('roles', fn (Builder $query) => $query->where('name', RoleName::WaliKelas->value))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\StudentsRelationManager::class,
            RelationManagers\SubjectsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSchoolClasses::route('/'),
            'create' => Pages\CreateSchoolClass::route('/create'),
            'edit' => Pages\EditSchoolClass::route('/{record}/edit'),
        ];
    }
}
