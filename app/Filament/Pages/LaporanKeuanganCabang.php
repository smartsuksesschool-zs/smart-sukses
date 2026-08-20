<?php

namespace App\Filament\Pages;

use App\Services\Finance\CrossSchoolFinanceSummaryService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * KAS-03 — "Sebagai Super Admin, saya dapat melihat ringkasan keuangan semua
 * cabang dalam satu dashboard": per cabang menampilkan total tagihan, total
 * terkumpul, dan persentase lunas, dengan filter tahun ajaran dan bulan.
 *
 * Halaman terpisah dari Laporan Keuangan (KAS-02), bukan tab di dalamnya:
 * keduanya menjawab pertanyaan berbeda dan memakai definisi "terkumpul" yang
 * berbeda pula. KAS-02 tetap dashboard cabang untuk School Admin, Kepala
 * Sekolah, dan Bendahara; halaman ini hanya untuk Super Admin.
 *
 * Tidak menghitung apa pun sendiri — seluruh angkanya dari
 * CrossSchoolFinanceSummaryService.
 */
class LaporanKeuanganCabang extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationGroup = 'Keuangan';

    protected static ?string $navigationLabel = 'Keuangan Semua Cabang';

    protected static ?string $title = 'Ringkasan Keuangan Semua Cabang';

    protected static ?int $navigationSort = 6;

    protected static string $view = 'filament.pages.laporan-keuangan-cabang';

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    /**
     * Baris ringkasan per cabang.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $rows = [];

    /**
     * PRD KAS-03 menyebut pelakunya tunggal: Super Admin. Tidak ada peran lain
     * yang boleh melihat perbandingan antarcabang — School Admin, Kepala
     * Sekolah, dan Bendahara terikat cabangnya sendiri (Arsitektur 3.2).
     */
    public static function canAccess(): bool
    {
        return Auth::user()?->isSuperAdmin() ?? false;
    }

    /**
     * `canAccess()` hanya menyembunyikan menu; rutenya tetap ada.
     */
    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->form->fill([
            'academic_year' => null,
            'period' => null,
        ]);

        $this->refreshRows();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Filter')
                    ->columns(2)
                    ->schema([
                        // Tahun ajaran adalah baris milik masing-masing cabang
                        // (ERD: "per cabang"), sehingga id-nya tidak pernah
                        // lintas cabang. Yang dipilih di sini namanya, dan
                        // setiap cabang dicocokkan pada baris miliknya sendiri
                        // yang bernama sama (butir 92).
                        Forms\Components\Select::make('academic_year')
                            ->label('Tahun Ajaran')
                            ->options(fn (): array => static::academicYearOptions())
                            ->searchable()
                            ->placeholder('Semua tahun ajaran')
                            ->live()
                            ->afterStateUpdated(fn () => $this->refreshRows())
                            ->helperText('Dicocokkan berdasarkan nama tahun ajaran di setiap cabang.'),

                        Forms\Components\TextInput::make('period')
                            ->label('Bulan')
                            ->maxLength(7)
                            ->placeholder('2026-08')
                            ->live(onBlur: true)
                            // Format sama dengan `student_fees.period`.
                            ->rule('regex:/^\d{4}-(0[1-9]|1[0-2])$/')
                            ->validationMessages([
                                'regex' => 'Bulan harus berformat YYYY-MM, misalnya 2026-08.',
                            ])
                            ->afterStateUpdated(fn () => $this->refreshRows())
                            ->helperText('Kosongkan untuk seluruh bulan.'),
                    ]),
            ])
            ->statePath('data');
    }

    public function refreshRows(): void
    {
        $user = Auth::user();

        if ($user === null || ! $user->isSuperAdmin()) {
            $this->rows = [];

            return;
        }

        $period = $this->data['period'] ?? null;

        try {
            $this->rows = app(CrossSchoolFinanceSummaryService::class)->summarize(
                $user,
                is_string($this->data['academic_year'] ?? null) ? $this->data['academic_year'] : null,
                is_string($period) ? $period : null,
            );
        } catch (ValidationException) {
            // Bulan belum lengkap saat diketik; formnya sendiri sudah
            // menampilkan pesan validasinya.
            $this->rows = [];
        }
    }

    public function hasRows(): bool
    {
        return $this->rows !== [];
    }

    /**
     * Total seluruh cabang — penjumlahan baris yang sedang ditampilkan, bukan
     * query tambahan.
     *
     * @return array{total_billed: string, total_collected: string}
     */
    public function totals(): array
    {
        $billed = '0.00';
        $collected = '0.00';

        foreach ($this->rows as $row) {
            $billed = bcadd($billed, $row['total_billed'], 2);
            $collected = bcadd($collected, $row['total_collected'], 2);
        }

        return ['total_billed' => $billed, 'total_collected' => $collected];
    }

    /**
     * @return array<string, string>
     */
    protected static function academicYearOptions(): array
    {
        $user = Auth::user();

        if ($user === null || ! $user->isSuperAdmin()) {
            return [];
        }

        return app(CrossSchoolFinanceSummaryService::class)->academicYearOptions($user);
    }
}
