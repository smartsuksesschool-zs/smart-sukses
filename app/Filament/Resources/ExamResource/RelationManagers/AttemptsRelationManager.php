<?php

namespace App\Filament\Resources\ExamResource\RelationManagers;

use App\Enums\AssessmentType;
use App\Enums\ExamAttemptStatus;
use App\Enums\GradeType;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ReportCard;
use App\Models\Scopes\SchoolScope;
use App\Services\Cbt\ExamAttemptService;
use App\Services\Cbt\ExamGradeBridge;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Hasil pengerjaan siswa, dan pintu menuju nilai akademik.
 *
 * Dua kewenangan berbeda bertemu di satu tabel: **melihat** hasil (Kepala
 * Sekolah termasuk) dan **memasukkannya ke nilai** (hanya yang berwenang
 * menilai kelas itu). Keduanya dijawab policy yang berbeda, bukan satu
 * pemeriksaan yang dipakai dua kali (butir 324, 325).
 *
 * Membuka halaman ini juga menutup pengerjaan yang batas waktunya sudah lewat —
 * hanya untuk ujian yang sedang dibuka (butir 334).
 */
class AttemptsRelationManager extends RelationManager
{
    protected static string $relationship = 'attempts';

    protected static ?string $title = 'Hasil Ujian';

    protected static ?string $modelLabel = 'Hasil';

    protected static ?string $pluralModelLabel = 'Hasil';

    /** Kewenangan tingkat ujian — sama untuk seluruh baris, dijawab sekali. */
    protected ?bool $mayBridgeCache = null;

