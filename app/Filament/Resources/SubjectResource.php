<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SubjectResource\Pages;
use App\Models\Subject;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * API 4.6 — /subjects. Modul "Kelas & Jadwal" pada matriks izin.
 */
class SubjectResource extends Resource
{
    protected static ?string $model = Subject::class;

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?string $navigationLabel = 'Mata Pelajaran';

    protected static ?string $modelLabel = 'Mata Pelajaran';

    protected static ?string $pluralModelLabel = 'Mata Pelajaran';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            // Cabang wajib dipilih Super Admin; tanpa itu penyimpanan berakhir
            // sebagai school_id NULL (butir 588).
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
                // Mata pelajaran yang pindah cabang akan memutus penugasan
                // kelas, nilai, dan jadwal yang memakainya.
                ->disabledOn('edit')
                ->columnSpanFull()
                ->helperText(__('Mata pelajaran ini milik cabang tersebut.')),

            Forms\Components\TextInput::make('name')
                ->label(__('Nama Mata Pelajaran'))
                ->required()
                ->maxLength(100)
                ->placeholder(__('Matematika')),

            Forms\Components\TextInput::make('code')
                ->label(__('Kode'))
                ->required()
                ->maxLength(20)
                ->placeholder('MTK'),

            Forms\Components\TextInput::make('credit_hours')
                ->label(__('Jam Pelajaran / Minggu'))
                ->numeric()
                ->minValue(0)
                ->maxValue(127),

            Forms\Components\Toggle::make('is_active')
                ->label(__('Aktif'))
                ->default(true),

            Forms\Components\Textarea::make('description')
                ->label(__('Deskripsi'))
                ->rows(3)
                ->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label(__('Kode'))
                    ->badge()
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->label(__('Nama'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('credit_hours')
                    ->label(__('JP/Minggu'))
                    ->placeholder('—'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label(__('Aktif'))
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label(__('Status Aktif')),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageSubjects::route('/'),
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
