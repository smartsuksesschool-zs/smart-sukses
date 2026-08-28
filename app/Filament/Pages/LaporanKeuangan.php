<?php

namespace App\Filament\Pages;

use App\Enums\PermissionName;
use App\Models\School;
use App\Services\Finance\FinanceSummaryService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * KAS-02 — "Sebagai Kepala Sekolah / Admin, saya dapat melihat ringkasan
 * keuangan bulanan: total pemasukan, pengeluaran, dan saldo", dengan dashboard
 * yang menampilkan "saldo kas, total penerimaan SPP bulan ini, total
 * pengeluaran bulan ini" dan "grafik tren 6 bulan terakhir".
 *
 * Dashboard **satu cabang**. Ringkasan lintas cabang adalah KAS-03 dan bukan
 * lingkup batch ini; Super Admin karena itu wajib memilih cabang di sini alih-
 * alih diam-diam melihat penjumlahan seluruh cabang.
 *
 * Halaman ini tidak menghitung apa pun sendiri — seluruh angkanya berasal dari
 * FinanceSummaryService.
 */
class LaporanKeuangan extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationGroup = 'Keuangan';

    protected static ?string $navigationLabel = 'Laporan Keuangan';

    protected static ?string $title = 'Laporan Keuangan Bulanan';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.pages.laporan-keuangan';

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    /**
     * Ringkasan periode terpilih, atau NULL bila cabang belum ditentukan.
     *
     * @var array<string, mixed>|null
     */
    public ?array $summary = null;

    /**
     * PRD 1.1.2 — modul "Laporan Keuangan": SUPER_ADMIN ✅, SCHOOL_ADMIN ✅,
     * KEPALA ⭕, GURU/WALI ❌, BENDAHARA ✅, SISWA ❌, ORTU ❌.
     *
     * KAS-02 adalah laporan, bukan pencatatan, sehingga izinnya `financial_report.view`
     * dan bukan `accounting.*` milik buku kas (KAS-01). Lihat butir 85.
     */
    public static function canAccess(): bool
    {
        return Auth::user()?->can(PermissionName::FinancialReportView->value) ?? false;
    }

    /**
     * `canAccess()` hanya menyembunyikan menu; halamannya tetap punya rute.
     * Penjagaan aksesnya karena itu ditegakkan di sini juga.
     */
    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->form->fill([
            'school_id' => static::resolveSchoolId(),
            'period' => now()->format('Y-m'),
        ]);

        $this->refreshSummary();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('Periode'))
                    ->columns(2)
                    ->schema([
                        // Super Admin tidak memiliki school_id (Arsitektur
                        // 3.2.2). KAS-02 adalah dashboard satu cabang, jadi
                        // cabangnya dipilih eksplisit — bukan dijumlahkan
                        // diam-diam (itu KAS-03).
                        Forms\Components\Select::make('school_id')
                            ->label(__('Cabang Sekolah'))
                            ->options(fn (): array => School::query()
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn () => $this->refreshSummary())
                            ->visible(fn (): bool => Auth::user()?->isSuperAdmin() ?? false)
                            ->helperText(__('Ringkasan menampilkan satu cabang. Perbandingan antarcabang belum tersedia.')),

                        Forms\Components\TextInput::make('period')
                            ->label(__('Periode'))
                            ->required()
                            ->maxLength(7)
                            ->placeholder('2026-08')
                            ->live(onBlur: true)
                            // Format sama dengan `student_fees.period`.
                            ->rule('regex:/^\d{4}-(0[1-9]|1[0-2])$/')
                            ->validationMessages([
                                'regex' => 'Periode harus berformat YYYY-MM, misalnya 2026-08.',
                            ])
                            ->afterStateUpdated(fn () => $this->refreshSummary())
                            ->helperText(__('Penerimaan SPP dan pengeluaran mengikuti bulan ini; saldo kas adalah posisi sampai akhir bulan tersebut.')),
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * Menghitung ulang seluruh angka untuk cabang dan periode terpilih.
     */
    public function refreshSummary(): void
    {
        $schoolId = static::resolveSchoolId($this->data['school_id'] ?? null);
        $period = $this->data['period'] ?? null;

        if ($schoolId === null || ! is_string($period)) {
            $this->summary = null;

            return;
        }

        try {
            $this->summary = app(FinanceSummaryService::class)->summarize($schoolId, $period);
        } catch (ValidationException) {
            // Periode belum lengkap saat diketik; formnya sendiri sudah
            // menampilkan pesan validasinya.
            $this->summary = null;
        }
    }

    public function hasSummary(): bool
    {
        return $this->summary !== null;
    }

    /**
     * Nilai tertinggi pada tren — dipakai blade untuk menskalakan tinggi batang.
     */
    public function trendPeak(): string
    {
        $peak = '0.00';

        foreach ($this->summary['trend'] ?? [] as $month) {
            foreach ([$month['income'], $month['expense']] as $value) {
                if (bccomp($value, $peak, 2) > 0) {
                    $peak = $value;
                }
            }
        }

        return $peak;
    }

    /**
     * Tinggi batang dalam persen terhadap nilai tertinggi.
     */
    public function trendBarHeight(string $value): float
    {
        $peak = $this->trendPeak();

        if (bccomp($peak, '0', 2) <= 0) {
            return 0.0;
        }

        return round((float) $value / (float) $peak * 100, 2);
    }

    /**
     * Cabang yang ringkasannya ditampilkan.
     *
     * Nilai form hanya dipercaya dari Super Admin — merekalah satu-satunya
     * peran yang melihat field "Cabang Sekolah", dan justru merekalah yang
     * `school_id`-nya NULL. Bagi peran School Level field itu tidak pernah
     * dirender, sehingga apa pun yang muncul di state Livewire adalah
     * selundupan dan diabaikan sepenuhnya. Pola sama dengan FeeTypeResource
     * dan GenerateTagihan.
     */
    public static function resolveSchoolId(mixed $formValue = null): ?int
    {
        $user = Auth::user();

        if ($user?->isSuperAdmin() && filled($formValue) && is_numeric($formValue)) {
            return (int) $formValue;
        }

        if ($user?->isSuperAdmin()) {
            return null;
        }

        return $user?->school_id;
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
