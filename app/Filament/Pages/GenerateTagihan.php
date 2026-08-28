<?php

namespace App\Filament\Pages;

use App\Jobs\GenerateStudentFees;
use App\Models\FeeType;
use App\Models\School;
use App\Models\Scopes\SchoolScope;
use App\Models\Student;
use App\Models\StudentFee;
use App\Services\Finance\StudentFeeGenerator;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Exists;

/**
 * SPP-02 — "men-generate tagihan SPP bulanan untuk semua siswa aktif dalam
 * satu klik", dengan syarat "preview daftar tagihan ditampilkan sebelum
 * konfirmasi generate".
 *
 * Pratinjau karena itu bukan kenyamanan melainkan gerbang: tombol Generate
 * baru hidup setelah pratinjau dibuat, dan pratinjau menjadi basi begitu satu
 * pun isian berubah — apa yang dikonfirmasi operator harus persis apa yang
 * dilihatnya.
 */
class GenerateTagihan extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-document-plus';

    protected static ?string $navigationGroup = 'Keuangan';

    protected static ?string $navigationLabel = 'Generate Tagihan';

    protected static ?string $title = 'Generate Tagihan Massal';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.generate-tagihan';

    /**
     * Banyaknya nama siswa yang dirender pada pratinjau. Sisanya diringkas
     * sebagai jumlah — bukan dipotong diam-diam.
     */
    public const PREVIEW_LIST_LIMIT = 50;

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    /**
     * Hasil pratinjau terakhir, atau NULL bila belum ada.
     *
     * @var array<string, mixed>|null
     */
    public ?array $preview = null;

    /**
     * Sidik isian saat pratinjau dibuat. Perbedaan dengan sidik isian saat ini
     * berarti pratinjau sudah tidak menggambarkan apa yang akan diterbitkan.
     */
    public ?string $previewSignature = null;

    public static function canAccess(): bool
    {
        return Auth::user()?->can('create', StudentFee::class) ?? false;
    }

    /**
     * `canAccess()` hanya menyembunyikan menu; halamannya sendiri tetap punya
     * rute. Penjagaan aksesnya karena itu ditegakkan di sini, bukan hanya di
     * navigasi.
     */
    public function mount(): void
    {
        $this->authorizeGenerate();

        $period = now()->format('Y-m');

        $this->form->fill([
            'school_id' => static::resolveSchoolId(),
            'period' => $period,
            'due_date' => static::defaultDueDate($period),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('Tagihan yang akan diterbitkan'))
                    ->columns(2)
                    ->schema([
                        // Super Admin tidak memiliki school_id (Arsitektur 3.2),
                        // sehingga cabang harus dipilih eksplisit. Pola sama
                        // dengan FeeTypeResource dan PengaturanPenilaian.
                        Forms\Components\Select::make('school_id')
                            ->label(__('Cabang Sekolah'))
                            ->options(fn () => School::query()
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Forms\Set $set) => $set('fee_type_id', null))
                            ->visible(fn () => Auth::user()?->isSuperAdmin())
                            ->columnSpanFull()
                            ->helperText(__('Jenis tagihan dan daftar siswa mengikuti cabang ini.')),

                        Forms\Components\Select::make('fee_type_id')
                            ->label(__('Jenis Tagihan'))
                            ->options(fn (Forms\Get $get) => static::feeTypeOptions(
                                static::resolveSchoolId($get('school_id')),
                            ))
                            ->required()
                            ->searchable()
                            ->live()
                            // Opsi sudah disaring, tetapi state Livewire bisa
                            // dikirim apa adanya — pagar terakhirnya di validasi.
                            ->exists(
                                table: 'fee_types',
                                column: 'id',
                                modifyRuleUsing: fn (Exists $rule, Forms\Get $get) => $rule
                                    ->where('school_id', static::resolveSchoolId($get('school_id')))
                                    ->where('is_active', true),
                            )
                            ->helperText(__('Hanya jenis tagihan yang berstatus aktif.')),

                        Forms\Components\TextInput::make('period')
                            ->label(__('Periode'))
                            ->required()
                            ->maxLength(7)
                            ->placeholder('2026-08')
                            ->live(onBlur: true)
                            // ERD: "Periode: format YYYY-MM".
                            ->rule('regex:/^\d{4}-(0[1-9]|1[0-2])$/')
                            ->validationMessages([
                                'regex' => 'Periode harus berformat YYYY-MM, misalnya 2026-08.',
                            ])
                            // Due date bawaan mengikuti periode yang dipilih,
                            // bukan bulan berjalan.
                            ->afterStateUpdated(function (mixed $state, Forms\Set $set): void {
                                $due = static::defaultDueDate(is_string($state) ? $state : '');

                                if ($due !== null) {
                                    $set('due_date', $due);
                                }
                            }),

                        Forms\Components\DatePicker::make('due_date')
                            ->label(__('Jatuh Tempo'))
                            ->required()
                            ->native(false)
                            ->live()
                            ->helperText(__('Bawaan: tanggal 10 pada periode terpilih (SPP-02 poin 2). Dapat diubah.')),
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * SPP-02 poin 3 — pratinjau sebelum konfirmasi. Tidak menulis apa pun.
     */
    public function preview(): void
    {
        $this->authorizeGenerate();

        $data = $this->form->getState();

        $feeType = $this->resolveFeeType($data);

        if ($feeType === null) {
            $this->forgetPreview();

            Notification::make()
                ->title(__('Jenis tagihan tidak ditemukan pada cabang ini'))
                ->danger()
                ->send();

            return;
        }

        $plan = app(StudentFeeGenerator::class)->preview($feeType, $data['period']);

        $this->preview = [
            'fee_type' => $feeType->name,
            'amount' => $feeType->amount,
            'frequency' => $feeType->frequency->label(),
            'period' => $data['period'],
            'due_date' => $data['due_date'],
            'school' => $feeType->school?->name,
            'target_count' => $plan['targets']->count(),
            'skipped_count' => $plan['skipped']->count(),
            'active_count' => $plan['targets']->count() + $plan['skipped']->count(),
            'targets' => $this->names($plan['targets']),
            'skipped' => $this->names($plan['skipped']),
        ];

        $this->previewSignature = $this->signature();
    }

    /**
     * Menerbitkan tagihan lewat antrean (Tech stack 3.1).
     */
    public function generate(): void
    {
        $this->authorizeGenerate();

        $data = $this->form->getState();

        // Gerbang SPP-02 poin 3. Diperiksa di sini, bukan hanya di tampilan:
        // tombol yang dinonaktifkan hanyalah petunjuk, sedangkan permintaan
        // Livewire tetap bisa dikirim langsung.
        if (! $this->hasFreshPreview()) {
            $this->forgetPreview();

            Notification::make()
                ->title(__('Pratinjau belum dibuat atau sudah tidak sesuai'))
                ->body(__('Isian berubah setelah pratinjau terakhir. Jalankan Pratinjau lagi sebelum menerbitkan.'))
                ->warning()
                ->send();

            return;
        }

        $feeType = $this->resolveFeeType($data);

        if ($feeType === null) {
            $this->forgetPreview();

            Notification::make()
                ->title(__('Jenis tagihan tidak ditemukan pada cabang ini'))
                ->danger()
                ->send();

            return;
        }

        $targetCount = $this->preview['target_count'] ?? 0;

        GenerateStudentFees::dispatch(
            (int) $feeType->school_id,
            (int) $feeType->getKey(),
            $data['period'],
            Carbon::parse($data['due_date'])->toDateString(),
        );

        // Pratinjau dilupakan setelah dispatch: klik kedua pada tombol yang
        // sama harus melewati pratinjau lagi.
        $this->forgetPreview();

        Notification::make()
            ->title($targetCount === 0
                ? 'Tidak ada tagihan baru untuk diterbitkan'
                : "{$targetCount} tagihan masuk antrean")
            ->body($targetCount === 0
                ? 'Seluruh siswa aktif sudah memiliki tagihan untuk kombinasi ini.'
                : 'Tagihan diterbitkan di latar belakang; halaman ini tidak perlu dibiarkan terbuka.')
            ->{$targetCount === 0 ? 'warning' : 'success'}()
            ->send();
    }

    /**
     * Pratinjau masih menggambarkan isian yang sekarang.
     */
    public function hasFreshPreview(): bool
    {
        return $this->preview !== null
            && $this->previewSignature !== null
            && $this->previewSignature === $this->signature();
    }

    /**
     * Tanggal 10 pada periode terpilih — SPP-02 poin 2: "Due date otomatis
     * diisi (misal: tanggal 10 bulan berjalan)". Yang dipakai adalah bulan
     * periode, bukan bulan berjalan; keduanya berbeda begitu operator
     * menerbitkan tagihan untuk bulan lain.
     */
    public static function defaultDueDate(string $period): ?string
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            return null;
        }

        return "{$period}-10";
    }

    /**
     * Cabang tempat tagihan diterbitkan.
     *
     * Nilai form hanya dipercaya dari Super Admin — merekalah satu-satunya
     * peran yang melihat field cabang, dan justru merekalah yang `school_id`
     * akunnya NULL. Bagi peran School Level nilai apa pun yang muncul di state
     * Livewire adalah selundupan dan diabaikan.
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
    protected static function feeTypeOptions(?int $schoolId): array
    {
        // Kosong selama cabang belum dipilih: global scope tidak membatasi apa
        // pun bagi Super Admin, sehingga tanpa penyaringan ini daftarnya berisi
        // jenis tagihan seluruh cabang.
        if ($schoolId === null) {
            return [];
        }

        return FeeType::query()
            ->forSchool($schoolId)
            ->active()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Jenis tagihan yang benar-benar milik cabang yang sedang dikerjakan.
     *
     * @param  array<string, mixed>  $data
     */
    protected function resolveFeeType(array $data): ?FeeType
    {
        $schoolId = static::resolveSchoolId($data['school_id'] ?? null);

        if ($schoolId === null) {
            return null;
        }

        return FeeType::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->with('school')
            ->where('school_id', $schoolId)
            ->where('is_active', true)
            ->find($data['fee_type_id'] ?? null);
    }

    protected function authorizeGenerate(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /**
     * Sidik isian yang menentukan hasil penerbitan. Due date ikut dihitung
     * karena ia ditampilkan pada pratinjau dan tersimpan di setiap tagihan.
     */
    protected function signature(): string
    {
        return md5(json_encode([
            static::resolveSchoolId($this->data['school_id'] ?? null),
            $this->data['fee_type_id'] ?? null,
            $this->data['period'] ?? null,
            $this->normalizedDueDate(),
        ]) ?: '');
    }

    /**
     * State mentah DatePicker menyimpan jam ikut-ikutan (Filament mem-parse
     * "2026-08-10" dengan Carbon::createFromFormat, yang melengkapi bagian
     * waktu dari jam sekarang). Yang menentukan hasil penerbitan hanyalah
     * tanggalnya, jadi sidik pratinjau dihitung dari tanggal saja — kalau
     * tidak, pratinjau bisa dianggap basi tanpa ada yang berubah.
     */
    protected function normalizedDueDate(): ?string
    {
        $value = $this->data['due_date'] ?? null;

        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    protected function forgetPreview(): void
    {
        $this->preview = null;
        $this->previewSignature = null;
    }

    /**
     * @param  Collection<int, Student>  $students
     * @return array<int, string>
     */
    protected function names($students): array
    {
        return $students
            ->take(self::PREVIEW_LIST_LIMIT)
            ->map(fn (Student $student) => "{$student->full_name} ({$student->nis})")
            ->all();
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
