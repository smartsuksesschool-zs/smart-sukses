<?php

namespace App\Imports;

use App\Enums\Gender;
use App\Enums\StudentClassStatus;
use App\Enums\StudentStatus;
use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentClass;
use App\Support\Migration\NisnNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Events\BeforeSheet;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * SIS-05 / API 4.5 POST /students/import — import siswa massal dari Excel.
 * "Return: sukses + daftar error baris" (API 4.4 untuk import user, pola sama).
 *
 * Kontrak kolomnya ada di COLUMNS dan **satu-satunya**: berkas contoh
 * (App\Exports\StudentTemplateExport) dan naskah modal import membacanya dari
 * sini, tidak menyalinnya. Kolom yang ditambahkan di sini otomatis ikut
 * terdokumentasi; kolom yang hanya ada di berkas contoh mustahil (butir 497).
 */
class StudentsImport implements ToCollection, WithEvents, WithHeadingRow
{
    /**
     * Nama lembar data pada berkas contoh resmi.
     *
     * Ditulis di sini, bukan di kelas ekspornya, karena kontrak berkas dimiliki
     * importer — berkas contoh yang mengikuti (butir 504).
     */
    public const SHEET = 'Data Siswa';

    /**
     * Judul kolom di berkas -> kolom `students`. Urutannya adalah urutan kolom
     * di berkas contoh.
     *
     * @var array<string, string>
     */
    public const COLUMNS = [
        'nis' => 'nis',
        'nisn' => 'nisn',
        'nama_lengkap' => 'full_name',
        'jenis_kelamin' => 'gender',
        /*
         * Penempatan rombel, opsional.
         *
         * Sampai M9.1 kolom ini tidak ada, dan akibatnya terlihat pada uji coba
         * pertama: tiga belas siswa masuk, seluruhnya "Belum ada kelas", dan
         * tata usaha harus menempatkan satu per satu lewat menu Kelas. Impor
         * yang menuntut pekerjaan manual sebanyak itu sesudahnya bukan impor
         * (butir 572).
         *
         * Tetap **opsional**: berkas lama tanpa kolom ini harus tetap terbaca,
         * dan siswa yang rombelnya memang belum ditentukan harus tetap dapat
         * dimasukkan.
         */
        'kelas' => 'class_label',
        'tempat_lahir' => 'birth_place',
        'tanggal_lahir' => 'birth_date',
        'agama' => 'religion',
        'alamat' => 'address',
        'nama_orang_tua' => 'parent_name',
        'hp_orang_tua' => 'parent_phone',
        'email_orang_tua' => 'parent_email',
        'tahun_masuk' => 'entry_year',
        'status' => 'status',
    ];

    /**
     * Kolom yang harus ada judulnya di berkas. Tanpa ketiganya, berkas itu
     * bukan berkas siswa — dan mengabaikannya baris demi baris hanya
     * menghasilkan "0 siswa berhasil diimport" tanpa sebab (butir 500).
     *
     * @var array<int, string>
     */
    public const REQUIRED_COLUMNS = ['nis', 'nama_lengkap', 'jenis_kelamin'];

    public int $imported = 0;

    public int $rejected = 0;

    public bool $sawRows = false;

    /**
     * Satu catatan per lembar yang dibaca: nama, jumlah baris, cocok atau tidak,
     * dan kolom wajib yang hilang.
     *
     * Maatwebsite memanggil `collection()` **sekali untuk setiap lembar**, bukan
     * sekali untuk setiap berkas. Menyimpan kesimpulan dalam satu properti
     * membuat lembar terakhir menimpa lembar sebelumnya — dan berkas contoh
     * resmi berlembar dua (butir 504).
     *
     * @var array<int, array{name: ?string, rows: int, matched: bool, missing: array<int, string>}>
     */
    public array $sheets = [];

    /** @var array<int, string> */
    public array $errors = [];

    protected ?string $currentSheet = null;

    /**
     * NIS yang sudah terlihat pada berkas ini -> nomor barisnya.
     *
     * Dipakai membedakan dua kegagalan yang pesannya dulu sama persis: NIS yang
     * bentrok dengan siswa yang **sudah ada di basis data**, dan NIS yang
     * bentrok dengan **baris lain di berkas yang sama**. Yang pertama menuntut
     * tata usaha memeriksa data siswa; yang kedua menuntutnya memeriksa
     * berkasnya sendiri — dan "Kolom NIS sudah digunakan" tidak memberi tahu
     * yang mana (butir 574).
     *
     * @var array<string, int>
     */
    protected array $seenNis = [];

