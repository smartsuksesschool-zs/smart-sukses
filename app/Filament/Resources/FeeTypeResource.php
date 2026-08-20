<?php

namespace App\Filament\Resources;

use App\Enums\FeeFrequency;
use App\Filament\Resources\FeeTypeResource\Pages;
use App\Models\AcademicYear;
use App\Models\FeeType;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * SPP-01 / API 4.9 — /fee-types. "Jenis tagihan baru (SPP, Uang Gedung, dll.)
 * dengan jumlah dan frekuensi tertentu."
 *
 * SPP-01 poin 2 — "dapat dinonaktifkan tanpa menghapus histori" — dipenuhi
 * lewat aksi Aktifkan/Nonaktifkan; tidak ada DeleteAction maupun bulk delete,
 * dan FeeTypePolicy::delete() menolak secara mutlak.
 */
class FeeTypeResource extends Resource
{
    protected static ?string $model = FeeType::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Keuangan';

    protected static ?string $navigationLabel = 'Jenis Tagihan';

    protected static ?string $modelLabel = 'Jenis Tagihan';

    protected static ?string $pluralModelLabel = 'Jenis Tagihan';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form->schema([
            // Super Admin tidak memiliki school_id (SchoolScope::currentSchoolId()
            // sengaja NULL untuk mereka), sehingga cabang harus dipilih di sini.
            // Pola ini mengikuti GradeConfigResource dan UserResource: field
            // cabang hanya dirender untuk Super Admin, sedangkan peran School
            // Level terikat cabang akunnya sendiri.
            Forms\Components\Select::make('school_id')
                ->label('Cabang Sekolah')
                ->relationship(
                    name: 'school',
                    titleAttribute: 'name',
                    modifyQueryUsing: fn ($query) => $query->where('is_active', true),
                )
                ->searchable()
                ->preload()
                ->required()
                ->live()
                // Tahun ajaran milik cabang lama tidak berlaku lagi.
                ->afterStateUpdated(fn (Forms\Set $set) => $set('academic_year_id', null))
                ->visible(fn () => Auth::user()?->isSuperAdmin())
                // Memindahkan jenis tagihan antar cabang akan memutusnya dari
                // tagihan siswa yang sudah terbit memakainya.
                ->disabledOn('edit')
                ->columnSpanFull()
                ->helperText('Jenis tagihan berlaku hanya untuk cabang ini.'),

            Forms\Components\TextInput::make('name')
                ->label('Nama Tagihan')
                ->required()
                ->maxLength(100)
                ->placeholder('SPP'),

            Forms\Components\TextInput::make('amount')
                ->label('Nominal')
                ->prefix('Rp')
                ->numeric()
                ->required()
                // ERD: DECIMAL(12,2). Nominal nol atau negatif bukan tagihan.
                ->rule('gt:0')
                ->step(0.01)
                ->maxValue(9999999999.99)
                ->validationMessages([
                    'gt' => 'Nominal tagihan harus lebih besar dari 0.',
                ]),

            Forms\Components\Select::make('frequency')
                ->label('Frekuensi')
                ->options(FeeFrequency::options())
                ->default(FeeFrequency::Monthly->value)
                ->required()
                // Opsi sudah terbatas, tetapi payload Livewire bisa dikirim apa
                // adanya — pagar terakhirnya di lapisan validasi.
                ->rule(Rule::enum(FeeFrequency::class)),

            Forms\Components\Select::make('academic_year_id')
                ->label('Tahun Ajaran')
                ->options(fn (Forms\Get $get) => static::academicYearOptions(
                    static::resolveSchoolId($get('school_id')),
                ))
                ->searchable()
                ->placeholder('Tidak terikat tahun ajaran')
                // ERD: "NULL untuk tagihan berulang".
                ->helperText('Kosongkan untuk tagihan berulang yang tidak terikat satu tahun ajaran.')
                ->exists(
                    table: 'academic_years',
                    column: 'id',
                    modifyRuleUsing: fn (Exists $rule, Forms\Get $get) => $rule
                        ->where('school_id', static::resolveSchoolId($get('school_id'))),
                ),

            Forms\Components\Toggle::make('is_active')
                ->label('Aktif')
                ->default(true)
                ->helperText('Jenis tagihan tidak pernah dihapus; nonaktifkan bila tidak dipakai lagi.'),

            Forms\Components\Textarea::make('description')
                ->label('Keterangan')
                ->rows(3)
                ->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Tagihan')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Nominal')
                    ->money('IDR')
                    ->sortable(),

                Tables\Columns\TextColumn::make('frequency')
                    ->label('Frekuensi')
                    ->badge()
                    ->formatStateUsing(fn (FeeFrequency $state) => $state->label())
                    ->color(fn (FeeFrequency $state) => $state->color()),

                Tables\Columns\TextColumn::make('academicYear.name')
                    ->label('Tahun Ajaran')
                    ->placeholder('Berulang')
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),

