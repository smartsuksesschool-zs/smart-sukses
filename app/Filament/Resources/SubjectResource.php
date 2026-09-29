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
use Illuminate\Validation\Rules\Unique;

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
                /*
                 * AC-KELAS-03 "per cabang": `subjects_school_id_code_unique`
                 * sudah menolak kode ganda di lapis basis data, tetapi
                 * penolakan itu tiba sebagai UniqueConstraintViolationException
                 * — layar galat 500 yang tidak memberi tahu admin apa yang
                 * harus ia perbaiki. Pagar ini memindahkan penolakan yang sama
                 * ke tempat yang dapat dibaca, dan lingkupnya **cabang**
                 * sehingga kode yang sama tetap boleh dipakai cabang lain
                 * (butir 592).
                 */
                ->unique(
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule, Forms\Get $get) => $rule
                        ->where('school_id', static::resolveSchoolId($get('school_id'))),
                )
                ->validationMessages([
                    'unique' => __('Kode mata pelajaran ini sudah dipakai di cabang tersebut.'),
                ])
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

    /**
     * Cabang yang berlaku untuk form ini: pilihan Super Admin, atau cabang akun
     * bagi peran School Level (butir 588).
     */
    protected static function resolveSchoolId(mixed $formValue = null): ?int
    {
        $user = Auth::user();

        if ($user?->isSuperAdmin() && filled($formValue)) {
            return (int) $formValue;
        }

        return $user?->school_id;
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