    /**
     * Rombel yang sudah terisi pada berkas ini -> jumlah tambahannya.
     *
     * Kapasitas kelas ditegakkan layar Kelas (ERD 2.2 classes.capacity), dan
     * impor tidak boleh menjadi pintu belakang yang melewatinya. Baris yang
     * sudah ditempatkan pada berkas yang sama ikut dihitung, sebab keduanya
     * belum tentu tersimpan ketika baris berikutnya dinilai (butir 575).
     *
     * @var array<int, int>
     */
    protected array $placedThisRun = [];

    /** @var array<string, array<int, int>>|null nama rombel ternormalkan -> id */
    protected ?array $classMap = null;

    protected ?AcademicYear $activeYear = null;

    protected bool $activeYearResolved = false;

    public int $placed = 0;

    public function __construct(protected int $schoolId) {}

    /**
     * Nama lembar yang sedang dibaca. `collection()` sendiri tidak menerimanya.
     *
     * @return array<string, callable>
     */
    public function registerEvents(): array
    {
        return [
            BeforeSheet::class => function (BeforeSheet $event): void {
                $this->currentSheet = $event->getSheet()->getDelegate()->getTitle();
            },
        ];
    }

    /**
     * Berkasnya terbaca, tetapi tidak ada satu lembar pun yang judul kolomnya
     * dikenali.
     *
     * Satu lembar yang cocok sudah cukup: berkas contoh resmi memuat lembar
     * "Petunjuk" yang judul kolomnya memang berbeda, dan lembar itu bukan
     * kesalahan pengguna.
     */
    public function headerMismatch(): bool
    {
        return ! $this->matchedAnySheet() && $this->preferredSheet()['rows'] > 0;
    }

    /**
     * Kolom wajib yang hilang, dilihat dari lembar yang paling mungkin
     * dimaksudkan pengguna.
     *
     * @return array<int, string>
     */
    public function missingColumns(): array
    {
        return $this->matchedAnySheet() ? [] : $this->preferredSheet()['missing'];
    }

