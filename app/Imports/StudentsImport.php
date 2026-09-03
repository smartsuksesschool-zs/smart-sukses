<?php

namespace App\Imports;

use App\Enums\Gender;
use App\Enums\StudentStatus;
use App\Models\Student;
use App\Support\Migration\NisnNormalizer;
use Illuminate\Support\Collection;
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

            Student::create($validator->validated() + ['school_id' => $this->schoolId]);

            $this->imported++;
        }
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
