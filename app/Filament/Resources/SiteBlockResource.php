<?php

namespace App\Filament\Resources;

use App\Enums\SiteBlockType;
use App\Filament\Resources\SiteBlockResource\Pages;
use App\Models\SiteBlock;
use App\Models\SiteSetting;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Penyuntingan isi berulang halaman muka publik: unit pendidikan, program,
 * galeri kegiatan, dan pratinjau artikel.
 *
 * Satu resource untuk keempatnya, bukan empat resource yang identik. Yang
 * membedakan hanya kolom `type`, dan itu cukup ditangani satu Select beserta
 * filter tabel (butir 465).
 *
 * Permintaan pemilik yang dijawab bagian ini: "isi dan foto halaman muka harus
 * bisa diubah nanti" — tanpa menyunting kode, dan tanpa membangun WordPress
 * kedua.
 */
class SiteBlockResource extends Resource
{
    protected static ?string $model = SiteBlock::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-group';

    protected static ?string $navigationGroup = 'Situs Publik';

    protected static ?string $navigationLabel = 'Isi Halaman Muka';

    protected static ?string $modelLabel = 'Blok Isi';

    protected static ?string $pluralModelLabel = 'Blok Isi';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('type')
                ->label(__('Jenis'))
                ->options(SiteBlockType::options())
                ->required()
                ->live()
                ->native(false)
                ->helperText(__('Menentukan bagian halaman muka tempat blok ini tampil.')),

            Forms\Components\TextInput::make('title')
                ->label(__('Judul'))
                ->required()
                ->maxLength(150),

            Forms\Components\TextInput::make('subtitle')
                ->label(__('Keterangan Singkat'))
                ->maxLength(150)
                ->helperText(__('Contoh: "Jenjang SMA" untuk Smart Building.')),

            Forms\Components\Textarea::make('body')
                ->label(__('Isi'))
                ->rows(4)
                ->columnSpanFull(),

            Forms\Components\FileUpload::make('image_path')
                ->label(__('Gambar'))
                ->image()
                ->disk(SiteSetting::MEDIA_DISK)
                ->directory(SiteSetting::MEDIA_DIRECTORY)
                // Nama berkas **tidak** dipertahankan: Filament menamainya
                // ulang dengan ULID, dan itu memang yang diinginkan di sini.
                // Nama asli unggahan berasal dari luar dan ikut menentukan path
                // di disk; menamainya ulang menutup sekaligus dua hal — path
                // traversal lewat nama berkas, dan berkas baru yang diam-diam
                // menimpa berkas lama bernama sama.
                //
                // Perilakunya bawaan, jadi yang dijaga test adalah bahwa tidak
                // ada yang memanggil `preserveFilenames()` di kemudian hari
                // (butir 474).
                ->preserveFilenames(false)
                // WEBP diizinkan di sini, berbeda dari logo cabang yang hanya
                // JPG/PNG (butir 42). Alasannya bukan pelonggaran asal: ini
                // halaman publik yang memuat banyak foto sekaligus, WEBP
                // memangkas ukurannya secara berarti, dan berkas merek yang
                // diserahkan pemilik sendiri berformat WEBP. SVG tetap tidak
                // diizinkan — SVG dapat memuat skrip (butir 474).
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                // Lebih longgar dari 2 MB milik logo karena ini foto kegiatan,
                // bukan lambang; masih cukup ketat untuk menahan unggahan
                // kamera mentah.
                ->maxSize(4096)
                ->helperText(__('JPG, PNG, atau WEBP, maksimal 4 MB. Kosongkan bila foto belum tersedia.'))
                ->columnSpanFull(),

            Forms\Components\TextInput::make('link_url')
                ->label(__('Tautan'))
                ->url()
                ->maxLength(500)
                ->helperText(__('Untuk artikel: alamat tulisan di blog.')),

            Forms\Components\TextInput::make('position')
                ->label(__('Urutan'))
                ->numeric()
                ->default(0)
                ->minValue(0)
                ->maxValue(65535)
                ->helperText(__('Angka kecil tampil lebih dulu.')),

            Forms\Components\Toggle::make('is_published')
                ->label(__('Tampilkan di Halaman Muka'))
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // Urutan tabel dibuat sama dengan urutan tampil di halaman muka,
            // supaya admin menyusun urutan sambil melihat hasilnya.
            ->defaultSort('position')
            ->columns([
                Tables\Columns\ImageColumn::make('image_path')
                    ->label(__('Gambar'))
                    ->disk(SiteSetting::MEDIA_DISK)
                    ->square()
                    ->defaultImageUrl(null),

                Tables\Columns\TextColumn::make('type')
                    ->label(__('Jenis'))
                    ->badge()
                    ->formatStateUsing(fn (SiteBlockType $state): string => $state->label())
                    ->sortable(),

                Tables\Columns\TextColumn::make('title')
                    ->label(__('Judul'))
                    ->searchable()
                    ->limit(40),

                Tables\Columns\TextColumn::make('position')
                    ->label(__('Urutan'))
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_published')
                    ->label(__('Tampil'))
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label(__('Jenis'))
                    ->options(SiteBlockType::options()),

                Tables\Filters\TernaryFilter::make('is_published')
                    ->label(__('Tampil di Halaman Muka')),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    /**
     * Satu halaman dengan modal, mengikuti SubjectResource — bukan tiga halaman
     * terpisah. Menyunting judul kartu tidak sepadan dengan berpindah halaman.
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageSiteBlocks::route('/'),
        ];
    }
}