    public function matchedAnySheet(): bool
    {
        foreach ($this->sheets as $sheet) {
            if ($sheet['matched']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lembar yang dinilai saat tidak ada yang cocok: lembar bernama "Data Siswa"
     * bila ada, selebihnya lembar pertama.
     *
     * Ini yang membedakan "berkas contoh diunggah tanpa diisi" — lembar data ada
     * tetapi kosong — dari "judul kolomnya salah". Tanpa pembedaan itu, template
     * kosong akan dilaporkan sebagai judul kolom yang tidak dikenali, yaitu
     * penolakan keliru yang sama sekali lagi (butir 505).
     *
     * @return array{name: ?string, rows: int, matched: bool, missing: array<int, string>}
     */
    protected function preferredSheet(): array
    {
        foreach ($this->sheets as $sheet) {
            if ($sheet['name'] === self::SHEET) {
                return $sheet;
            }
        }

        return $this->sheets[0] ?? ['name' => null, 'rows' => 0, 'matched' => false, 'missing' => self::REQUIRED_COLUMNS];
    }

    public function collection(Collection $rows): void
    {
        $first = $rows->first();

        if ($first === null) {
            // Lembar kosong tetap dicatat: keberadaannya yang membedakan
            // "belum diisi" dari "judul kolomnya salah".
            $this->sheets[] = [
                'name' => $this->currentSheet,
                'rows' => 0,
                'matched' => false,
                'missing' => self::REQUIRED_COLUMNS,
            ];

            return;
        }

        $headings = $this->headingKeys($first->toArray());
        $missing = array_values(array_diff(self::REQUIRED_COLUMNS, $headings));

        $this->sheets[] = [
            'name' => $this->currentSheet,
            'rows' => $rows->count(),
            'matched' => $missing === [],
            'missing' => $missing,
        ];

        // Lembar yang bukan lembar data **dilewati**, bukan menggagalkan
        // berkasnya. Lembar "Petunjuk" pada berkas contoh resmi masuk ke sini.
        if ($missing !== []) {
            return;
        }

        $this->sawRows = true;

        foreach ($rows as $index => $row) {
            // +2 karena baris 1 adalah heading dan index Collection mulai dari 0.
            $line = $index + 2;
            $data = $this->normalise($this->rekey($row->toArray()));

            if (blank($data['nis']) && blank($data['full_name'])) {
                continue;
            }

            // NISN yang bukan digit, atau lebih panjang dari 10 digit, tidak
            // pernah dipotong diam-diam: barisnya ditolak dengan sebabnya
            // supaya nilainya diperiksa manusia (butir 483).
            if ($data['nisn_state'] === NisnNormalizer::INVALID) {
                $this->errors[] = __('Baris :line: NISN harus berupa angka dan tidak lebih dari 10 digit.', ['line' => $line]);
                $this->rejected++;

                continue;
            }

            unset($data['nisn_state']);

            /*
             * Bentrok NIS di dalam berkas yang sama dilaporkan tersendiri.
             *
             * Aturan `unique` di bawah tetap menangkapnya — baris pertama sudah
             * tersimpan ketika baris kedua dinilai — tetapi pesannya akan
             * menyuruh tata usaha memeriksa data siswa, padahal yang keliru
             * berkasnya sendiri (butir 574).
             */
            $nisKey = (string) ($data['nis'] ?? '');

            if ($nisKey !== '' && isset($this->seenNis[$nisKey])) {
                $this->errors[] = __('Baris :line: NIS ini sudah dipakai baris :first pada berkas yang sama.', [
                    'line' => $line,
                    'first' => $this->seenNis[$nisKey],
                ]);
                $this->rejected++;

                continue;
            }

            $classLabel = $data['class_label'];
            unset($data['class_label']);

            $validator = Validator::make($data, [
                'nis' => [
                    'required',
                    'string',
                    'max:20',
                    Rule::unique('students', 'nis')->where('school_id', $this->schoolId),
                ],
                'nisn' => ['nullable', 'digits:10'],
                'full_name' => ['required', 'string', 'max:150'],
                'gender' => ['required', Rule::in(array_column(Gender::cases(), 'value'))],
                'birth_place' => ['nullable', 'string', 'max:100'],
                'birth_date' => ['nullable', 'date'],
                'religion' => ['nullable', 'string', 'max:30'],
                'parent_name' => ['nullable', 'string', 'max:150'],
                'parent_phone' => ['nullable', 'string', 'max:20'],
                'parent_email' => ['nullable', 'email', 'max:150'],
                'entry_year' => ['nullable', 'integer', 'min:1900', 'max:2200'],
                'status' => ['required', Rule::in(array_column(StudentStatus::cases(), 'value'))],
            ], [
                // Nilai yang keliru di kolom status paling sering angka tingkat
                // kelas ("10"). Pesannya menyebut nilai yang diterima, karena
                // "pilihan status tidak sah" tidak memberi tahu apa pun tentang
                // apa yang harus ditulis — dan angka itu tidak pernah
                // ditafsirkan ulang sebagai penempatan kelas (butir 507).
                'status.in' => __('kolom status harus salah satu dari :values (kosongkan untuk ACTIVE).', [
                    'values' => implode(', ', array_column(StudentStatus::cases(), 'value')),
                ]),
            ]);

            if ($validator->fails()) {
                $this->errors[] = "Baris {$line}: ".implode(' ', $validator->errors()->all());
                $this->rejected++;

                continue;
            }

            // Kelas diselesaikan **sebelum** siswanya dibuat, sehingga baris
            // yang kelasnya keliru tidak pernah meninggalkan siswa tanpa rombel
            // — keadaan yang justru memicu perbaikan ini (butir 572).
            $classId = null;

            if ($classLabel !== null) {
                $resolved = $this->resolveClass($classLabel);

                if ($resolved['error'] !== null) {
                    $this->errors[] = "Baris {$line}: ".$resolved['error'];
                    $this->rejected++;

                    continue;
                }

                $classId = $resolved['id'];
            }

            /*
             * Satu baris, satu transaksi.
             *
             * Siswa dan penempatannya berhasil bersama-sama atau gagal
             * bersama-sama. Berkasnya sendiri sengaja **tidak** dijadikan
             * seluruh-atau-tidak-sama-sekali: kontrak sebagian sudah berlaku
             * sejak awal importer ini — satu baris yang keliru tidak boleh
             * menahan tiga puluh sembilan baris yang benar (butir 487).
             */
            DB::transaction(function () use ($validator, $classId, $classLabel): void {
                $student = Student::create($validator->validated() + ['school_id' => $this->schoolId]);

                if ($classId !== null) {
                    $this->place($student, $classId);
                    $this->placedThisRun[$classId] = ($this->placedThisRun[$classId] ?? 0) + 1;
                    $this->placed++;
                }

                unset($classLabel);
            });

            if ($nisKey !== '') {
                $this->seenNis[$nisKey] = $line;
            }

            $this->imported++;
        }
    }

    /**
     * Menempatkan siswa pada rombel tahun ajaran aktif.
     *
     * Bentuknya sama persis dengan jalur migrasi legacy dan layar Kelas:
     * `firstOrCreate` atas kunci sekolah + siswa + tahun + status aktif,
     * sehingga aturan "satu penempatan aktif per siswa per tahun" ditegakkan
     * satu cara saja, bukan tiga cara yang perlahan berbeda (butir 573).
     */
    protected function place(Student $student, int $classId): void
    {
        StudentClass::query()->firstOrCreate(
            [
                'school_id' => $this->schoolId,
                'student_id' => $student->id,
                'academic_year_id' => $this->activeYear()?->id,
                'status' => StudentClassStatus::Active->value,
            ],
            [
                'class_id' => $classId,
            ],
        );
    }

    /**
     * Nama rombel dari berkas -> id rombel, atau sebab penolakannya.
     *
     * Pencocokannya **deterministik**: spasi dirapikan dan huruf disamakan,
     * tidak lebih. Tidak ada tebakan kemiripan, dan tidak ada alias yang
     * diwarisi dari jalur migrasi legacy — alias di `CanonicalRombel` adalah
     * koreksi atas salah ketik pada satu berkas sumber tertentu, bukan aturan
     * umum tentang label rombel mana pun (butir 506, 576).
     *
     * Rombel tidak pernah dibuat dari teks di berkas. Yang membuat rombel hanya
     * layar Kelas, dengan sengaja (butir 490).
     *
     * @return array{id: ?int, error: ?string}
     */
    protected function resolveClass(string $label): array
    {
        $year = $this->activeYear();

        if ($year === null) {
            return [
                'id' => null,
                'error' => __('kolom kelas diisi, tetapi cabang ini belum punya tahun ajaran aktif.'),
            ];
        }

        $key = self::classKey($label);
        $matches = $this->classMap()[$key] ?? [];

        if ($matches === []) {
            $available = implode(', ', array_keys($this->classNames()));

            return [
                'id' => null,
                'error' => $available === ''
                    ? __('Kelas ":label" tidak ditemukan; belum ada rombel pada tahun ajaran aktif.', ['label' => $label])
                    : __('Kelas ":label" tidak ditemukan pada tahun ajaran aktif. Pilihan yang ada: :available.', [
                        'label' => $label,
                        'available' => $available,
                    ]),
            ];
        }

        if (count($matches) > 1) {
            // Dua rombel bernama sama pada satu tahun ajaran adalah cacat data.
            // Menebak salah satunya berarti menempatkan siswa di kelas yang
            // belum tentu dimaksud siapa pun.
            return [
                'id' => null,
                'error' => __('Kelas ":label" cocok dengan lebih dari satu rombel pada tahun ajaran aktif.', ['label' => $label]),
            ];
        }

        $classId = $matches[0];
        $class = SchoolClass::query()->withoutGlobalScopes()->find($classId);

        if ($class !== null && ! $this->hasRoomFor($class)) {
            return [
                'id' => null,
                'error' => __('Kelas ":label" sudah penuh (kapasitas :capacity).', [
                    'label' => $class->name,
                    'capacity' => $class->capacity,
                ]),
            ];
        }

        return ['id' => $classId, 'error' => null];
    }

    /**
     * Kapasitas rombel, menghitung juga baris yang sudah ditempatkan pada
     * berkas ini (butir 575).
     */
    protected function hasRoomFor(SchoolClass $class): bool
    {
        $pending = $this->placedThisRun[$class->id] ?? 0;

        return ($class->activeStudentCount() + $pending) < $class->capacity;
    }

    protected function activeYear(): ?AcademicYear
    {
        if (! $this->activeYearResolved) {
            $this->activeYear = AcademicYear::query()
                ->withoutGlobalScopes()
                ->where('school_id', $this->schoolId)
                ->where('is_active', true)
                ->first();

            $this->activeYearResolved = true;
        }

        return $this->activeYear;
    }

    /**
     * Nama rombel ternormalkan -> daftar id, dibatasi cabang dan tahun aktif.
     *
     * Dibatasi eksplisit pada `school_id`, tidak menyandarkan diri pada global
     * scope: importer dipanggil dengan cabang yang sudah ditetapkan pemanggil,
     * dan pembatasan yang tertulis tidak dapat hilang karena konteks sesi
     * berubah.
     *
     * @return array<string, array<int, int>>
     */
    protected function classMap(): array
    {
        if ($this->classMap === null) {
            $this->classMap = [];

            foreach ($this->classNames() as $name => $id) {
                $this->classMap[self::classKey($name)][] = $id;
            }
        }

        return $this->classMap;
    }

    /**
     * @return array<string, int>
     */
    protected function classNames(): array
    {
        $year = $this->activeYear();

        if ($year === null) {
            return [];
        }

        return SchoolClass::query()
            ->withoutGlobalScopes()
            ->where('school_id', $this->schoolId)
            ->where('academic_year_id', $year->id)
            ->orderBy('name')
            ->pluck('id', 'name')
            ->all();
    }

    /**
     * Kunci pencocokan rombel: spasi dirapikan, huruf disamakan.
     *
     * Sengaja sesempit itu. "x terbuka - 2" yang diketik tata usaha memang
     * menunjuk "X Terbuka - 2", tetapi "X Terbuka 2" tanpa tanda hubung tidak
     * dianggap sama — dan pesan penolakannya menyebutkan daftar rombel yang
     * ada, sehingga ejaan yang benar terbaca langsung tanpa perlu ditebak
     * program (butir 576).
     */
    public static function classKey(string $label): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/u', ' ', trim($label)));
    }

    /**
     * Judul kolom yang sudah dinormalkan — dipakai untuk mendeteksi **dan** untuk
     * membaca barisnya, sehingga keduanya tidak mungkin berbeda pendapat.
     *
     * Normalisasinya sengaja sempit: buang BOM UTF-8, rapikan spasi, huruf
     * kecilkan. Kolom yang benar-benar diganti namanya tetap ditolak.
     *
     * @param  array<string, mixed>  $row
     * @return array<int, string>
     */
    protected function headingKeys(array $row): array
    {
        return array_map(fn ($key): string => $this->headingKey($key), array_keys($row));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function rekey(array $row): array
    {
        $out = [];

        foreach ($row as $key => $value) {
            $out[$this->headingKey($key)] = $value;
        }

        return $out;
    }

    protected function headingKey(mixed $key): string
    {
        $key = str_replace("\u{FEFF}", '', (string) $key);

        return mb_strtolower(trim($key));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function normalise(array $row): array
    {
        $gender = strtoupper(trim((string) ($row['jenis_kelamin'] ?? '')));
        $status = strtoupper(trim((string) ($row['status'] ?? '')));

        $nisn = NisnNormalizer::normalise($row['nisn'] ?? null);

        return [
            'nis' => $this->value($row, 'nis'),
            // Angka nol di depan NISN hilang karena Excel menyimpan kolom
            // identitas sebagai bilangan. Keputusan pemilik: dikembalikan saat
            // impor, bukan dengan meminta tata usaha mengetik ulang (butir 483).
            'nisn' => $nisn['value'],
            'nisn_state' => $nisn['state'],
            'full_name' => $this->value($row, 'nama_lengkap'),
            'gender' => $gender === '' ? null : $gender,
            'birth_place' => $this->value($row, 'tempat_lahir'),
            'birth_date' => $this->date($row, 'tanggal_lahir'),
            'religion' => $this->value($row, 'agama'),
            'address' => $this->value($row, 'alamat'),
            'parent_name' => $this->value($row, 'nama_orang_tua'),
            'parent_phone' => $this->value($row, 'hp_orang_tua'),
            'parent_email' => $this->value($row, 'email_orang_tua'),
            'entry_year' => $this->value($row, 'tahun_masuk'),
            'status' => $status === '' ? StudentStatus::Active->value : $status,
            'class_label' => $this->value($row, 'kelas'),
        ];
    }

    /**
     * Sel tanggal di .xlsx terbaca sebagai serial number PhpSpreadsheet,
     * sehingga perlu dikonversi sebelum divalidasi sebagai tanggal.
     *
     * @param  array<string, mixed>  $row
     */
    protected function date(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
        }

        return trim((string) $value);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function value(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
