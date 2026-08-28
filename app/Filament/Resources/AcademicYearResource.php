<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AcademicYearResource\Pages;
use App\Models\AcademicYear;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * API 4.6 — /academic-years. Modul "Kelas & Jadwal" pada matriks izin.
 */
class AcademicYearResource extends Resource
{
    protected static ?string $model = AcademicYear::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?string $navigationLabel = 'Tahun Ajaran';

    protected static ?string $modelLabel = 'Tahun Ajaran';

    protected static ?string $pluralModelLabel = 'Tahun Ajaran';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label(__('Nama Tahun Ajaran'))
                ->required()
                ->maxLength(20)
                ->placeholder('2024/2025 Semester 1')
                ->helperText(__('Maksimal 20 karakter sesuai ERD.')),

            Forms\Components\Select::make('semester')
                ->label(__('Semester'))
                ->options([1 => 'Semester 1', 2 => 'Semester 2'])
                ->required(),

            Forms\Components\DatePicker::make('start_date')
                ->label(__('Tanggal Mulai'))
                ->required(),

            Forms\Components\DatePicker::make('end_date')
                ->label(__('Tanggal Selesai'))
                ->required()
                ->afterOrEqual('start_date'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('Tahun Ajaran'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('semester')
                    ->label(__('Semester'))
                    ->badge(),

                Tables\Columns\TextColumn::make('start_date')
                    ->label(__('Mulai'))
                    ->date('d M Y'),

                Tables\Columns\TextColumn::make('end_date')
                    ->label(__('Selesai'))
                    ->date('d M Y'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label(__('Aktif'))
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label(__('Status Aktif')),
            ])
            ->actions([
                // API 4.6 — PATCH /academic-years/{id}/activate.
                Tables\Actions\Action::make('activate')
                    ->label(__('Jadikan Aktif'))
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription(__('Tahun ajaran lain di cabang ini otomatis dinonaktifkan.'))
                    ->visible(fn (AcademicYear $record) => ! $record->is_active
                        && auth()->user()?->can('update', $record))
                    ->action(function (AcademicYear $record) {
                        $record->activate();

                        Notification::make()
                            ->title("Tahun ajaran {$record->name} kini aktif")
                            ->success()
                            ->send();
                    }),

                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([])
            ->defaultSort('start_date', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageAcademicYears::route('/'),
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
