<?php

namespace App\Filament\Pages;

use App\Enums\AttitudePredicate;
use App\Models\GradeConfig;
use App\Models\School;
use App\Services\Grading\AttitudePredicateResolver;
use Closure;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Keputusan Sprint 4 butir 3 — "Range attitude JANGAN hard-code. Simpan
 * sebagai configuration agar dapat diubah Admin."
 *
 * Rentang disimpan pada `schools.attitude_scale` (di luar ERD; lihat
 * docs/implementation-notes.md butir 27). Kewenangannya disamakan dengan
 * konfigurasi bobot: hanya SCHOOL_ADMIN & SUPER_ADMIN.
 */
class PengaturanPenilaian extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'Akademik';

    protected static ?string $navigationLabel = 'Pengaturan Penilaian';

    protected static ?string $title = 'Pengaturan Penilaian';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.pages.pengaturan-penilaian';

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->can('configureAttitudeScale', GradeConfig::class) ?? false;
    }

    public function mount(): void
    {
        $schoolId = static::resolveSchoolId();

        $this->form->fill([
            'school_id' => $schoolId,
            'attitude_scale' => static::scaleRows(static::schoolById($schoolId)),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Skala Predikat Sikap')
                    ->description('Batas bawah tiap predikat. Nilai sikap tidak masuk perhitungan nilai akademik dan hanya dilaporkan sebagai predikat pada rapor.')
                    ->schema([
                        // `attitude_scale` adalah konfigurasi per cabang (butir 27),
                        // sedangkan Super Admin memang tidak memiliki `school_id`
                        // (Arsitektur 3.2 — "Super Admin melewati Global Scope").
                        // Cabangnya karena itu dipilih di sini, mengikuti pola
                        // GradeConfigResource: field hanya dirender untuk Super
                        // Admin, sementara Admin Sekolah tetap terikat akunnya.
                        Forms\Components\Select::make('school_id')
                            ->label('Cabang Sekolah')
                            ->options(fn () => School::query()
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live()
                            // Skala cabang lain tidak boleh tertinggal di form.
                            ->afterStateUpdated(function (mixed $state, Forms\Set $set): void {
                                $set('attitude_scale', static::scaleRows(
                                    static::schoolById(static::resolveSchoolId($state)),
                                ));
                            })
                            ->visible(fn () => Auth::user()?->isSuperAdmin())
                            ->helperText('Skala yang disimpan hanya berlaku untuk cabang ini.'),

                        Forms\Components\Repeater::make('attitude_scale')
                            ->label('')
                            ->columns(2)
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->schema([
                                Forms\Components\TextInput::make('predicate')
                                    ->label('Predikat')
                                    ->disabled()
                                    ->dehydrated(),

                                Forms\Components\TextInput::make('minimum')
                                    ->label('Batas Bawah')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->step(0.01)
                                    ->required(),
                            ])
                            // Validasi per-field tidak cukup: A=50 dan B=80
                            // masing-masing sah, tetapi gabungannya membalik
                            // predikat. Koherensi antar-baris karena itu
                            // diperiksa di tingkat repeater.
                            ->rules([
                                fn (): Closure => function (string $attribute, $value, Closure $fail): void {
                                    try {
                                        app(AttitudePredicateResolver::class)
                                            ->validateScale(static::scaleFromRows((array) $value));
                                    } catch (ValidationException $exception) {
                                        $fail((string) collect($exception->errors())->flatten()->first());
                                    }
                                },
                            ]),
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * Baris repeater → peta predikat ke batas bawah.
     *
     * @param  array<int, mixed>  $rows
     * @return array<string, mixed>
     */
    protected static function scaleFromRows(array $rows): array
    {
        $scale = [];

        foreach ($rows as $row) {
            $predicate = AttitudePredicate::tryFrom((is_array($row) ? $row['predicate'] : null) ?? '');

            if ($predicate !== null) {
                $scale[$predicate->value] = is_array($row) ? ($row['minimum'] ?? null) : null;
            }
        }

        return $scale;
    }

    public function save(): void
    {
        // getState() menjalankan validasi lebih dulu, jadi cabang yang belum
        // dipilih dan skala yang urutannya terbalik tidak pernah sampai ke
        // baris-baris di bawahnya.
        $state = $this->form->getState();

        $school = static::schoolById(static::resolveSchoolId($state['school_id'] ?? null));

        if ($school === null) {
            Notification::make()
                ->title('Cabang belum ditentukan')
                ->body('Pilih cabang sekolah terlebih dahulu sebelum menyimpan skala predikat.')
                ->danger()
                ->send();

            return;
        }

        $scale = array_map(
            fn (mixed $minimum) => (float) $minimum,
            static::scaleFromRows((array) ($state['attitude_scale'] ?? [])),
        );

        $school->update(['attitude_scale' => $scale]);

        Notification::make()->title('Skala predikat sikap disimpan')->success()->send();
    }

    /**
     * Deskripsi rentang aktif untuk ditampilkan di halaman.
     *
     * @return array<string, string>
     */
    public function describedScale(): array
    {
        return app(AttitudePredicateResolver::class)->describe($this->school());
    }

    /**
     * Cabang yang skalanya sedang disetel.
     *
     * Nilai form hanya dipercaya dari Super Admin. Bagi peran lain field
     * "Cabang Sekolah" tidak pernah dirender, sehingga apa pun yang muncul di
     * state Livewire adalah selundupan dan diabaikan — inilah yang menjaga
     * `attitude_scale` tidak tercampur antar cabang.
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
     * Baris repeater untuk satu cabang. `scaleFor()` sendiri sudah jatuh ke
     * rentang default bila cabang belum menyetel apa pun.
     *
     * @return array<int, array{predicate: string, minimum: float}>
     */
    protected static function scaleRows(?School $school): array
    {
        $scale = app(AttitudePredicateResolver::class)->scaleFor($school);

        return array_map(
            fn (AttitudePredicate $predicate) => [
                'predicate' => $predicate->value,
                'minimum' => $scale[$predicate->value] ?? AttitudePredicate::defaultScale()[$predicate->value],
            ],
            AttitudePredicate::cases(),
        );
    }

    protected function school(): ?School
    {
        return static::schoolById(static::resolveSchoolId($this->data['school_id'] ?? null));
    }
}
