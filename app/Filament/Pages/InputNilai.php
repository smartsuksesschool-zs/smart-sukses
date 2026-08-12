<?php

namespace App\Filament\Pages;

use App\Enums\AssessmentType;
use App\Enums\GradeType;
use App\Enums\PermissionName;
use App\Models\ClassSubject;
use App\Models\Grade;
use App\Models\GradeConfig;
use App\Models\Student;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * NILAI-01 / API 4.8 — POST /grades/bulk:
 * "Input nilai massal untuk satu class_subject (array of {student_id, score})".
 *
 * Guru hanya melihat kelas-mapel yang dia ampu (API 4.8 baris 8).
 */
class InputNilai extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Akademik';

    protected static ?string $navigationLabel = 'Input Nilai';

    protected static ?string $title = 'Input Nilai Massal';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.input-nilai';

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->can(PermissionName::GradeManage->value) ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'grade_type' => GradeType::Daily->value,
            'assessment_type' => AssessmentType::Summative->value,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Pilih Kelas & Komponen')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('class_subject_id')
                            ->label('Kelas — Mata Pelajaran')
                            ->options(fn () => $this->classSubjectOptions())
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn () => $this->refreshStudentRows()),

                        Forms\Components\Select::make('grade_type')
                            ->label('Komponen')
                            ->options(GradeType::options())
                            ->required()
                            ->live(),

                        Forms\Components\Select::make('assessment_type')
                            ->label('Jenis Penilaian')
                            ->options(AssessmentType::options())
                            ->default(AssessmentType::Summative->value)
                            ->required()
                            ->helperText('Hanya penilaian sumatif yang dihitung ke rapor.'),

                        Forms\Components\TextInput::make('description')
                            ->label('Keterangan')
                            ->maxLength(200)
                            ->placeholder('Ulangan Harian Bab 3'),
                    ]),

                Forms\Components\Section::make('Nilai Siswa')
                    ->visible(fn (Forms\Get $get) => filled($get('class_subject_id')))
                    ->schema([
                        Forms\Components\Repeater::make('rows')
                            ->label('')
                            ->columns(2)
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->schema([
                                Forms\Components\TextInput::make('student_name')
                                    ->label('Siswa')
                                    ->disabled()
                                    ->dehydrated(false),

                                Forms\Components\TextInput::make('score')
                                    ->label('Nilai')
                                    ->numeric()
                                    ->minValue(Grade::MIN_SCORE)
                                    ->maxValue(Grade::MAX_SCORE)
                                    ->step(0.01)
                                    ->helperText('Kosongkan bila siswa ini belum dinilai.'),

                                Forms\Components\Hidden::make('student_id'),
                            ]),
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * Mengisi ulang daftar siswa saat kelas-mapel berganti.
     */
    public function refreshStudentRows(): void
    {
        $classSubjectId = $this->data['class_subject_id'] ?? null;

        if (blank($classSubjectId)) {
            $this->data['rows'] = [];

            return;
        }

        $classSubject = ClassSubject::query()->find($classSubjectId);

        if ($classSubject === null) {
            $this->data['rows'] = [];

            return;
        }

        $this->data['rows'] = Student::query()
            ->active()
            ->inClass((int) $classSubject->class_id)
            ->orderBy('full_name')
            ->get()
            ->map(fn (Student $student) => [
                'student_id' => $student->getKey(),
                'student_name' => "{$student->full_name} ({$student->nis})",
                'score' => null,
            ])
            ->all();
    }

    public function save(): void
    {
        $state = $this->form->getState();

        $classSubject = ClassSubject::query()->findOrFail($state['class_subject_id']);

        if (! Auth::user()?->can('gradeClassSubject', [Grade::class, $classSubject])) {
            Notification::make()
                ->title('Tidak diizinkan')
                ->body('Anda hanya dapat menginput nilai untuk kelas yang Anda ampu.')
                ->danger()
                ->send();

            return;
        }

        $saved = 0;

        DB::transaction(function () use ($state, $classSubject, &$saved): void {
            foreach ($state['rows'] ?? [] as $row) {
                if (blank($row['score'] ?? null) || blank($row['student_id'] ?? null)) {
                    continue;
                }

                Grade::query()->create([
                    'school_id' => $classSubject->school_id,
                    'student_id' => $row['student_id'],
                    'class_subject_id' => $classSubject->getKey(),
                    'academic_year_id' => $classSubject->academic_year_id,
                    'grade_type' => $state['grade_type'],
                    'assessment_type' => $state['assessment_type'],
                    'score' => $row['score'],
                    'description' => $state['description'] ?? null,
                    'graded_by' => Auth::id(),
                    'graded_at' => now(),
                ]);

                $saved++;
            }
        });

        Notification::make()
            ->title("{$saved} nilai tersimpan")
            ->success()
            ->send();

        $this->warnAboutConfigurationGaps($classSubject, $state);

        $this->refreshStudentRows();
    }

    /**
     * Dua keadaan yang membuat nilai sumatif tidak ikut menghitung rapor,
     * keduanya senyap tanpa peringatan ini:
     *
     *   C-6      — komponennya tidak tercantum di Grade Config yang aktif;
     *   LOCKED   — tidak ada konfigurasi aktif sama sekali karena versi
     *              terakhirnya sudah dikunci setelah rapor terbit.
     *
     * Keduanya hanya memberi tahu. Tidak ada konfigurasi yang dibuka, dibuat,
     * atau diaktifkan otomatis — siklus DRAFT → ACTIVE → LOCKED tetap milik
     * Admin (keputusan butir 4).
     *
     * @param  array<string, mixed>  $state
     */
    protected function warnAboutConfigurationGaps(ClassSubject $classSubject, array $state): void
    {
        $type = GradeType::tryFrom((string) ($state['grade_type'] ?? ''));

        // Formatif memang tidak dihitung, dan sikap dilaporkan terpisah sebagai
        // predikat — keduanya bukan kelalaian konfigurasi.
        if ($type === null
            || ! $type->isAcademic()
            || ($state['assessment_type'] ?? null) !== AssessmentType::Summative->value) {
            return;
        }

        $subjectId = (int) $classSubject->subject_id;
        $academicYearId = (int) $classSubject->academic_year_id;

        $config = GradeConfig::activeFor($subjectId, $academicYearId);

        if ($config === null) {
            $this->warnIfConfigurationIsLocked($subjectId, $academicYearId);

            return;
        }

        if (in_array($type, $config->componentTypes(), true)) {
            return;
        }

        Notification::make()
            ->title('Komponen ini tidak masuk nilai akhir')
            ->body(sprintf(
                'Nilai tersimpan, tetapi komponen %s tidak ada di Grade Config %s sehingga '
                    .'tidak ikut menghitung nilai rapor. Tambahkan komponen itu lewat versi '
                    .'konfigurasi baru bila memang seharusnya dihitung.',
                $type->label(),
                $config->label(),
            ))
            ->warning()
            ->persistent()
            ->send();
    }

    /**
     * Tidak adanya konfigurasi aktif punya dua sebab yang sangat berbeda.
     * Mapel yang memang belum pernah dikonfigurasi bukan masalah — nilainya
     * mendapat snapshot begitu Admin mengaktifkan konfigurasi pertama. Yang
     * perlu diperingatkan adalah mapel yang konfigurasinya sudah LOCKED:
     * nilai baru tersimpan tanpa bobot dan tidak akan pernah masuk rapor
     * sampai ada versi baru yang diaktifkan. Ini persis yang dialami siswa
     * yang masuk setelah rapor sekelas terbit.
     */
    protected function warnIfConfigurationIsLocked(int $subjectId, int $academicYearId): void
    {
        $locked = GradeConfig::lockedFor($subjectId, $academicYearId);

        if ($locked === null) {
            return;
        }

        Notification::make()
            ->title('Grade Config sudah terkunci')
            ->body(sprintf(
                'Nilai tersimpan tanpa bobot karena Grade Config %s berstatus LOCKED dan '
                    .'tidak ada versi aktif sebagai acuan. Nilai ini belum akan masuk rapor '
                    .'sampai Admin membuat versi Grade Config baru lewat "Buat Versi Baru" '
                    .'lalu mengaktifkannya.',
                $locked->label(),
            ))
            ->warning()
            ->persistent()
            ->send();
    }

    /**
     * Guru mata pelajaran hanya melihat kelas yang dia ampu; administrator
     * cabang melihat seluruhnya (matriks 1.1.2 "Input Nilai" ✅).
     *
     * @return array<int, string>
     */
    protected function classSubjectOptions(): array
    {
        $user = Auth::user();

        return ClassSubject::query()
            ->with(['schoolClass', 'subject'])
            ->when(
                ! $user?->isSchoolAdmin() && ! $user?->isSuperAdmin(),
                fn ($query) => $query->where('teacher_id', $user?->getKey()),
            )
            ->get()
            ->mapWithKeys(fn (ClassSubject $cs) => [
                $cs->id => trim(($cs->schoolClass?->name ?? '?').' — '.($cs->subject?->name ?? '?')),
            ])
            ->all();
    }
}
