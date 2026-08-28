<?php

namespace App\Filament\Pages;

use App\Enums\RoleName;
use App\Models\School;
use App\Support\PpdbWaTemplate;
use App\Support\StudentWaTemplate;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * NOTIF-03 poin 2 — "Template teks notifikasi dapat diedit oleh Admin Sekolah."
 *
 * Polanya mengikuti PengaturanTampilan dan PengaturanPenilaian: Admin Sekolah
 * tidak boleh membuka SchoolResource — itu "Manajemen Tenant", baris matriks
 * yang berbeda dan hanya untuk Super Admin — sehingga penyuntingan pengaturan
 * cabangnya sendiri diberikan lewat halaman tersendiri, dan Super Admin yang
 * tidak terikat cabang memilih cabangnya di form (butir 249).
 *
 * Halaman ini sengaja terpisah dari Pengaturan Tampilan. Template WhatsApp
 * bukan pengaturan white-label, dan menyatukannya berarti satu kewenangan tidak
 * lagi dapat berubah tanpa ikut mengubah yang lain.
 */
class PengaturanNotifikasi extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-bottom-center-text';

    protected static ?string $navigationGroup = 'Komunikasi';

    protected static ?string $navigationLabel = 'Template WhatsApp';

    protected static ?string $title = 'Template Notifikasi WhatsApp';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'template-whatsapp';

    protected static string $view = 'filament.pages.pengaturan-notifikasi';

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public static function canAccess(): bool
    {
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        return $user->isSuperAdmin() || $user->hasRole(RoleName::SchoolAdmin->value);
    }

    public function mount(): void
    {
        $this->form->fill($this->templatesOf(static::resolveSchoolId()));
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('school_id')
                    ->label(__('Cabang Sekolah'))
                    ->options(fn (): array => School::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live()
                    // Template cabang lain tidak boleh tertinggal di form.
                    ->afterStateUpdated(function (mixed $state, Forms\Set $set): void {
                        foreach ($this->templatesOf(static::resolveSchoolId($state)) as $field => $value) {
                            $set($field, $value);
                        }
                    })
                    ->visible(fn (): bool => Auth::user()?->isSuperAdmin() ?? false)
                    ->helperText(__('Template yang disimpan hanya berlaku untuk cabang ini.')),

                Forms\Components\Section::make(__('Template WhatsApp'))
                    ->description('Dipakai saat menyusun pesan wa.me. Dikosongkan berarti memakai teks bawaan sistem. '
                        .'Pengirimannya tetap manual — sistem hanya menyiapkan tautannya.')
                    ->schema([
                        Forms\Components\Textarea::make('wa_template_ppdb')
                            ->label(__('Template PPDB'))
                            ->rows(4)
                            ->helperText('Placeholder yang dikenali: '.implode(', ', PpdbWaTemplate::placeholders()).'.'),

                        Forms\Components\Textarea::make('wa_template_spp')
                            ->label(__('Template Tagihan'))
                            ->rows(4)
                            ->helperText('Placeholder yang dikenali: '.implode(', ', StudentWaTemplate::placeholders()).'.'),

                        Forms\Components\Textarea::make('wa_template_rapor')
                            ->label(__('Template Rapor'))
                            ->rows(4)
                            ->helperText('Placeholder yang dikenali: '.implode(', ', StudentWaTemplate::placeholders()).'.'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        $school = static::schoolById(static::resolveSchoolId($state['school_id'] ?? null));

        if ($school === null) {
            Notification::make()
                ->title(__('Cabang belum ditentukan'))
                ->body(__('Pilih cabang sekolah terlebih dahulu sebelum menyimpan template.'))
                ->danger()
                ->send();

            return;
        }

        // Pagar terakhir: Admin Sekolah tidak pernah melihat pemilih cabang,
        // tetapi state Livewire tetap dapat dikirim apa adanya.
        if (Auth::user()?->cannot('configureWaTemplates', $school)) {
            Notification::make()
                ->title(__('Tidak diizinkan'))
                ->body(__('Anda hanya dapat menyetel template cabang Anda sendiri.'))
                ->danger()
                ->send();

            return;
        }

        // Hanya tiga kolom ini yang disentuh; pengaturan cabang lainnya tidak
        // ikut tertulis ulang oleh halaman ini.
        $school->update([
            'wa_template_ppdb' => static::normalise($state['wa_template_ppdb'] ?? null),
            'wa_template_spp' => static::normalise($state['wa_template_spp'] ?? null),
            'wa_template_rapor' => static::normalise($state['wa_template_rapor'] ?? null),
        ]);

        Notification::make()
            ->title(__('Template WhatsApp disimpan'))
            ->body(__('Notifikasi yang sudah terbit tidak berubah — template ini berlaku untuk kejadian berikutnya.'))
            ->success()
            ->send();
    }

    /**
     * Kosong disimpan sebagai NULL, bukan string kosong, supaya "belum diisi"
     * hanya punya satu bentuk.
     */
    protected static function normalise(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }

    /**
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
     * @return array<string, mixed>
     */
    protected function templatesOf(?int $schoolId): array
    {
        $school = static::schoolById($schoolId);

        return [
            'school_id' => $schoolId,
            'wa_template_ppdb' => $school?->wa_template_ppdb,
            'wa_template_spp' => $school?->wa_template_spp,
            'wa_template_rapor' => $school?->wa_template_rapor,
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __(static::$navigationGroup);
    }

    public static function getNavigationLabel(): string
    {
        return __(static::$navigationLabel);
    }

    public function getTitle(): string
    {
        return __(static::$title);
    }
}
