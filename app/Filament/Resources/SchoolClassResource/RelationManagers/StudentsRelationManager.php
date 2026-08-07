<?php

namespace App\Filament\Resources\SchoolClassResource\RelationManagers;

use App\Enums\StudentClassStatus;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentClass;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * KELAS-02 dan API 4.6 — POST/DELETE /classes/{id}/students.
 */
class StudentsRelationManager extends RelationManager
{
    protected static string $relationship = 'studentClasses';

    protected static ?string $title = 'Siswa di Kelas';

    protected static ?string $modelLabel = 'Siswa';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('student_id')
                ->label('Siswa')
                ->options(fn () => $this->eligibleStudents())
                ->searchable()
                ->required()
                ->helperText('Hanya siswa aktif yang belum terdaftar di kelas manapun pada tahun ajaran ini.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('student.nis')
                    ->label('NIS')
                    ->searchable(),

                Tables\Columns\TextColumn::make('student.full_name')
                    ->label('Nama Lengkap')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('student.gender')
                    ->label('L/P')
                    ->formatStateUsing(fn ($state) => $state?->value),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (StudentClassStatus $state) => $state->label())
                    ->color(fn (StudentClassStatus $state) => $state === StudentClassStatus::Active ? 'success' : 'gray'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(StudentClassStatus::options()),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Tambahkan Siswa')
                    ->mutateFormDataUsing(function (array $data): array {
                        $class = $this->getClass();

                        $data['class_id'] = $class->id;
                        $data['academic_year_id'] = $class->academic_year_id;
                        $data['school_id'] = $class->school_id;
                        $data['status'] = StudentClassStatus::Active->value;

                        return $data;
                    })
                    ->before(function (Tables\Actions\CreateAction $action) {
                        // Kapasitas kelas (ERD 2.2 classes.capacity).
                        if (! $this->getClass()->hasRemainingCapacity()) {
                            Notification::make()
                                ->title('Kapasitas kelas sudah penuh')
                                ->danger()
                                ->send();

                            $action->cancel();
                        }
                    }),
            ])
            ->actions([
                // API 4.6 — DELETE /classes/{id}/students/{studentId} (pindah kelas).
                // Baris tidak dihapus agar histori perpindahan tetap tersimpan.
                Tables\Actions\Action::make('markMoved')
                    ->label('Keluarkan dari Kelas')
                    ->icon('heroicon-o-arrow-right-start-on-rectangle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Baris tetap disimpan sebagai histori dengan status Pindah Kelas.')
                    ->visible(fn (StudentClass $record) => $record->status === StudentClassStatus::Active)
                    ->action(fn (StudentClass $record) => $record->update([
                        'status' => StudentClassStatus::Moved->value,
                    ])),
            ])
            ->bulkActions([])
            ->defaultSort('id');
    }

    protected function getClass(): SchoolClass
    {
        /** @var SchoolClass $class */
        $class = $this->getOwnerRecord();

        return $class;
    }

    /**
     * KELAS-02 poin 1 — siswa aktif yang belum punya penempatan ACTIVE
     * pada tahun ajaran kelas ini.
     *
     * @return array<int, string>
     */
    protected function eligibleStudents(): array
    {
        $class = $this->getClass();

        return Student::query()
            ->eligibleForYear($class->academic_year_id)
            ->orderBy('full_name')
            ->get()
            ->mapWithKeys(fn (Student $student) => [
                $student->id => "{$student->nis} — {$student->full_name}",
            ])
            ->all();
    }
}
