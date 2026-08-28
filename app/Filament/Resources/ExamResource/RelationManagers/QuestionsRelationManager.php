<?php

namespace App\Filament\Resources\ExamResource\RelationManagers;

use App\Enums\ExamQuestionType;
use App\Models\Exam;
use App\Models\ExamOption;
use App\Models\ExamQuestion;
use App\Services\Cbt\ExamPublisher;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Penulisan soal dan pilihan jawabannya.
 *
 * Dipilih relation manager, bukan repeater soal di dalam form ujian. Repeater
 * bersarang dua tingkat — soal berisi pilihan — menaruh seluruh isi ujian di
 * dalam satu state Livewire yang harus dikirim ulang setiap kali satu huruf
 * berubah, dan satu kegagalan validasi di ujung mana pun membatalkan
 * seluruhnya. Relation manager membuat setiap soal berdiri sendiri, sehingga
 * yang bersarang tinggal satu tingkat: pilihan jawaban di dalam satu soal
 * (butir 297).
 *
 * Kewenangannya menempel pada **ujiannya**, bukan pada soal: yang boleh menulis
 * soal adalah yang boleh mengubah ujian itu, dan syarat keadaan (masih draf,
 * belum dikerjakan) sudah ada di `ExamPolicy::update()`. Tidak ada aturan kedua
 * di sini (butir 298).
 */
class QuestionsRelationManager extends RelationManager
{
    protected static string $relationship = 'questions';

    protected static ?string $title = 'Soal';

    protected static ?string $modelLabel = 'Soal';

    protected static ?string $pluralModelLabel = 'Soal';

