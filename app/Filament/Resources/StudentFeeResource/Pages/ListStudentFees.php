<?php

namespace App\Filament\Resources\StudentFeeResource\Pages;

use App\Enums\StudentFeeStatus;
use App\Filament\Resources\StudentFeeResource;
use App\Models\School;
use App\Models\StudentFee;
use App\Services\Finance\StudentFeeReportExporter;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * API 4.9 — GET /student-fees.
 *
 * Tidak ada header action untuk membuat tagihan: penerbitannya di halaman
 * Generate Tagihan (SPP-02). Yang ada hanya ekspor laporan (SPP-05).
 */
class ListStudentFees extends ListRecords
{
    protected static string $resource = StudentFeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            static::exportAction(),
        ];
    }

    /**
     * SPP-05 / API 4.9 — GET /student-fees/export.
     *
     * Filternya dipilih di modal, bukan diambil dari filter tabel: laporan ini
     * adalah berkas yang keluar dari sistem, dan operator harus melihat persis
     * apa yang sedang diekspor sebelum menekan tombolnya.
     */
    public static function exportAction(): Actions\Action
    {
        return Actions\Action::make('export')
            ->label('Export Excel')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->modalHeading('Export Laporan Tagihan')
            ->modalDescription('Berkas .xlsx berisi nama siswa, kelas, periode, jumlah tagihan, jumlah bayar, sisa, dan status.')
            ->modalSubmitActionLabel('Unduh')
            // Disembunyikan bila tidak berwenang — tetapi pagarnya ada di
            // StudentFeeReportExporter, yang memeriksa izin yang sama pada
            // jalur yang benar-benar menghasilkan berkas.
            ->visible(fn (): bool => Auth::user()?->can('export', StudentFee::class) ?? false)
            ->form(static::exportFormSchema())
            ->action(fn (array $data): ?BinaryFileResponse => static::handleExport($data));
    }

    /**
     * @return array<int, Forms\Components\Component>
     */
    public static function exportFormSchema(): array
    {
        return [
            // KAS-03 sudah menjawab kebutuhan lintas cabang; SPP-05 adalah
            // laporan satu cabang, jadi Super Admin memilih cabangnya lebih
            // dulu alih-alih mengunduh seluruh cabang tanpa menyadarinya.
            Forms\Components\Select::make('school_id')
                ->label('Cabang Sekolah')
                ->options(fn (): array => School::query()
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->preload()
                ->required()
                ->live()
                ->afterStateUpdated(fn (Forms\Set $set) => $set('class_id', null))
                ->visible(fn (): bool => Auth::user()?->isSuperAdmin() ?? false)
                ->helperText('Laporan berisi satu cabang.'),

            Forms\Components\TextInput::make('period')
                ->label('Periode')
                ->required()
                ->maxLength(7)
                ->default(now()->format('Y-m'))
                ->placeholder('2026-08')
                // Format sama dengan `student_fees.period`.
                ->rule('regex:/^\d{4}-(0[1-9]|1[0-2])$/')
                ->validationMessages([
                    'regex' => 'Periode harus berformat YYYY-MM, misalnya 2026-08.',
                ])
                ->helperText('SPP-05 adalah laporan per periode, jadi bulannya wajib dipilih.'),

            Forms\Components\Select::make('class_id')
                ->label('Kelas')
                ->options(fn (Forms\Get $get): array => app(StudentFeeReportExporter::class)
                    ->classOptions(static::resolveSchoolId($get('school_id'))))
                ->searchable()
                ->placeholder('Semua kelas')
                ->helperText('Kelas mengikuti tahun ajaran tagihannya, bukan kelas siswa saat ini.'),

            Forms\Components\Select::make('status')
                ->label('Status')
                ->options(StudentFeeStatus::options())
                ->placeholder('Semua status')
                ->rule(Rule::enum(StudentFeeStatus::class)),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function handleExport(array $data): ?BinaryFileResponse
    {
        $user = Auth::user();

        if ($user === null) {
            return null;
        }

        return app(StudentFeeReportExporter::class)->download($user, $data);
    }

    /**
     * Cabang laporan. Nilai form hanya dipercaya dari Super Admin — bagi peran
     * School Level field itu tidak pernah dirender, sehingga apa pun yang
     * muncul di state Livewire diabaikan. Pola sama dengan FeeTypeResource;
     * StudentFeeReportExporter memeriksanya ulang di jalur tulis.
     */
    public static function resolveSchoolId(mixed $formValue = null): ?int
    {
        $user = Auth::user();

        if ($user?->isSuperAdmin()) {
            return filled($formValue) && is_numeric($formValue) ? (int) $formValue : null;
        }

        return $user?->school_id;
    }
}
