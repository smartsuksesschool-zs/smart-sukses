<?php

namespace App\Filament\Resources;

use App\Enums\NotificationTargetType;
use App\Enums\NotificationType;
use App\Filament\Resources\NotificationResource\Pages;
use App\Models\Notification;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Scopes\SchoolScope;
use App\Models\User;
use App\Services\Notification\AnnouncementPublisher;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * NOTIF-01 — pembuatan pengumuman manual dari panel.
 *
 * Seluruh penulisannya lewat AnnouncementPublisher, service yang sama dengan
 * endpoint API, sehingga pemeriksaan cabang, pengirim, dan target tidak dapat
 * dilewati salah satu jalur.
 */
class NotificationResource extends Resource
{
    protected static ?string $model = Notification::class;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationGroup = 'Komunikasi';

    protected static ?string $navigationLabel = 'Pengumuman';

    protected static ?string $modelLabel = 'Pengumuman';

    protected static ?string $pluralModelLabel = 'Pengumuman';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('Isi Pengumuman'))->schema([
                // Super Admin tidak punya cabang, jadi ia wajib memilihnya;
                // peran cabang tidak pernah melihat pilihan ini (butir 202).
                Forms\Components\Select::make('school_id')
                    ->label(__('Cabang'))
                    ->options(fn (): array => School::query()
                        ->withoutGlobalScope(SchoolScope::class)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->required()
                    ->live()
                    ->visible(fn (): bool => Auth::user()?->isSuperAdmin() ?? false),

                Forms\Components\TextInput::make('title')
                    ->label(__('Judul'))
                    ->required()
                    ->maxLength(200),

                Forms\Components\Textarea::make('message')
                    ->label(__('Isi Pesan'))
                    ->required()
                    ->rows(6),

                Forms\Components\Select::make('type')
                    ->label(__('Kategori'))
                    // SYSTEM tidak ditawarkan: itu milik notifikasi otomatis
                    // (NOTIF-03), dan memilihnya manual akan membuat pengumuman
                    // manusia menyamar sebagai notifikasi sistem (butir 191).
                    ->options(NotificationType::manualOptions())
                    ->default(NotificationType::Announcement->value)
                    ->required(),
            ])->columns(1),

            Forms\Components\Section::make(__('Penerima'))->schema([
                Forms\Components\Select::make('target_type')
                    ->label(__('Target'))
                    ->options(NotificationTargetType::options())
                    ->default(NotificationTargetType::All->value)
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Forms\Set $set) => $set('target_id', null)),

                Forms\Components\Select::make('target_id')
                    ->label(__('Kelas'))
                    ->options(fn (Forms\Get $get): array => static::classOptions($get('school_id')))
                    ->searchable()
                    ->required()
                    ->visible(fn (Forms\Get $get): bool => $get('target_type') === NotificationTargetType::SchoolClass->value),

                Forms\Components\Select::make('target_id')
                    ->label(__('Pengguna'))
                    ->options(fn (Forms\Get $get): array => static::userOptions($get('school_id')))
                    ->searchable()
                    ->required()
                    ->visible(fn (Forms\Get $get): bool => $get('target_type') === NotificationTargetType::Individual->value),
            ])->columns(1),
        ]);
    }

    /**
     * @return array<int, string>
     */
    protected static function classOptions(mixed $schoolId): array
    {
        $schoolId = static::resolveSchoolId($schoolId);

        if ($schoolId === null) {
            return [];
        }

        return SchoolClass::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $schoolId)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected static function userOptions(mixed $schoolId): array
    {
        $schoolId = static::resolveSchoolId($schoolId);

        if ($schoolId === null) {
            return [];
        }

        return User::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $schoolId)
            ->active()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    protected static function resolveSchoolId(mixed $formValue): ?int
    {
        $user = Auth::user();

        if ($user === null) {
            return null;
        }

        // Nilai dari form hanya dipercaya bila pelakunya Super Admin; peran
        // cabang selalu memakai cabangnya sendiri.
        if (! $user->isSuperAdmin()) {
            return $user->school_id === null ? null : (int) $user->school_id;
        }

        return blank($formValue) || ! is_numeric($formValue) ? null : (int) $formValue;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label(__('Judul'))
                    ->searchable()
                    ->limit(40),

                Tables\Columns\TextColumn::make('type')
                    ->label(__('Kategori'))
                    ->badge()
                    ->formatStateUsing(fn (NotificationType $state): string => $state->label()),

                Tables\Columns\TextColumn::make('target_type')
                    ->label(__('Target'))
                    ->formatStateUsing(fn (NotificationTargetType $state): string => $state->label()),

                Tables\Columns\IconColumn::make('is_draft')
                    ->label(__('Draf'))
                    ->boolean(),

                Tables\Columns\TextColumn::make('sent_at')
                    ->label(__('Dikirim'))
                    ->dateTime('d M Y H:i')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('sender.name')
                    ->label(__('Pembuat'))
                    ->placeholder(__('Sistem')),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label(__('Kategori'))
                    ->options(NotificationType::manualOptions()),

                Tables\Filters\TernaryFilter::make('is_draft')
                    ->label(__('Status'))
                    ->placeholder(__('Semua'))
                    ->trueLabel('Draf saja')
                    ->falseLabel('Terkirim saja'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make()
                    // Hanya draf yang dapat diubah (butir 195).
                    ->visible(fn (Notification $record): bool => Auth::user()?->can('update', $record) ?? false),
                static::sendAction(),
                static::waLinksAction(),
            ])
            ->bulkActions([])
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByDesc('created_at')
                ->orderByDesc('id'));
    }

    /**
     * Menerbitkan draf yang sudah tersimpan.
     */
    protected static function sendAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('send')
            ->label(__('Kirim'))
            ->icon('heroicon-m-paper-airplane')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('Kirim pengumuman'))
            ->modalDescription(__('Setelah terkirim, isi dan target pengumuman tidak dapat diubah lagi.'))
            ->visible(fn (Notification $record): bool => ! $record->isSent()
                && (Auth::user()?->can('update', $record) ?? false))
            ->action(function (Notification $record): void {
                try {
                    app(AnnouncementPublisher::class)->send($record, Auth::user());
                } catch (AuthorizationException|ValidationException $e) {
                    FilamentNotification::make()
                        ->title(__('Pengumuman tidak dapat dikirim'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                FilamentNotification::make()->title('Pengumuman terkirim')->success()->send();
            });
    }

    /**
     * NOTIF-02 — "Daftar Link WhatsApp" untuk pengumuman yang sudah terkirim.
     *
     * Sengaja hanya di sisi pengelolaan. Menaruhnya di Notifikasi Saya akan
     * berarti penerima melihat nomor penerima lain, dan itu justru kebalikan
     * dari pemisahan yang dijaga butir 218 (butir 231).
     */
    protected static function waLinksAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('waLinks')
            ->label(__('Link WhatsApp'))
            ->icon('heroicon-o-chat-bubble-left-right')
            ->color('success')
            // Draf belum punya tautan siap kirim (butir 224).
            ->visible(fn (Notification $record): bool => $record->isSent()
                && (Auth::user()?->can('waLinks', $record) ?? false))
            ->url(fn (Notification $record): string => static::getUrl('wa-links', ['record' => $record]));
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make(__('Pengumuman'))->schema([
                Infolists\Components\TextEntry::make('title')->label(__('Judul')),
                Infolists\Components\TextEntry::make('message')->label(__('Isi Pesan'))->columnSpanFull(),
                Infolists\Components\TextEntry::make('type')
                    ->label(__('Kategori'))
                    ->formatStateUsing(fn (NotificationType $state): string => $state->label()),
                Infolists\Components\TextEntry::make('target_type')
                    ->label(__('Target'))
                    ->formatStateUsing(fn (NotificationTargetType $state): string => $state->label()),
                Infolists\Components\TextEntry::make('sender.name')->label(__('Pembuat'))->placeholder('Sistem'),
                Infolists\Components\TextEntry::make('sent_at')
                    ->label(__('Dikirim'))
                    ->dateTime('d M Y H:i')
                    ->placeholder(__('Belum dikirim')),
            ])->columns(2),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNotifications::route('/'),
            'create' => Pages\CreateNotification::route('/create'),
            'view' => Pages\ViewNotification::route('/{record}'),
            'edit' => Pages\EditNotification::route('/{record}/edit'),
            'wa-links' => Pages\NotificationWaLinks::route('/{record}/wa-links'),
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