    /** Siswa berapor terbit pada tahun ajaran ujian ini, diambil sekali. */
    protected ?array $publishedReportCardStudents = null;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->can('viewResults', $ownerRecord) === true;
    }

    /**
     * Menutup pengerjaan yang sudah lewat batas, sekali saat halaman dibuka.
     *
     * Diletakkan di sini, bukan di dalam pembangun query: query yang menulis
     * akan berjalan ulang pada setiap pengurutan dan setiap perpindahan halaman.
     */
    public function mount(): void
    {
        parent::mount();

        $exam = $this->getOwnerRecord();

        if ($exam instanceof Exam) {
            app(ExamAttemptService::class)->settleExpiredForExam($exam);
        }
    }

    /**
     * Tabel ini tidak pernah menulis pengerjaan siswa. Yang boleh dilakukan
     * guru hanyalah memasukkan hasilnya ke nilai — dan itu aksi tersendiri.
     */
    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->defaultSort('submitted_at', 'desc')
            // NFR 1.4 — siswa dan nilai yang tertaut dimuat sekali, bukan sekali
            // per baris (butir 335).
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['student', 'grade']))
            ->columns([
                Tables\Columns\TextColumn::make('student.full_name')
                    ->label(__('Siswa'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('student.nis')
                    ->label(__('NIS'))
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (ExamAttemptStatus $state) => $state->label())
                    ->color(fn (ExamAttemptStatus $state) => $state->color()),

                Tables\Columns\TextColumn::make('score')
                    ->label(__('Nilai'))
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('started_at')
                    ->label(__('Mulai'))
                    ->dateTime('d M Y H:i')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('submitted_at')
                    ->label(__('Dikumpulkan'))
                    ->dateTime('d M Y H:i')
                    ->placeholder('—')
                    ->sortable(),

                // Keadaan integrasi nilai, dibaca dari kolom penghubung yang
                // sudah ada di barisnya — bukan dari query tambahan per baris.
                //
                // Keadaannya dihitung (`state()`), bukan diambil dari kolom lalu
                // diformat: `grade_id` NULL justru keadaan yang paling sering,
                // dan kolom bernilai NULL tidak melewati `formatStateUsing()`
                // sehingga "Belum masuk nilai" tidak pernah tercetak.
                Tables\Columns\TextColumn::make('grade_status')
                    ->label(__('Nilai Akademik'))
                    ->badge()
                    ->state(fn (ExamAttempt $record) => $record->grade_id === null
                        ? 'Belum masuk nilai'
                        : 'Sudah masuk nilai')
                    ->color(fn (ExamAttempt $record) => $record->grade_id === null ? 'gray' : 'success')
                    ->description(fn (ExamAttempt $record) => $record->grade?->grade_type?->label()),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(ExamAttemptStatus::options()),
            ])
            ->actions([
                static::bridgeAction(),
            ])
            ->bulkActions([
                static::bulkBridgeAction(),
            ]);
    }

    /**
     * "Masukkan ke Nilai" — satu hasil.
     *
     * Bawaan jenis penilaiannya **FORMATIF**, dan itu bukan pilihan estetika:
     * nilai sumatif ikut menghitung rapor. Guru yang membuka aksi ini lalu
     * menekan simpan tanpa membaca tidak boleh diam-diam menggeser nilai rapor
     * siswanya. Sumatif tetap tersedia, tetapi harus dipilih dengan sengaja
     * (butir 336).
     */
    public static function bridgeAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('bridgeToGrade')
            ->label(__('Masukkan ke Nilai'))
            ->icon('heroicon-o-arrow-right-circle')
            ->color('success')
            ->modalHeading(__('Masukkan Hasil Ujian ke Nilai'))
            ->modalDescription('Nilai formatif tidak ikut menghitung rapor. Pilih Sumatif hanya bila '
                .'nilai ini memang menjadi komponen rapor.')
            ->modalSubmitActionLabel(__('Masukkan'))
            ->form([
                Forms\Components\Select::make('grade_type')
                    ->label(__('Jenis Nilai'))
                    ->options(ExamGradeBridge::gradeTypeOptions())
                    ->required()
                    ->in(array_keys(ExamGradeBridge::gradeTypeOptions())),

                Forms\Components\Select::make('assessment_type')
                    ->label(__('Jenis Penilaian'))
                    ->options(AssessmentType::options())
                    ->default(AssessmentType::Formative->value)
                    ->required()
                    ->in(AssessmentType::values())
                    ->helperText(__('Formatif tidak dihitung ke rapor.')),

                Forms\Components\TextInput::make('description')
                    ->label(__('Keterangan'))
                    ->maxLength(200)
                    ->placeholder(__('Dikosongkan berarti memakai judul ujiannya.')),
            ])
            ->visible(fn (ExamAttempt $record, RelationManager $livewire) => $livewire->mayBridge($record))
            ->action(function (ExamAttempt $record, array $data): void {
                try {
                    app(ExamGradeBridge::class)->bridge(
                        $record,
                        Auth::user(),
                        GradeType::from($data['grade_type']),
                        AssessmentType::from($data['assessment_type']),
                        $data['description'] ?? null,
                    );
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title(__('Gagal memasukkan ke nilai'))
                        ->body((string) collect($exception->errors())->flatten()->first())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()->title('Hasil ujian masuk ke nilai')->success()->send();
            });
    }

    /**
     * "Masukkan ke Nilai" — sekaligus untuk beberapa hasil.
     *
     * Dikerjakan karena satu kelas berisi puluhan siswa dan menekan aksi satu
     * per satu adalah pekerjaan yang tidak menghasilkan apa-apa. Ia tidak punya
     * aturan sendiri: yang dipanggil `ExamGradeBridge::bridge()` yang sama,
     * sekali per hasil, masing-masing dengan transaksinya sendiri.
     *
     * Kegagalan sebagian **dilaporkan**, tidak disembunyikan. Rapor yang sudah
     * terbit menolak sebagian siswa sementara yang lain lolos, dan guru harus
     * mengetahui angkanya — laporan "semua berhasil" pada keadaan itu adalah
     * kebohongan yang paling mudah dipercaya (butir 337).
     */
    public static function bulkBridgeAction(): Tables\Actions\BulkAction
    {
        return Tables\Actions\BulkAction::make('bridgeSelectedToGrade')
            ->label(__('Masukkan ke Nilai'))
            ->icon('heroicon-o-arrow-right-circle')
            ->color('success')
            ->modalHeading(__('Masukkan Hasil Terpilih ke Nilai'))
            ->modalDescription('Jenis nilai yang dipilih berlaku untuk seluruh hasil terpilih. '
                .'Hasil yang sudah masuk nilai atau yang rapornya sudah terbit akan dilewati.')
            ->modalSubmitActionLabel(__('Masukkan'))
            ->form([
                Forms\Components\Select::make('grade_type')
                    ->label(__('Jenis Nilai'))
                    ->options(ExamGradeBridge::gradeTypeOptions())
                    ->required()
                    ->in(array_keys(ExamGradeBridge::gradeTypeOptions())),

                Forms\Components\Select::make('assessment_type')
                    ->label(__('Jenis Penilaian'))
                    ->options(AssessmentType::options())
                    ->default(AssessmentType::Formative->value)
                    ->required()
                    ->in(AssessmentType::values())
                    ->helperText(__('Formatif tidak dihitung ke rapor.')),
            ])
            ->visible(fn (RelationManager $livewire) => $livewire->mayBridgeAny())
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, array $data): void {
                $bridge = app(ExamGradeBridge::class);
                $actor = Auth::user();
                $gradeType = GradeType::from($data['grade_type']);
                $assessmentType = AssessmentType::from($data['assessment_type']);

                $done = 0;
                $skipped = [];

                foreach ($records as $record) {
                    try {
                        $bridge->bridge($record, $actor, $gradeType, $assessmentType);
                        $done++;
                    } catch (ValidationException $exception) {
                        $skipped[] = ($record->student?->full_name ?? 'Siswa').' — '
                            .(string) collect($exception->errors())->flatten()->first();
                    }
                }

                Notification::make()
                    ->title("{$done} hasil masuk ke nilai")
                    ->body($skipped === [] ? null : count($skipped).' dilewati: '.implode(' · ', $skipped))
                    ->color($skipped === [] ? 'success' : 'warning')
                    ->persistent(fn () => $skipped !== [])
                    ->send();
            });
    }

    /**
     * Bolehkah tombol "Masukkan ke Nilai" ditampilkan untuk baris ini?
     *
     * Ini **cermin murah**, bukan penegak. Memanggil
     * `ExamGradeBridge::reasonToRefuse()` di sini terasa lebih rapi dan terukur
     * salah: pemeriksaan itu menanyakan ujian, integritasnya, siswanya,
     * kewenangannya, dan rapornya — dan Filament menimbang visibilitas **sekali
     * per baris**. Terukur: enam baris membayar 102 query, satu baris 22
     * (butir 341).
     *
     * Yang dipakai di sini karena itu hanya bahan yang sudah ada di tangan:
     * kewenangan tingkat ujian (dijawab sekali per render), kolom-kolom yang
     * memang sudah termuat di barisnya, dan satu daftar siswa berapor terbit
     * yang diambil sekali.
     *
     * Penegaknya tetap `ExamGradeBridge::bridge()`, yang mengulang **seluruh**
     * pemeriksaan di dalam transaksi setelah mengunci barisnya. Tombol yang
     * tersembunyi bukan pagar; yang menolak adalah service (butir 331).
     */
    public function mayBridge(ExamAttempt $attempt): bool
    {
        return $this->mayBridgeAny()
            && $attempt->status === ExamAttemptStatus::Submitted
            && $attempt->score !== null
            && $attempt->grade_id === null
            && ! in_array((int) $attempt->student_id, $this->studentsWithPublishedReportCard(), true);
    }

    /**
     * Siswa yang rapornya pada tahun ajaran ujian ini sudah terbit.
     *
     * Diambil sekali per render, bukan sekali per baris. Cakupannya cabang dan
     * tahun ajaran ujian yang sedang dibuka — bukan seluruh sekolah.
     *
     * @return array<int, int>
     */
    protected function studentsWithPublishedReportCard(): array
    {
        if ($this->publishedReportCardStudents !== null) {
            return $this->publishedReportCardStudents;
        }

        $exam = $this->getOwnerRecord();

        if (! $exam instanceof Exam) {
            return $this->publishedReportCardStudents = [];
        }

        return $this->publishedReportCardStudents = ReportCard::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $exam->school_id)
            ->where('academic_year_id', $exam->academic_year_id)
            ->published()
            ->pluck('student_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Kewenangan yang tidak bergantung pada baris tertentu — dipakai untuk
     * memutuskan apakah aksi massalnya ditawarkan sama sekali.
     */
    public function mayBridgeAny(): bool
    {
        if ($this->mayBridgeCache !== null) {
            return $this->mayBridgeCache;
        }

        $exam = $this->getOwnerRecord();

        return $this->mayBridgeCache = $exam instanceof Exam
            && Auth::user()?->can('bridgeToGrade', $exam) === true;
    }

    public static function getModelLabel(): string
    {
        return __(static::$modelLabel);
    }

    public static function getPluralModelLabel(): string
    {
        return __(static::$pluralModelLabel);
    }
}
