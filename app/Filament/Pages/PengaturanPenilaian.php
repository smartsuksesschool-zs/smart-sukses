<?php

namespace App\Filament\Pages;

use App\Enums\AttitudePredicate;
use App\Models\GradeConfig;
use App\Models\School;
use App\Services\Grading\AttitudePredicateResolver;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

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
        $scale = app(AttitudePredicateResolver::class)->scaleFor($this->school());

        $this->form->fill([
            'attitude_scale' => array_map(
                fn (AttitudePredicate $predicate) => [
                    'predicate' => $predicate->value,
                    'minimum' => $scale[$predicate->value] ?? AttitudePredicate::defaultScale()[$predicate->value],
                ],
                AttitudePredicate::cases(),
            ),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Skala Predikat Sikap')
                    ->description('Batas bawah tiap predikat. Nilai sikap tidak masuk perhitungan nilai akademik dan hanya dilaporkan sebagai predikat pada rapor.')
                    ->schema([
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
                            ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $school = $this->school();

        if ($school === null) {
            Notification::make()
                ->title('Tidak ada cabang aktif')
                ->body('Super Admin perlu masuk sebagai pengguna cabang untuk menyetel skala sikap.')
                ->danger()
                ->send();

            return;
        }

        $state = $this->form->getState();
        $scale = [];

        foreach ($state['attitude_scale'] ?? [] as $row) {
            $predicate = AttitudePredicate::tryFrom($row['predicate'] ?? '');

            if ($predicate !== null) {
                $scale[$predicate->value] = (float) $row['minimum'];
            }
        }

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

    protected function school(): ?School
    {
        $schoolId = Auth::user()?->school_id;

        return $schoolId === null ? null : School::query()->find($schoolId);
    }
}
