<?php

namespace App\Filament\Pages;

use App\Enums\PermissionName;
use App\Filament\Resources\SchoolResource;
use App\Models\School;
use App\Support\SchoolBranding;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * AUTH-03 — "Perubahan white-label oleh Admin Sekolah langsung berlaku tanpa
 * deployment ulang", dan PRD 1.1.2 baris "White-label Settings" yang memberi
 * SCHOOL_ADMIN akses penuh.
 *
 * Admin Sekolah tidak boleh membuka SchoolResource (itu "Manajemen Tenant",
 * baris matriks yang berbeda dan hanya untuk Super Admin), sehingga penyuntingan
 * tampilan cabangnya sendiri diberikan lewat halaman ini. Field-nya diambil dari
 * `SchoolResource::brandingSection()` supaya kedua jalur menyunting kolom yang
 * sama dengan validasi yang sama.
 *
 * Polanya mengikuti PengaturanPenilaian: Super Admin tidak terikat cabang,
 * jadi cabangnya dipilih di form.
 */
class PengaturanTampilan extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-swatch';

    protected static ?string $navigationGroup = 'Manajemen Akses';

    protected static ?string $navigationLabel = 'Pengaturan Tampilan';

    protected static ?string $title = 'Pengaturan Tampilan';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.pengaturan-tampilan';

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->can(PermissionName::WhiteLabelManage->value) ?? false;
    }

    public function mount(): void
    {
        $this->form->fill($this->brandingOf(static::resolveSchoolId()));
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('school_id')
                    ->label('Cabang Sekolah')
                    ->options(fn () => School::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live()
                    // Tampilan cabang lain tidak boleh tertinggal di form.
                    ->afterStateUpdated(function (mixed $state, Forms\Set $set): void {
                        foreach ($this->brandingOf(static::resolveSchoolId($state)) as $field => $value) {
                            $set($field, $value);
                        }
                    })
                    ->visible(fn () => Auth::user()?->isSuperAdmin())
                    ->helperText('Tampilan yang disimpan hanya berlaku untuk cabang ini.'),

                SchoolResource::brandingSection(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        // getState() menjalankan validasi lebih dulu, jadi warna yang bukan hex
        // dan berkas di luar batas MIME/ukuran tidak pernah sampai ke sini.
        $state = $this->form->getState();

        $school = static::schoolById(static::resolveSchoolId($state['school_id'] ?? null));

        if ($school === null) {
            Notification::make()
                ->title('Cabang belum ditentukan')
                ->body('Pilih cabang sekolah terlebih dahulu sebelum menyimpan tampilan.')
                ->danger()
                ->send();

            return;
        }

        // Pagar terakhir: Admin Sekolah tidak pernah melihat pemilih cabang,
        // tetapi state Livewire tetap dapat dikirim apa adanya.
        if (Auth::user()?->cannot('configureBranding', $school)) {
            Notification::make()
                ->title('Tidak diizinkan')
                ->body('Anda hanya dapat menyetel tampilan cabang Anda sendiri.')
                ->danger()
                ->send();

            return;
        }

        $school->update([
            'logo_url' => $state['logo_url'] ?? null,
            'primary_color' => $state['primary_color'],
            'secondary_color' => $state['secondary_color'],
        ]);

        Notification::make()
            ->title('Tampilan cabang disimpan')
            ->body('Perubahan berlaku pada muat ulang halaman berikutnya.')
            ->success()
            ->send();
    }

    /**
     * Cabang yang tampilannya sedang disetel.
     *
     * Nilai form hanya dipercaya dari Super Admin. Bagi peran lain field
     * "Cabang Sekolah" tidak pernah dirender, sehingga apa pun yang muncul di
     * state Livewire adalah selundupan dan diabaikan.
     */
    public static function resolveSchoolId(mixed $formValue = null): ?int
    {
        $user = Auth::user();

        if ($user?->isSuperAdmin() && filled($formValue)) {
            return (int) $formValue;
        }

        return $user?->school_id;
    }

    protected static function schoolById(?int $schoolId): ?School
    {
        return $schoolId === null ? null : School::query()->find($schoolId);
    }

    /**
     * Nilai form untuk satu cabang, lengkap dengan fallback platform bila
     * cabangnya belum pernah menyetel apa pun.
     *
     * @return array<string, mixed>
     */
    protected function brandingOf(?int $schoolId): array
    {
        $school = static::schoolById($schoolId);
        $branding = app(SchoolBranding::class);

        return [
            'school_id' => $schoolId,
            'logo_url' => $school?->logo_url,
            'primary_color' => $branding->primaryColor($school),
            'secondary_color' => $branding->secondaryColor($school),
        ];
    }

    /**
     * Cabang yang sedang ditampilkan pratinjaunya.
     */
    public function previewedSchool(): ?School
    {
        return static::schoolById(static::resolveSchoolId($this->data['school_id'] ?? null));
    }
}
