<?php

namespace App\Filament\Resources;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Filament\Resources\RoleResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * PRD 1.1.1 & 1.1.2 — Definisi peran dan matriks izin per modul.
 * Hanya Super Admin yang boleh mengubah definisi peran (lihat RolePolicy).
 */
class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'Manajemen Akses';

    protected static ?string $navigationLabel = 'Peran & Izin';

    protected static ?string $modelLabel = 'Peran';

    protected static ?string $pluralModelLabel = 'Peran';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Peran')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Kode Peran')
                        ->required()
                        ->maxLength(125)
                        ->unique(ignoreRecord: true)
                        ->helperText('Contoh: SCHOOL_ADMIN'),

                    Forms\Components\TextInput::make('guard_name')
                        ->label('Guard')
                        ->default('web')
                        ->required()
                        ->maxLength(125),
                ]),

            Forms\Components\Section::make('Izin per Modul')
                ->description('⭕ Lihat = akses baca saja, ✅ Kelola = akses penuh (create, update, delete).')
                ->schema([
                    Forms\Components\CheckboxList::make('permissions')
                        ->label('')
                        ->relationship('permissions', 'name')
                        ->getOptionLabelFromRecordUsing(
                            fn (Permission $record) => PermissionName::tryFrom($record->name)?->label() ?? $record->name,
                        )
                        ->searchable()
                        ->bulkToggleable()
                        ->columns(2),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Kode Peran')
                    ->badge()
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('label')
                    ->label('Nama Peran')
                    ->state(fn (Role $record) => RoleName::tryFrom($record->name)?->label() ?? '—'),

                Tables\Columns\TextColumn::make('level')
                    ->label('Level')
                    ->badge()
                    ->color(fn (string $state) => $state === 'Platform' ? 'danger' : 'gray')
                    ->state(fn (Role $record) => RoleName::tryFrom($record->name)?->isPlatformLevel() ? 'Platform' : 'Sekolah'),

                Tables\Columns\TextColumn::make('permissions_count')
                    ->label('Jumlah Izin')
                    ->counts('permissions'),

                Tables\Columns\TextColumn::make('users_count')
                    ->label('Jumlah Pengguna')
                    ->counts('users'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([])
            ->defaultSort('id');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'edit' => Pages\EditRole::route('/{record}/edit'),
        ];
    }
}