                Tables\Columns\TextColumn::make('school.name')
                    ->label('Cabang')
                    ->visible(fn () => Auth::user()?->isSuperAdmin())
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Status Aktif'),

                Tables\Filters\SelectFilter::make('frequency')
                    ->label('Frekuensi')
                    ->options(FeeFrequency::options()),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                static::toggleActiveAction(),
            ])
            // SPP-01 poin 2 — tidak ada aksi hapus, termasuk secara massal.
            ->bulkActions([])
            ->defaultSort('name');
    }

    /**
     * SPP-01 poin 2 — penonaktifan menggantikan penghapusan.
     *
     * Ditulis lewat `save()` pada model, bukan mass update, supaya event
     * `updated` tetap terpicu dan jejak auditnya tercatat (butir 46).
     */
    public static function toggleActiveAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('toggleActive')
            ->label(fn (FeeType $record) => $record->is_active ? 'Nonaktifkan' : 'Aktifkan')
            ->icon(fn (FeeType $record) => $record->is_active ? 'heroicon-o-x-circle' : 'heroicon-o-check-circle')
            ->color(fn (FeeType $record) => $record->is_active ? 'danger' : 'success')
            ->authorize('update')
            ->requiresConfirmation()
            ->modalDescription(fn (FeeType $record) => $record->is_active
                ? 'Jenis tagihan tidak akan tersedia untuk tagihan baru. Histori tagihan yang sudah terbit tetap utuh.'
                : 'Jenis tagihan kembali tersedia untuk tagihan baru.')
            ->action(function (FeeType $record): void {
                $record->forceFill(['is_active' => ! $record->is_active])->save();

                Notification::make()
                    ->title($record->is_active
                        ? "Jenis tagihan {$record->name} diaktifkan"
                        : "Jenis tagihan {$record->name} dinonaktifkan")
                    ->success()
                    ->send();
            });
    }

    /**
     * Cabang tempat jenis tagihan ini akan dibuat.
     *
     * Nilai form hanya dipercaya dari Super Admin — merekalah satu-satunya
     * peran yang melihat field "Cabang Sekolah", dan justru merekalah yang
     * `school_id`-nya NULL. Bagi peran School Level field itu tidak pernah
     * dirender, sehingga apa pun yang muncul di state Livewire adalah
     * selundupan dan diabaikan sepenuhnya.
     */
    public static function resolveSchoolId(mixed $formValue = null): ?int
    {
        $user = Auth::user();

        if ($user?->isSuperAdmin() && filled($formValue)) {
            return (int) $formValue;
        }

        return $user?->school_id;
    }

    /**
     * @return array<int, string>
     */
    protected static function academicYearOptions(?int $schoolId): array
    {
        // Sengaja kosong selama cabang belum dipilih: global scope tidak
        // membatasi apa pun bagi Super Admin, sehingga tanpa penyaringan ini
        // daftarnya berisi tahun ajaran milik seluruh cabang.
        if ($schoolId === null) {
            return [];
        }

        return AcademicYear::query()
            ->forSchool($schoolId)
            ->orderByDesc('start_date')
            ->pluck('name', 'id')
            ->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFeeTypes::route('/'),
            'create' => Pages\CreateFeeType::route('/create'),
            'edit' => Pages\EditFeeType::route('/{record}/edit'),
        ];
    }
}
