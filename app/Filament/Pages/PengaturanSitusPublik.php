<?php

namespace App\Filament\Pages;

use App\Enums\PermissionName;
use App\Models\SiteSetting;
use App\Support\PublicSite;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Penyuntingan isi tunggal halaman muka publik: hero, tentang, logo, alamat
 * PPDB, alamat blog, kontak, dan tautan media sosial.
 *
 * Polanya mengikuti PengaturanTampilan dan PengaturanPenilaian — halaman form,
 * bukan Resource, karena yang disunting satu himpunan nilai tunggal dan bukan
 * daftar baris. Isi yang memang berulang (unit, program, galeri, artikel) hidup
 * di SiteBlockResource.
 *
 * **Bukan halaman per cabang.** Tidak ada pemilih cabang di sini, berbeda dari
 * PengaturanTampilan, karena yang disunting situs payung Smart Sukses School
 * (butir 464).
 */
class PengaturanSitusPublik extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationGroup = 'Situs Publik';

    protected static ?string $navigationLabel = 'Pengaturan Halaman Muka';

    protected static ?string $title = 'Pengaturan Halaman Muka';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.pengaturan-situs-publik';

    /**
     * Kunci yang dikelola halaman ini.
     *
     * Daftarnya ditulis di kode, bukan disimpulkan dari isi tabel: kunci yang
     * tidak ada di sini tidak akan pernah tersimpan, sehingga tabel pengaturan
     * tidak dapat tumbuh menjadi tempat penyimpanan sembarang nilai.
     *
     * @var array<int, string>
     */
    public const KEYS = [
        'hero_heading',
        'hero_description',
        'hero_image_path',
        'about_heading',
        'about_body',
        'activity_heading',
        'activity_description',
        'article_heading',
        'article_description',
        'ppdb_heading',
        'ppdb_description',
        'ppdb_url',
        'blog_url',
        'logo_path',
        'contact_address',
        'contact_phone',
        'contact_email',
        'social_instagram',
        'social_facebook',
        'social_youtube',
    ];

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->can(PermissionName::PublicContentManage->value) ?? false;
    }

    public function mount(): void
    {
        $values = [];

        foreach (self::KEYS as $key) {
            // Bawaan ikut terisi ke form, sehingga admin menyunting teks yang
            // benar-benar sedang tampil — bukan field kosong yang menyesatkan
            // seolah halaman muka juga kosong (butir 466).
            $values[$key] = SiteSetting::get($key, PublicSite::DEFAULTS[$key] ?? null);
        }

        $this->form->fill($values);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('Merek'))
                    ->description(__('Tagline resmi tidak dapat diubah dari sini.'))
                    ->schema([
                        Forms\Components\Placeholder::make('tagline')
                            ->label(__('Tagline'))
                            ->content(PublicSite::TAGLINE),

                        $this->imageField('logo_path', __('Logo'))
                            ->helperText(__('Kosongkan untuk memakai logo bawaan Smart Sukses School × Zakat Sukses.')),
                    ]),

                Forms\Components\Section::make(__('Hero'))
                    ->schema([
                        Forms\Components\TextInput::make('hero_heading')
                            ->label(__('Judul Utama'))
                            ->maxLength(150),

                        Forms\Components\Textarea::make('hero_description')
                            ->label(__('Deskripsi Singkat'))
                            ->rows(3),

                        $this->imageField('hero_image_path', __('Foto Hero'))
                            ->helperText(__('Kosongkan bila foto sekolah belum tersedia; halaman menampilkan penanda foto.')),
                    ]),

                Forms\Components\Section::make(__('Tentang'))
                    ->schema([
                        Forms\Components\TextInput::make('about_heading')
                            ->label(__('Judul'))
                            ->maxLength(150),

                        Forms\Components\Textarea::make('about_body')
                            ->label(__('Isi'))
                            ->rows(5),
                    ]),

                Forms\Components\Section::make(__('Kegiatan'))
                    ->schema([
                        Forms\Components\TextInput::make('activity_heading')
                            ->label(__('Judul'))
                            ->maxLength(150),

                        Forms\Components\Textarea::make('activity_description')
                            ->label(__('Deskripsi'))
                            ->rows(3),
                    ]),

                Forms\Components\Section::make(__('PPDB'))
                    ->schema([
                        Forms\Components\TextInput::make('ppdb_heading')
                            ->label(__('Judul'))
                            ->maxLength(150),

                        Forms\Components\Textarea::make('ppdb_description')
                            ->label(__('Deskripsi'))
                            ->rows(3),

                        Forms\Components\TextInput::make('ppdb_url')
                            ->label(__('Alamat Pendaftaran'))
                            ->url()
                            ->maxLength(500)
                            ->helperText(__('Tempelkan alamat Google Form yang sedang dipakai. Kosongkan untuk memakai halaman PPDB aplikasi ini.')),
                    ]),

                Forms\Components\Section::make(__('Artikel'))
                    ->schema([
                        Forms\Components\TextInput::make('article_heading')
                            ->label(__('Judul'))
                            ->maxLength(150),

                        Forms\Components\Textarea::make('article_description')
                            ->label(__('Deskripsi'))
                            ->rows(2),

                        Forms\Components\TextInput::make('blog_url')
                            ->label(__('Alamat Blog'))
                            ->url()
                            ->maxLength(500)
                            ->helperText(__('Contoh: alamat blog WordPress sekolah. Kosongkan untuk menyembunyikan tautannya.')),
                    ]),

                Forms\Components\Section::make(__('Kontak'))
                    ->schema([
                        Forms\Components\Textarea::make('contact_address')
                            ->label(__('Alamat'))
                            ->rows(2),

                        Forms\Components\TextInput::make('contact_phone')
                            ->label(__('Telepon / WhatsApp'))
                            ->maxLength(50),

                        Forms\Components\TextInput::make('contact_email')
                            ->label(__('Surel'))
                            ->email()
                            ->maxLength(150),
                    ]),

                Forms\Components\Section::make(__('Media Sosial'))
                    ->schema([
                        Forms\Components\TextInput::make('social_instagram')
                            ->label('Instagram')->url()->maxLength(500),

                        Forms\Components\TextInput::make('social_facebook')
                            ->label('Facebook')->url()->maxLength(500),

                        Forms\Components\TextInput::make('social_youtube')
                            ->label('YouTube')->url()->maxLength(500),
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * Aturan unggahan yang sama persis dengan SiteBlockResource.
     *
     * Ditulis satu kali supaya kedua jalur unggah tidak dapat berbeda batas
     * MIME atau ukurannya seiring waktu — perbedaan semacam itu selalu berakhir
     * sebagai jalur longgar yang tidak disengaja.
     */
    protected function imageField(string $name, string $label): Forms\Components\FileUpload
    {
        return Forms\Components\FileUpload::make($name)
            ->label($label)
            ->image()
            ->disk(SiteSetting::MEDIA_DISK)
            ->directory(SiteSetting::MEDIA_DIRECTORY)
            ->preserveFilenames(false)
            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->maxSize(4096);
    }

    public function save(): void
    {
        // getState() memvalidasi lebih dulu: alamat yang bukan URL dan berkas
        // di luar batas MIME/ukuran tidak pernah sampai ke basis data.
        $state = $this->form->getState();

        foreach (self::KEYS as $key) {
            $value = $state[$key] ?? null;

            SiteSetting::set($key, is_string($value) ? trim($value) : $value);
        }

        Notification::make()
            ->title(__('Halaman muka diperbarui'))
            ->body(__('Perubahan berlaku pada muat ulang halaman berikutnya.'))
            ->success()
            ->send();
    }
}