    /**
     * Terlihat oleh siapa pun yang boleh melihat ujiannya — termasuk Kepala
     * Sekolah. Yang membedakan bukan daftar soalnya, melainkan kunci jawaban di
     * dalamnya (lihat `optionsField()`).
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->can('view', $ownerRecord) === true;
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('question_type')
                ->label(__('Jenis Soal'))
                // Hanya jenis yang didukung rilis ini yang ditawarkan. ESSAY ada
                // di skema tetapi tidak boleh dapat dipilih (butir 266).
                ->options(ExamQuestionType::supportedOptions())
                ->default(ExamQuestionType::MultipleChoice->value)
                ->required()
                ->in(ExamQuestionType::supportedValues()),

            Forms\Components\TextInput::make('points')
                ->label(__('Bobot Soal'))
                ->numeric()
                ->step(0.01)
                ->minValue(0.01)
                ->maxValue(999.99)
                ->default(1.00)
                ->required()
                ->helperText('Bobot soal ini di dalam ujiannya. Nilai akhir dihitung sebagai '
                    .'persentase dari total bobot seluruh soal.'),

            Forms\Components\TextInput::make('position')
                ->label(__('Nomor Urut'))
                ->numeric()
                ->minValue(1)
                ->maxValue(9999)
                ->default(fn () => $this->nextPosition())
                ->required(),

            Forms\Components\Textarea::make('question_text')
                ->label(__('Pertanyaan'))
                ->rows(3)
                ->required()
                ->columnSpanFull(),

            $this->optionsField(),
        ]);
    }

    /**
     * Pilihan jawaban satu soal.
     *
     * Repeater biasa, **tanpa** `relationship()` — penyimpanannya dikerjakan
     * `persistQuestion()`. Alasannya ada di butir 302: repeater yang terikat
     * relasi memuat ulang keadaannya dari relasi itu setiap kali form terisi,
     * sehingga pada soal baru isian apa pun tertimpa baris kosong bawaan.
     */
    protected function optionsField(): Forms\Components\Repeater
    {
        return Forms\Components\Repeater::make('options')
            ->label(__('Pilihan Jawaban'))
            ->columns(12)
            ->minItems(2)
            ->defaultItems(4)
            ->reorderable(false)
            ->addActionLabel(__('Tambah pilihan'))
            ->columnSpanFull()
            ->schema([
                // Menandai baris yang sudah ada, supaya menyunting soal
                // memperbarui pilihan jawabannya alih-alih menghapus lalu
                // membuat ulang. Baris yang dibuat ulang akan memutus id yang
                // sudah ditunjuk jawaban siswa.
                Forms\Components\Hidden::make('id'),

                Forms\Components\TextInput::make('position')
                    ->label(__('No.'))
                    ->numeric()
                    ->minValue(1)
                    ->default(1)
                    ->required()
                    ->columnSpan(2),

                Forms\Components\TextInput::make('option_text')
                    ->label(__('Teks Pilihan'))
                    ->required()
                    ->maxLength(500)
                    ->columnSpan(7),

                Forms\Components\Toggle::make('is_correct')
                    ->label(__('Kunci'))
                    ->inline(false)
                    ->columnSpan(3),
            ])
            // Bentuk pilihan jawaban yang sah diputuskan ExamPublisher,
            // satu-satunya pemegang aturan itu. Di sini ia hanya dipanggil lebih
            // awal supaya guru tahu sekarang, bukan saat menekan Terbitkan
            // (butir 288).
            ->rules([
                fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    $reason = app(ExamPublisher::class)->reasonToRejectOptions(
                        array_values(array_map(
                            fn ($option) => [
                                'option_text' => $option['option_text'] ?? null,
                                'is_correct' => (bool) ($option['is_correct'] ?? false),
                            ],
                            (array) $value,
                        )),
                    );

                    if ($reason !== null) {
                        $fail('Pilihan jawaban belum sah: '.$reason);
                    }
                },
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('question_text')
            ->defaultSort('position')
            ->columns([
                Tables\Columns\TextColumn::make('position')
                    ->label(__('No.'))
                    ->sortable(),

                Tables\Columns\TextColumn::make('question_text')
                    ->label(__('Pertanyaan'))
                    ->wrap()
                    ->limit(120),

                Tables\Columns\TextColumn::make('question_type')
                    ->label(__('Jenis'))
                    ->badge()
                    ->formatStateUsing(fn (ExamQuestionType $state) => $state->label()),

                Tables\Columns\TextColumn::make('points')
                    ->label(__('Bobot')),

                // Jumlah pilihan, bukan isinya — dan tidak pernah mana yang
                // benar. Kunci jawaban tidak punya kolom di daftar mana pun
                // (butir 292).
                Tables\Columns\TextColumn::make('options_count')
                    ->label(__('Pilihan'))
                    ->counts('options')
                    ->badge()
                    ->color(fn (int $state) => $state >= 2 ? 'gray' : 'danger'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label(__('Tambah Soal'))
                    ->using(fn (array $data) => $this->persistQuestion(null, $data)),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->mutateRecordDataUsing(fn (array $data, Model $record) => $this->withOptions($data, $record))
                    ->using(fn (Model $record, array $data) => $this->persistQuestion($record, $data)),

                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([]);
    }

    // ------------------------------------------------------------ kewenangan

    /**
     * Menutup seluruh aksi bawaan Filament yang menulis sekaligus — pola yang
     * sama dengan PaymentsRelationManager. Soal ujian yang sudah terbit atau
     * sudah dikerjakan karena itu hanya dapat dibaca, tanpa bergantung pada
     * opsi panel yang kebetulan aktif.
     */
    public function isReadOnly(): bool
    {
        return ! $this->mayEditContent();
    }

    protected function canCreate(): bool
    {
        return $this->mayEditContent();
    }

    protected function canEdit(Model $record): bool
    {
        return $this->mayEditContent();
    }

    protected function canDelete(Model $record): bool
    {
        return $this->mayEditContent();
    }

    protected function canDeleteAny(): bool
    {
        return $this->mayEditContent();
    }

    /**
     * Jawaban `mayEditContent()` selama satu render.
     *
     * Bukan optimasi spekulatif: tanpa ini setiap baris soal menanyakan ulang
     * hal yang sama — kelas yang diampu, dan ada-tidaknya pengerjaan — sekali
     * untuk tombol Sunting dan sekali untuk tombol Hapus. Terukur: daftar berisi
     * enam soal membayar 159 query, sedangkan satu soal 43 (butir 304).
     */
    protected ?bool $mayEditContentCache = null;

    /**
     * Satu pertanyaan untuk seluruh aksi tulis: bolehkah pengguna ini mengubah
     * isi ujian itu?
     *
     * `ExamPolicy::update()` sudah memuat izin, cabang, kelas yang diampu, dan
     * syarat keadaan. Menuliskan syaratnya lagi di sini akan menjadi salinan
     * yang perlahan berbeda (butir 298).
     *
     * Jawabannya sama untuk seluruh baris — ia menyangkut ujiannya, bukan
     * soalnya — sehingga cukup dijawab sekali.
     */
    protected function mayEditContent(): bool
    {
        if ($this->mayEditContentCache !== null) {
            return $this->mayEditContentCache;
        }

        $exam = $this->getOwnerRecord();

        return $this->mayEditContentCache = $exam instanceof Exam
            && Auth::user()?->can('update', $exam) === true;
    }

    /**
     * Nomor urut berikutnya, supaya guru tidak perlu mengingat sudah sampai
     * mana.
     */
    protected function nextPosition(): int
    {
        $exam = $this->getOwnerRecord();

        if (! $exam instanceof Exam) {
            return 1;
        }

        return ((int) $exam->questions()->max('position')) + 1;
    }

    /**
     * Menyimpan satu soal beserta pilihan jawabannya.
     *
     * Ditulis sendiri, tidak diserahkan ke `Repeater::relationship()`. Repeater
     * yang terikat relasi memuat ulang keadaannya dari relasi itu setiap kali
     * form terisi, sehingga pada pembuatan soal baru — ketika relasinya masih
     * kosong — apa pun yang sudah diisi tertimpa oleh baris kosong bawaan.
     * Menuliskannya di sini membuat dua hal menjadi mungkin sekaligus: `school_id`
     * diturunkan dari ujiannya, dan alurnya benar-benar dapat diuji sebagai UI
     * (butir 302).
     *
     * Seluruh penyimpanan lewat model — `create`, `update`, `delete` — bukan
     * query massal, sehingga jejak audit CUD yang sudah ada ikut merekam soal
     * dan setiap pilihan jawabannya tanpa satu baris pun kode tambahan
     * (butir 299).
     *
     * @param  array<string, mixed>  $data
     */
    protected function persistQuestion(?Model $question, array $data): ExamQuestion
    {
        $exam = $this->getOwnerRecord();

        if (! $exam instanceof Exam) {
            throw new RuntimeException('Soal harus dimiliki sebuah ujian.');
        }

        $options = array_values($data['options'] ?? []);
        unset($data['options']);

        $data['school_id'] = $exam->school_id;

        return DB::transaction(function () use ($exam, $question, $data, $options): ExamQuestion {
            if ($question instanceof ExamQuestion) {
                $question->update($data);
            } else {
                $question = $exam->questions()->create($data);
            }

            $this->syncOptions($question, $options);

            return $question;
        });
    }

    /**
     * Menyamakan pilihan jawaban tersimpan dengan yang ada di form.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function syncOptions(ExamQuestion $question, array $rows): void
    {
        $kept = [];

        foreach ($rows as $index => $row) {
            $attributes = [
                'school_id' => $question->school_id,
                'option_text' => $row['option_text'] ?? '',
                'is_correct' => (bool) ($row['is_correct'] ?? false),
                'position' => (int) ($row['position'] ?? $index + 1),
            ];

            $existing = filled($row['id'] ?? null)
                ? $question->options()->whereKey($row['id'])->first()
                : null;

            if ($existing instanceof ExamOption) {
                $existing->update($attributes);
                $kept[] = $existing->getKey();

                continue;
            }

            $kept[] = $question->options()->create($attributes)->getKey();
        }

        // Pilihan yang dihapus guru dari repeater ikut hilang — satu per satu
        // lewat model, supaya jejak auditnya tetap terbentuk.
        $question->options()
            ->when($kept !== [], fn ($query) => $query->whereKeyNot($kept))
            ->get()
            ->each
            ->delete();
    }

    /**
     * Memuat pilihan jawaban tersimpan ke dalam form saat soal disunting.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function withOptions(array $data, Model $record): array
    {
        if (! $record instanceof ExamQuestion) {
            return $data;
        }

        $data['options'] = $record->options()
            ->get()
            ->map(fn (ExamOption $option) => [
                'id' => $option->getKey(),
                'position' => $option->position,
                'option_text' => $option->option_text,
                'is_correct' => (bool) $option->is_correct,
            ])
            ->all();

        return $data;
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
