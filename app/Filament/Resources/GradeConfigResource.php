<?php

namespace App\Filament\Resources;

use App\Enums\GradeConfigStatus;
use App\Enums\GradeType;
use App\Filament\Resources\GradeConfigResource\Pages;
use App\Models\AcademicYear;
use App\Models\GradeConfig;
use App\Models\Subject;
use App\Services\Grading\GradeConfigVersionManager;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * NILAI-05 / API 4.8 — /grade-configs.
 *
 * Keputusan Sprint 4 butir 4: konfigurasi berjalan DRAFT → ACTIVE → LOCKED,
 * dan perubahan kebijakan bobot dilakukan dengan membuat versi baru.
 */
class GradeConfigResource extends Resource
{
    protected static ?string $model = GradeConfig::class;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationGroup = 'Akademik';

    protected static ?string $navigationLabel = 'Konfigurasi Penilaian';

    protected static ?string $modelLabel = 'Konfigurasi Penilaian';

    protected static ?string $pluralModelLabel = 'Konfigurasi Penilaian';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Cakupan')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('subject_id')
                        ->label('Mata Pelajaran')
                        ->options(fn () => Subject::query()->active()->orderBy('name')
                            ->get()->mapWithKeys(fn (Subject $s) => [$s->id => "{$s->name} ({$s->code})"]))
                        ->required()
                        ->searchable()
                        ->disabledOn('edit'),

                    Forms\Components\Select::make('academic_year_id')
                        ->label('Tahun Ajaran')
                        ->options(fn () => AcademicYear::query()->orderByDesc('start_date')->pluck('name', 'id'))
                        ->default(fn () => AcademicYear::current()?->id)
                        ->required()
                        ->disabledOn('edit'),

                    Forms\Components\TextInput::make('version')
                        ->label('Versi')
                        ->disabled()
                        ->dehydrated(false)
                        ->visibleOn('edit'),

                    Forms\Components\TextInput::make('status')
                        ->label('Status')
                        ->formatStateUsing(fn ($state) => $state instanceof GradeConfigStatus ? $state->label() : $state)
                        ->disabled()
                        ->dehydrated(false)
                        ->visibleOn('edit'),
                ]),

            Forms\Components\Section::make('Komponen & Bobot')
                ->description('Total bobot harus tepat 1.00 (100%). Sikap tidak masuk nilai akademik dan dilaporkan terpisah sebagai predikat.')
                ->schema([
                    Forms\Components\Repeater::make('components')
                        ->label('Komponen')
                        ->columns(2)
                        ->minItems(1)
                        ->defaultItems(3)
                        ->default([
                            ['type' => GradeType::Daily->value, 'weight' => 0.40],
                            ['type' => GradeType::Midterm->value, 'weight' => 0.30],
                            ['type' => GradeType::Final->value, 'weight' => 0.30],
                        ])
                        ->schema([
                            Forms\Components\Select::make('type')
                                ->label('Komponen')
                                // ATTITUDE sengaja tidak ditawarkan.
                                ->options(GradeType::academicOptions())
                                ->required(),

                            Forms\Components\TextInput::make('weight')
                                ->label('Bobot')
                                ->numeric()
                                ->step(0.01)
                                ->minValue(0)
                                ->maxValue(1)
                                ->required()
                                ->helperText('0.40 berarti 40%.'),
                        ])
                        ->rules([
                            fn (): Closure => function (string $attribute, $value, Closure $fail): void {
                                try {
                                    app(GradeConfigVersionManager::class)
                                        ->validateComponents(array_values((array) $value));
                                } catch (ValidationException $exception) {
                                    $fail((string) collect($exception->errors())->flatten()->first());
                                }
                            },
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('subject.name')
                    ->label('Mata Pelajaran')
                    ->description(fn (GradeConfig $record) => $record->subject?->code)
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('academicYear.name')
                    ->label('Tahun Ajaran'),

                Tables\Columns\TextColumn::make('version')
                    ->label('Versi')
                    ->badge()
                    ->formatStateUsing(fn (int $state) => "v{$state}")
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (GradeConfigStatus $state) => $state->label())
                    ->color(fn (GradeConfigStatus $state) => $state->color()),

                Tables\Columns\TextColumn::make('components')
                    ->label('Komponen')
                    ->formatStateUsing(fn (GradeConfig $record) => collect($record->components ?? [])
                        ->map(fn (array $c) => (GradeType::tryFrom($c['type'] ?? '')?->label() ?? '?')
                            .' '.round(((float) ($c['weight'] ?? 0)) * 100).'%')
                        ->join(' · ')),

                Tables\Columns\TextColumn::make('creator.name')
                    ->label('Dibuat Oleh')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(GradeConfigStatus::options()),

                Tables\Filters\SelectFilter::make('academic_year_id')
                    ->label('Tahun Ajaran')
                    ->options(fn () => AcademicYear::query()->orderByDesc('start_date')->pluck('name', 'id')),

                Tables\Filters\SelectFilter::make('subject_id')
                    ->label('Mata Pelajaran')
                    ->options(fn () => Subject::query()->orderBy('name')->pluck('name', 'id')),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                static::activateAction(),
                static::lockAction(),
                static::createVersionAction(),
            ])
            ->bulkActions([])
            ->defaultSort('id', 'desc');
    }

    /**
     * DRAFT → ACTIVE. Konfigurasi ACTIVE sebelumnya otomatis dikunci.
     */
    public static function activateAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('activate')
            ->label('Aktifkan')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->authorize('activate')
            ->requiresConfirmation()
            ->modalDescription('Konfigurasi aktif sebelumnya untuk mapel & tahun ajaran ini akan dikunci.')
            ->action(function (GradeConfig $record): void {
                try {
                    app(GradeConfigVersionManager::class)->activate($record);
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title('Gagal mengaktifkan')
                        ->body((string) collect($exception->errors())->flatten()->first())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title("Konfigurasi {$record->label()} kini aktif")
                    ->success()
                    ->send();
            });
    }

    /**
     * ACTIVE → LOCKED, dipakai saat semester difinalisasi.
     */
    public static function lockAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('lock')
            ->label('Kunci')
            ->icon('heroicon-o-lock-closed')
            ->color('danger')
            ->authorize('lock')
            ->requiresConfirmation()
            ->modalDescription('Konfigurasi terkunci tidak dapat diaktifkan atau diubah lagi.')
            ->action(function (GradeConfig $record): void {
                app(GradeConfigVersionManager::class)->lock($record);

                Notification::make()->title('Konfigurasi dikunci')->success()->send();
            });
    }

    /**
     * "Perubahan kebijakan bobot harus membuat Grade Config versi baru,
     * bukan overwrite konfigurasi lama."
     */
    public static function createVersionAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('createVersion')
            ->label('Buat Versi Baru')
            ->icon('heroicon-o-document-duplicate')
            ->color('warning')
            ->authorize('createVersion')
            ->requiresConfirmation()
            ->modalDescription('Versi baru dibuat sebagai DRAFT dengan komponen yang sama; konfigurasi lama tidak berubah.')
            ->action(function (GradeConfig $record): void {
                $new = app(GradeConfigVersionManager::class)->createNextVersion($record, Auth::user());

                Notification::make()
                    ->title("Versi {$new->version} dibuat sebagai draft")
                    ->success()
                    ->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGradeConfigs::route('/'),
            'create' => Pages\CreateGradeConfig::route('/create'),
            'edit' => Pages\EditGradeConfig::route('/{record}/edit'),
        ];
    }
}
