<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SchoolResource\Pages;
use App\Models\School;
use App\Support\SchoolBranding;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * PRD 1.1.2 baris "Manajemen Tenant/Cabang" — hanya SUPER_ADMIN.
 * API 4.3 — GET/POST /admin/schools, GET/PUT /admin/schools/{id},
 * PATCH /admin/schools/{id}/toggle.
 *
 * `schools` adalah satu-satunya tabel bisnis tanpa kolom `school_id`: ia
 * *adalah* tenant-nya. SchoolScope karena itu tidak berlaku di sini, dan
 * isolasinya ditegakkan dua lapis — policy (`SchoolPolicy`) dan penyaringan
 * query di `getEloquentQuery()`.
 */
class SchoolResource extends Resource
{
    protected static ?string $model = School::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $navigationGroup = 'Manajemen Akses';

    protected static ?string $navigationLabel = 'Cabang Sekolah';

    protected static ?string $modelLabel = 'Cabang Sekolah';

    protected static ?string $pluralModelLabel = 'Cabang Sekolah';

    protected static ?int $navigationSort = 0;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identitas Cabang')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nama Cabang')
                        ->required()
                        ->maxLength(150)
                        ->live(onBlur: true)
                        // Kode dan slug hanya diusulkan saat membuat cabang baru;
                        // mengubahnya otomatis saat edit akan memutus tautan
                        // publik PPDB yang sudah beredar.
                        ->afterStateUpdated(function (string $operation, ?string $state, Forms\Set $set): void {
                            if ($operation !== 'create' || blank($state)) {
                                return;
                            }

                            $set('code', Str::upper(Str::substr(Str::slug($state, ''), 0, 20)));
                            $set('slug', Str::substr(Str::slug($state), 0, 50));
                        }),

                    Forms\Components\TextInput::make('code')
                        ->label('Kode Cabang')
                        ->required()
                        ->maxLength(20)
                        // ERD 2.2 — code UQ. Dipakai `reg_number` PPDB dan URL
                        // publik /ppdb/[kode_cabang].
                        ->unique(ignoreRecord: true)
                        ->rule('alpha_dash')
                        ->helperText('Dipakai pada nomor pendaftaran PPDB. Contoh: PUSAT, MADANI.')
                        ->afterStateUpdated(fn (?string $state, Forms\Set $set) => $set('code', Str::upper((string) $state))),

                    Forms\Components\TextInput::make('slug')
                        ->label('Slug')
                        ->required()
                        ->maxLength(50)
                        ->unique(ignoreRecord: true)
                        ->rule('alpha_dash')
                        ->helperText('Pengenal ramah-URL, huruf kecil. Contoh: madani.')
                        ->afterStateUpdated(fn (?string $state, Forms\Set $set) => $set('slug', Str::lower((string) $state))),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Cabang Aktif')
                        ->default(true)
                        ->helperText('Cabang nonaktif tidak menerima pendaftaran PPDB dan tidak tampil di halaman publik.'),
                ]),

            Forms\Components\Section::make('Kontak')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('head_name')
                        ->label('Nama Kepala Sekolah')
                        ->maxLength(150),

                    Forms\Components\TextInput::make('phone')
                        ->label('Telepon')
                        ->tel()
                        ->maxLength(20),

                    Forms\Components\TextInput::make('email')
                        ->label('Email Resmi')
                        ->email()
                        ->maxLength(150),

                    Forms\Components\Textarea::make('address')
                        ->label('Alamat')
                        ->rows(3)
                        ->columnSpanFull(),
                ]),

            // AUTH-03 — konfigurasi white-label. Field yang sama tersedia bagi
            // Admin Sekolah lewat halaman Pengaturan Tampilan.
            static::brandingSection(),

            Forms\Components\Section::make('Template WhatsApp')
                ->description('Dipakai saat menyusun pesan wa.me. Dikosongkan berarti memakai template bawaan sistem.')
                ->collapsed()
                ->schema([
                    Forms\Components\Textarea::make('wa_template_ppdb')
                        ->label('Template PPDB')
                        ->rows(3),

                    Forms\Components\Textarea::make('wa_template_spp')
                        ->label('Template Tagihan')
                        ->rows(3),

                    Forms\Components\Textarea::make('wa_template_rapor')
                        ->label('Template Rapor')
                        ->rows(3),
                ]),
        ]);
    }

    /**
     * Bagian white-label, dipakai bersama oleh resource ini dan halaman
     * Pengaturan Tampilan supaya kedua jalur menyunting field yang sama dengan
     * validasi yang sama persis.
     */
    public static function brandingSection(): Forms\Components\Section
    {
        return Forms\Components\Section::make('Tampilan (White-Label)')
            ->description('Logo dan warna yang dilihat pengguna cabang ini. Perubahan langsung berlaku tanpa deployment ulang.')
            ->columns(2)
            ->schema([
                Forms\Components\FileUpload::make('logo_url')
                    ->label('Logo Cabang')
                    ->image()
                    ->disk(School::LOGO_DISK)
                    ->directory('schools/logos')
                    // Arsitektur 3.4 — "Validasi MIME + ukuran | Hanya JPG/PNG/PDF
                    // diperbolehkan". Tidak ada requirement khusus logo di seluruh
                    // blueprint, jadi aturan global itulah yang berlaku. PDF tidak
                    // ditawarkan karena bukan format gambar yang dapat dirender
                    // sebagai logo panel — pembatasan lebih ketat dari dokumen,
                    // bukan pelonggaran. WEBP hanya diizinkan SIS-03 yang khusus
                    // foto siswa, dan SVG tidak disebut dokumen mana pun. Lihat
                    // butir 42.
                    ->acceptedFileTypes(['image/jpeg', 'image/png'])
                    // Tidak ada batas ukuran logo di dokumen; 2 MB menyamakan diri
                    // dengan dua unggahan lain yang batasnya memang tertulis —
                    // foto siswa (SIS-03) dan dokumen PPDB (butir 17).
                    ->maxSize(2048)
                    ->helperText('JPG atau PNG, maksimal 2 MB.')
                    ->columnSpanFull(),

                Forms\Components\ColorPicker::make('primary_color')
                    ->label('Warna Utama')
                    ->required()
                    ->default(SchoolBranding::FALLBACK_PRIMARY)
                    // Kolomnya VARCHAR(7): hanya hex 6 digit yang muat, dan
                    // hanya itu yang dapat dibaca Color::hex() saat merender.
                    ->rule('regex:/^#[0-9A-Fa-f]{6}$/')
                    ->validationMessages(['regex' => 'Warna harus berupa hex 6 digit, contoh #1B3A6B.']),

                Forms\Components\ColorPicker::make('secondary_color')
                    ->label('Warna Aksen')
                    ->required()
                    ->default(SchoolBranding::FALLBACK_SECONDARY)
                    ->rule('regex:/^#[0-9A-Fa-f]{6}$/')
                    ->validationMessages(['regex' => 'Warna harus berupa hex 6 digit, contoh #E07020.']),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('logo_url')
                    ->label('Logo')
                    ->disk(School::LOGO_DISK)
                    ->height(28),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Cabang')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('code')
                    ->label('Kode')
                    ->badge()
                    ->searchable(),

                Tables\Columns\ColorColumn::make('primary_color')
                    ->label('Warna Utama'),

                Tables\Columns\TextColumn::make('users_count')
                    ->label('Pengguna')
                    ->counts('users')
                    ->alignRight(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Status Aktif'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),

                // API 4.3 — PATCH /admin/schools/{id}/toggle. Cabang tidak
                // pernah dihapus; seluruh data akademik menggantung padanya.
                Tables\Actions\Action::make('toggleActive')
                    ->label(fn (School $record) => $record->is_active ? 'Nonaktifkan' : 'Aktifkan')
                    ->icon(fn (School $record) => $record->is_active ? 'heroicon-o-lock-closed' : 'heroicon-o-lock-open')
                    ->color(fn (School $record) => $record->is_active ? 'danger' : 'success')
                    ->requiresConfirmation()
                    ->modalDescription(fn (School $record) => $record->is_active
                        ? 'Cabang nonaktif tidak menerima pendaftaran PPDB dan tidak tampil di halaman publik. Data yang sudah ada tidak dihapus.'
                        : 'Cabang akan kembali menerima pendaftaran PPDB dan tampil di halaman publik.')
                    ->visible(fn (School $record) => Auth::user()?->can('toggleActive', $record))
                    ->action(fn (School $record) => $record->forceFill(['is_active' => ! $record->is_active])->save()),
            ])
            ->bulkActions([])
            ->defaultSort('name');
    }

    /**
     * Lapis kedua isolasi. `schools` tidak punya `school_id` sehingga tidak
     * tersentuh SchoolScope; tanpa penyaringan ini, peran School Level yang
     * suatu saat diberi `tenant.view` akan melihat seluruh cabang.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        $user = Auth::user();

        if ($user?->isSuperAdmin()) {
            return $query;
        }

        return $query->whereKey($user?->school_id ?? 0);
    }

    /**
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'code'];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSchools::route('/'),
            'create' => Pages\CreateSchool::route('/create'),
            'view' => Pages\ViewSchool::route('/{record}'),
            'edit' => Pages\EditSchool::route('/{record}/edit'),
        ];
    }
}
