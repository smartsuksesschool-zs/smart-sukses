<?php

namespace App\Filament\Resources;

use App\Enums\AuditAction;
use App\Filament\Resources\AuditLogResource\Pages;
use App\Models\AuditLog;
use App\Models\School;
use App\Models\User;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * NFR 1.4 & Arsitektur 3.4 — jejak aksi CUD, hanya untuk dibaca.
 *
 * Tidak ada aksi buat, sunting, hapus, maupun bulk di mana pun: baris audit
 * lahir dari aksi yang diaudit, bukan dari tangan manusia. Itulah yang membuat
 * jejaknya bernilai (butir 45).
 */
class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Manajemen Akses';

    protected static ?string $navigationLabel = 'Audit Log';

    protected static ?string $modelLabel = 'Audit Log';

    protected static ?string $pluralModelLabel = 'Audit Log';

    protected static ?int $navigationSort = 9;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d M Y H:i:s')
                    ->sortable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Pengguna')
                    ->placeholder('Sistem')
                    ->searchable(),

                Tables\Columns\TextColumn::make('school.name')
                    ->label('Cabang')
                    ->placeholder('Platform')
                    ->searchable(),

                Tables\Columns\TextColumn::make('action')
                    ->label('Aksi')
                    ->badge()
                    ->formatStateUsing(fn (AuditAction $state) => $state->label())
                    ->color(fn (AuditAction $state) => $state->color()),

                Tables\Columns\TextColumn::make('auditable_type')
                    ->label('Tabel')
                    ->formatStateUsing(fn (AuditLog $record) => $record->tableName())
                    ->searchable(),

                Tables\Columns\TextColumn::make('auditable_id')
                    ->label('ID Record')
                    ->alignRight(),

                Tables\Columns\TextColumn::make('ip_address')
                    ->label('IP')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('school_id')
                    ->label('Cabang')
                    ->options(fn () => School::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),

                Tables\Filters\SelectFilter::make('action')
                    ->label('Aksi')
                    ->options(AuditAction::options()),

                Tables\Filters\SelectFilter::make('user_id')
                    ->label('Pengguna')
                    ->options(fn () => User::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),

                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Dari Tanggal'),
                        Forms\Components\DatePicker::make('until')->label('Sampai Tanggal'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date))),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Jejak Aksi')
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('created_at')
                        ->label('Waktu')
                        ->dateTime('d M Y H:i:s'),

                    Infolists\Components\TextEntry::make('action')
                        ->label('Aksi')
                        ->badge()
                        ->formatStateUsing(fn (AuditAction $state) => $state->label())
                        ->color(fn (AuditAction $state) => $state->color()),

                    Infolists\Components\TextEntry::make('user.name')
                        ->label('Pengguna')
                        ->placeholder('Sistem (job/CLI)'),

                    Infolists\Components\TextEntry::make('school.name')
                        ->label('Cabang')
                        ->placeholder('Platform (lintas cabang)'),

                    Infolists\Components\TextEntry::make('auditable_type')
                        ->label('Tabel')
                        ->formatStateUsing(fn (AuditLog $record) => $record->tableName()),

                    Infolists\Components\TextEntry::make('auditable_id')
                        ->label('ID Record'),

                    Infolists\Components\TextEntry::make('ip_address')
                        ->label('Alamat IP')
                        ->placeholder('— (bukan request HTTP)'),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditLogs::route('/'),
            'view' => Pages\ViewAuditLog::route('/{record}'),
        ];
    }
}
