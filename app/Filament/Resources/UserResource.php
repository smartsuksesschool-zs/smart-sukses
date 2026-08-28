<?php

namespace App\Filament\Resources;

use App\Enums\RoleName;
use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * PORTAL-04 / API 4.4 — Manajemen akun pengguna: buat, edit, nonaktifkan,
 * reset password. Daftar user otomatis di-scope ke school_id lewat
 * global scope tenant pada model User.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Manajemen Akses';

    protected static ?string $navigationLabel = 'Pengguna';

    protected static ?string $modelLabel = 'Pengguna';

    protected static ?string $pluralModelLabel = 'Pengguna';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('Identitas'))
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label(__('Nama Lengkap'))
                        ->required()
                        ->maxLength(150),

                    Forms\Components\TextInput::make('email')
                        ->label(__('Email'))
                        ->email()
                        ->required()
                        ->maxLength(150)
                        ->unique(ignoreRecord: true),

                    Forms\Components\TextInput::make('phone')
                        ->label(__('No. HP'))
                        ->tel()
                        ->maxLength(20)
                        ->helperText(__('Dipakai untuk generate link wa.me.')),

                    Forms\Components\FileUpload::make('avatar_url')
                        ->label(__('Foto Profil'))
                        ->image()
                        ->avatar()
                        ->disk('public')
                        ->directory('avatars')
                        ->maxSize(2048),
                ]),

            Forms\Components\Section::make(__('Akses'))
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('school_id')
                        ->label(__('Cabang Sekolah'))
                        ->relationship(
                            name: 'school',
                            titleAttribute: 'name',
                            modifyQueryUsing: fn ($query) => $query->where('is_active', true),
                        )
                        ->searchable()
                        ->preload()
                        // Hanya Super Admin yang boleh memindahkan user antar cabang.
                        ->visible(fn () => Auth::user()?->isSuperAdmin())
                        ->default(fn () => Auth::user()?->school_id)
                        ->helperText(__('Kosongkan hanya untuk Super Administrator (akses lintas cabang).')),

                    Forms\Components\Select::make('roles')
                        ->label(__('Peran'))
                        ->relationship(
                            name: 'roles',
                            titleAttribute: 'name',
                            modifyQueryUsing: fn ($query) => $query->whereIn('name', static::assignableRoleNames()),
                        )
                        // PRD 1.1.1: setiap pengguna memiliki tepat satu peran utama.
                        ->multiple()
                        ->maxItems(1)
                        ->required()
                        ->preload()
                        ->getOptionLabelFromRecordUsing(
                            fn ($record) => RoleName::tryFrom($record->name)?->label() ?? $record->name,
                        ),

                    Forms\Components\Select::make('locale')
                        ->label(__('Bahasa'))
                        ->options(['id' => 'Bahasa Indonesia', 'en' => 'English'])
                        ->default('id')
                        ->required(),

                    Forms\Components\Toggle::make('is_active')
                        ->label(__('Aktif'))
                        ->default(true)
                        ->helperText(__('Pengguna nonaktif tidak dapat login.')),
                ]),

            Forms\Components\Section::make(__('Kata Sandi'))
                ->schema([
                    Forms\Components\TextInput::make('password')
                        ->label(__('Password'))
                        ->password()
                        ->revealable()
                        ->rule(Password::defaults())
                        ->required(fn (string $operation) => $operation === 'create')
                        ->dehydrated(fn (?string $state) => filled($state))
                        ->helperText(__('Kosongkan saat mengedit bila password tidak diubah.')),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('Nama'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('email')
                    ->label(__('Email'))
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('roles.name')
                    ->label(__('Peran'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => RoleName::tryFrom($state)?->label() ?? $state),

                Tables\Columns\TextColumn::make('school.name')
                    ->label(__('Cabang'))
                    ->placeholder(__('Semua cabang'))
                    ->visible(fn () => Auth::user()?->isSuperAdmin())
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label(__('Aktif'))
                    ->boolean(),

                Tables\Columns\TextColumn::make('last_login_at')
                    ->label(__('Login Terakhir'))
                    ->dateTime('d M Y H:i')
                    ->placeholder(__('Belum pernah'))
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('roles')
                    ->label(__('Peran'))
                    ->relationship('roles', 'name')
                    ->getOptionLabelFromRecordUsing(
                        fn ($record) => RoleName::tryFrom($record->name)?->label() ?? $record->name,
                    ),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label(__('Status Aktif')),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

                // API 4.4 — POST /users/{id}/reset-password (password sementara).
                Tables\Actions\Action::make('resetPassword')
                    ->label(__('Reset Password'))
                    ->icon('heroicon-o-key')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription(__('Password sementara akan dibuat dan ditampilkan sekali saja.'))
                    ->visible(fn (User $record) => Auth::user()?->can('resetPassword', $record))
                    ->action(function (User $record) {
                        $temporary = Str::password(12);

                        $record->forceFill([
                            'password' => Hash::make($temporary),
                            'must_change_password' => true,
                        ])->save();

                        Notification::make()
                            ->title(__('Password sementara dibuat'))
                            ->body("Password baru untuk {$record->email}: {$temporary}")
                            ->persistent()
                            ->success()
                            ->send();
                    }),

                // API 4.4 — DELETE /users/{id} adalah soft deactivate, bukan hard delete.
                Tables\Actions\Action::make('toggleActive')
                    ->label(fn (User $record) => $record->is_active ? 'Nonaktifkan' : 'Aktifkan')
                    ->icon(fn (User $record) => $record->is_active ? 'heroicon-o-lock-closed' : 'heroicon-o-lock-open')
                    ->color(fn (User $record) => $record->is_active ? 'danger' : 'success')
                    ->requiresConfirmation()
                    ->visible(fn (User $record) => Auth::user()?->can('delete', $record))
                    ->action(fn (User $record) => $record->forceFill(['is_active' => ! $record->is_active])->save()),
            ])
            ->bulkActions([])
            ->defaultSort('name');
    }

    /**
     * Peran yang boleh diberikan oleh pengguna yang sedang login.
     * SUPER_ADMIN hanya dapat diberikan oleh Super Admin.
     *
     * @return array<int, string>
     */
    protected static function assignableRoleNames(): array
    {
        $names = RoleName::values();

        if (Auth::user()?->isSuperAdmin()) {
            return $names;
        }

        return array_values(array_diff($names, [RoleName::SuperAdmin->value]));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
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
