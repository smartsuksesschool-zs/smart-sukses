<?php

namespace App\Support\Migration;

use App\Enums\StudentClassStatus;
use App\Models\AcademicYear;
use App\Models\PpdbRegistration;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentClass;

/**
 * M3 — rencana impor siswa: satu keputusan per baris sumber, tanpa menulis.
 *
 * Kelas ini adalah **satu-satunya** tempat baris sumber dinilai. Analisis
 * kering dan mode terap membaca rencana yang sama, sehingga yang diperagakan
 * `migrasi:dry-run` persis yang dikerjakan `migrasi:terapkan-uji` — tidak ada
 * dua pemahaman terpisah atas berkas yang sama (butir 486).
 *
 * Kontrak sebagian (butir 487). Analisis M2 menolak seluruh berkas begitu ada
 * satu penghambat. Untuk 40 siswa yang 39-nya siap, sikap itu memaksa memilih
 * antara menunda semuanya atau mengarang NIS bagi yang satu. Keduanya salah,
 * jadi setiap baris dinilai sendiri-sendiri: baris yang tertunda tidak pernah
 * menghalangi baris yang siap, dan tidak satu pun identitas dikarang.
 *
 * Rekonsiliasi selalu tertutup:
 *
 *     baris sumber = siap + tertunda + ditolak
 *
 * Semua query membawa `school_id` eksplisit — perintah artisan berjalan tanpa
 * sesi sehingga SchoolScope tidak aktif.
 */
class StudentImportPlan
{
    public const READY_CREATE = 'READY_CREATE';

    public const READY_MATCH = 'READY_MATCH';

    public const PENDING_MISSING_NIS = 'PENDING_MISSING_NIS';

    public const PENDING_INVALID_NISN = 'PENDING_INVALID_NISN';

    public const PENDING_DUPLICATE_NIS = 'PENDING_DUPLICATE_NIS';

    public const PENDING_DUPLICATE_NISN = 'PENDING_DUPLICATE_NISN';

    public const PENDING_PPDB_RECONCILIATION = 'PPDB_RECONCILIATION_REQUIRED';

    public const REJECTED_MASTER_INCOMPLETE = 'REJECTED_MASTER_INCOMPLETE';

    public const PLACEMENT_READY = 'PLACEMENT_READY';

    public const PLACEMENT_EXISTS = 'PLACEMENT_EXISTS';

    public const CLASS_NOT_FOUND = 'CLASS_NOT_FOUND';

    public const CLASS_AMBIGUOUS = 'CLASS_AMBIGUOUS';

    public const CLASS_LABEL_MISSING = 'CLASS_LABEL_MISSING';

    public const ACADEMIC_YEAR_MISSING = 'ACADEMIC_YEAR_MISSING';

    /**
     * Hasil yang berarti baris boleh ditulis.
     *
     * @var array<int, string>
     */
    public const WRITABLE = [self::READY_CREATE, self::READY_MATCH];

    /**
     * Penanda "tidak ada isinya" pada kolom identitas.
     *
     * @var array<int, string>
     */
    protected const BLANK_TOKENS = ['', '-', '--', '–', '—', 'n/a', 'na', 'null', 'kosong', 'belum ada'];

    /**
     * Tingkat yang berpotensi beririsan dengan pendaftar PPDB.
     */
    protected const PPDB_GRADE = 10;

    /**
     * Label kelas -> **seluruh** id yang cocok, bukan satu id.
     *
     * Menyimpan satu id akan menyembunyikan keadaan yang justru paling
     * berbahaya: dua rombel bernama sama pada cabang dan tahun ajaran yang
     * sama. Satu label dipakai berkali-kali dalam satu berkas, dan jawabannya
     * tidak berubah di tengah satu rencana (butir 517).
     *
     * @var array<string, array<int, int>>
     */
    protected array $classCache = [];

    public function __construct(
        protected School $school,
        protected ?AcademicYear $year = null,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function build(array $rows): array
    {
        $plan = [];
        $seenNis = [];
        $seenNisn = [];
        $nisn = NisnNormalizer::emptyTally();
        $classes = [];

        foreach ($rows as $row) {
            $decision = $this->decide($row, $seenNis, $seenNisn);

            $nisn[$decision['nisn_state']]++;

            if ($decision['nis'] !== null && ! isset($seenNis[$decision['nis']])) {
                $seenNis[$decision['nis']] = $decision['line'];
            }

            if ($decision['nisn'] !== null && ! isset($seenNisn[$decision['nisn']])) {
                $seenNisn[$decision['nisn']] = $decision['line'];
            }

            $label = $decision['class_label'];

            if ($label !== '') {
                $matches = $this->matchingClassIds($label);

                $classes[$label] ??= [
                    'label' => $label,
                    'source_label' => $decision['class_label_source'],
                    'corrected' => $decision['class_label_source'] !== $label,
                    'students' => 0,
                    'grade_level' => self::gradeLevel($label),
                    'class_id' => count($matches) === 1 ? $matches[0] : null,
                    'matches' => count($matches),
                ];
                $classes[$label]['students']++;
            }

            $plan[] = $decision;
        }

        ksort($classes);

        return [
            'rows' => $plan,
            'nisn' => $nisn,
            'classes' => array_values($classes),
            'outcomes' => $this->tally($plan, 'outcome'),
            'placements' => $this->tally(
                array_filter($plan, fn (array $r): bool => in_array($r['outcome'], self::WRITABLE, true)),
                'placement',
            ),
            'reconciliation' => $this->reconcile($plan),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, int>  $seenNis
     * @param  array<string, int>  $seenNisn
     * @return array<string, mixed>
     */
    protected function decide(array $row, array $seenNis, array $seenNisn): array
    {
        $name = $this->text($row['full_name'] ?? null);
        $gender = $this->gender($this->text($row['gender'] ?? null));
        $nis = $this->identifier($row['nis'] ?? null);
        $sourceLabel = $this->identifier($row['class_label'] ?? null) ?? '';
        $label = self::canonicalClassLabel($sourceLabel);
        $nisn = NisnNormalizer::normalise($row['nisn'] ?? null);

        $decision = [
            'sheet' => $row['sheet'] ?? null,
            'line' => $row['source_line'] ?? 0,
            'nis' => $nis,
            'nisn' => $nisn['value'],
            'nisn_state' => $nisn['state'],
            'full_name' => $name,
            'gender' => $gender,
            'class_label' => $label,
            'class_label_source' => $sourceLabel,
            'reasons' => [],
            'student_id' => null,
        ];

        // 1. Nama dan jenis kelamin NOT NULL di `students` dan tidak dapat
        //    disimpulkan dari kolom lain mana pun. Tanpa keduanya tidak ada
        //    baris yang bisa ditulis sama sekali.
        $absent = [];

        if ($name === '') {
            $absent[] = 'full_name';
        }

        if ($gender === '') {
            $absent[] = 'gender';
        }

        if ($absent !== []) {
            return $this->outcome($decision, self::REJECTED_MASTER_INCOMPLETE, $absent);
        }

        // 2. NIS adalah identitas migrasi. Yang belum punya NIS resmi menunggu
        //    NIS-nya terbit — ia tidak diberi identitas sementara, karena
        //    identitas sementara akan menjadi identitas tetap begitu ada nilai,
        //    tagihan, dan rapor yang menggantung padanya (butir 488).
        if ($nis === null) {
            return $this->outcome($decision, self::PENDING_MISSING_NIS);
        }

        if (isset($seenNis[$nis])) {
            return $this->outcome($decision, self::PENDING_DUPLICATE_NIS, ['baris '.$seenNis[$nis]]);
        }

        // 3. NISN yang tidak berbentuk digit, atau lebih panjang dari 10 digit,
        //    tidak boleh dipotong maupun dibuang diam-diam. Barisnya ditahan
        //    supaya nilainya diperiksa manusia lebih dulu.
        if ($nisn['state'] === NisnNormalizer::INVALID) {
            return $this->outcome($decision, self::PENDING_INVALID_NISN);
        }

        // Normalisasi dapat mempertemukan dua nilai yang semula berbeda:
        // "12345678" dan "0012345678" menjadi NISN yang sama. Dua siswa dengan
        // NISN sama adalah kesalahan data, dan menormalkannya diam-diam justru
        // menyembunyikan kesalahan itu. Baris keduanya ditahan (butir 503).
        if ($nisn['value'] !== null && isset($seenNisn[$nisn['value']])) {
            return $this->outcome($decision, self::PENDING_DUPLICATE_NISN, ['baris '.$seenNisn[$nisn['value']]]);
        }

        $existing = Student::query()
            ->where('school_id', $this->school->id)
            ->where('nis', $nis)
            ->first();

        if ($existing !== null) {
            $decision['student_id'] = $existing->id;

            return $this->outcome($decision, self::READY_MATCH);
        }

        // 4. Tingkat X beririsan dengan pendaftar PPDB. `ppdb_registrations`
        //    tidak menyimpan NIS maupun NISN, jadi tidak ada satu pun kunci
        //    aman untuk mencocokkannya; nama saja tidak pernah dipakai sebagai
        //    identitas. Yang dilakukan hanya sebaliknya: nama yang sama
        //    **menahan** barisnya untuk diperiksa manusia, tidak pernah
        //    menggabungkannya (butir 489).
        if (self::gradeLevel($label) === self::PPDB_GRADE && $this->ppdbNameOverlap($name)) {
            return $this->outcome($decision, self::PENDING_PPDB_RECONCILIATION);
        }

        return $this->outcome($decision, self::READY_CREATE);
    }

    /**
     * @param  array<string, mixed>  $decision
     * @param  array<int, string>  $reasons
     * @return array<string, mixed>
     */
    protected function outcome(array $decision, string $outcome, array $reasons = []): array
    {
        $decision['outcome'] = $outcome;
        $decision['reasons'] = $reasons;
        $decision['placement'] = in_array($outcome, self::WRITABLE, true)
            ? $this->placement($decision)
            : null;
        $decision['class_id'] = $decision['placement'] === self::PLACEMENT_READY
            ? $this->resolveClassId($decision['class_label'])
            : null;

        return $decision;
    }

    /**
     * Penempatan kelas dinilai terpisah dari data induk. Induk siswa yang sudah
     * benar tidak ditahan hanya karena rombelnya belum dibuat — dan sebaliknya,
     * rombel tidak pernah dibuat sendiri oleh importer (butir 490).
     *
     * @param  array<string, mixed>  $decision
     */
    protected function placement(array $decision): string
    {
        if ($decision['class_label'] === '') {
            return self::CLASS_LABEL_MISSING;
        }

        if ($this->year === null) {
            return self::ACADEMIC_YEAR_MISSING;
        }

        $matches = $this->matchingClassIds($decision['class_label']);

        if ($matches === []) {
            return self::CLASS_NOT_FOUND;
        }

        // Dua rombel bernama sama adalah data yang keliru, dan importer bukan
        // tempat memutuskan mana yang benar. Memilih salah satunya menempatkan
        // siswa di rombel yang belum tentu tujuannya, tanpa galat apa pun yang
        // menandainya (butir 517).
        if (count($matches) > 1) {
            return self::CLASS_AMBIGUOUS;
        }

        if ($decision['student_id'] === null) {
            return self::PLACEMENT_READY;
        }

        $already = StudentClass::query()
            ->where('school_id', $this->school->id)
            ->where('student_id', $decision['student_id'])
            ->where('academic_year_id', $this->year->id)
            ->where('status', StudentClassStatus::Active->value)
            ->exists();

        return $already ? self::PLACEMENT_EXISTS : self::PLACEMENT_READY;
    }

    /**
     * Seluruh rombel yang namanya **persis** sama, pada cabang dan tahun ajaran
     * tujuan.
     *
     * Batas pencariannya sengaja bertiga: cabang, tahun ajaran, dan nama
     * kanonis yang persis. Label sumber berasal dari kolom "Kelas di SMAN 11"
     * milik sekolah mitra; menebak padanannya di `classes` akan mengarang
     * rombel yang belum pernah dinyatakan sekolah.
     *
     * @return array<int, int>
     */
    protected function matchingClassIds(string $label): array
    {
        if ($label === '' || $this->year === null) {
            return [];
        }

        return $this->classCache[$label] ??= SchoolClass::query()
            ->where('school_id', $this->school->id)
            ->where('academic_year_id', $this->year->id)
            ->where('name', $label)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();
    }

    /**
     * Id rombel tujuan, **hanya** bila jawabannya tunggal.
     *
     * Sengaja mengembalikan null pada nol maupun lebih dari satu kecocokan:
     * pemanggilnya tidak boleh dapat membedakan "belum ada" dari "ganda" lewat
     * nilai ini, karena keduanya sama-sama berarti tidak ada yang boleh
     * ditulis. Yang membedakan keduanya untuk laporan adalah `placement()`.
     */
    protected function resolveClassId(string $label): ?int
    {
        $matches = $this->matchingClassIds($label);

        return count($matches) === 1 ? $matches[0] : null;
    }

    protected function ppdbNameOverlap(string $name): bool
    {
        $fold = mb_strtolower($name);

        if ($fold === '') {
            return false;
        }

        return PpdbRegistration::query()
            ->withoutGlobalScopes()
            ->where('school_id', $this->school->id)
            ->whereNull('converted_student_id')
            ->get(['full_name'])
            ->contains(fn (PpdbRegistration $r): bool => mb_strtolower(trim($r->full_name)) === $fold);
    }

    /**
     * @param  array<int, array<string, mixed>>  $plan
     * @return array<string, int>
     */
    protected function tally(array $plan, string $key): array
    {
        $out = [];

        foreach ($plan as $row) {
            if ($row[$key] === null) {
                continue;
            }

            $out[$row[$key]] = ($out[$row[$key]] ?? 0) + 1;
        }

        ksort($out);

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $plan
     * @return array<string, int>
     */
    protected function reconcile(array $plan): array
    {
        $ready = 0;
        $pending = 0;
        $rejected = 0;

        foreach ($plan as $row) {
            match (true) {
                in_array($row['outcome'], self::WRITABLE, true) => $ready++,
                $row['outcome'] === self::REJECTED_MASTER_INCOMPLETE => $rejected++,
                default => $pending++,
            };
        }

        return [
            'source' => count($plan),
            'ready' => $ready,
            'pending' => $pending,
            'rejected' => $rejected,
            'balanced' => count($plan) === $ready + $pending + $rejected,
        ];
    }

    /**
     * "X Terbuka - 2" -> 10. Yang dibaca hanya token tingkat di depan label;
     * sisanya adalah nama rombel milik sekolah dan tidak ditafsirkan.
     */
    public static function gradeLevel(string $label): ?int
    {
        $key = mb_strtoupper(trim($label));
        $key = preg_replace('/^KELAS\s+/', '', $key) ?? $key;

        if (preg_match('/^(XII|XI|X|10|11|12)\b/', $key, $m) !== 1) {
            return null;
        }

        return match ($m[1]) {
            'X', '10' => 10,
            'XI', '11' => 11,
            default => 12,
        };
    }

    /**
     * Label sumber setelah koreksi data terkonfirmasi.
     *
     * Daftarnya ada di CanonicalRombel bersama daftar rombel resmi: rombel mana
     * yang ada dan label mana yang menunjuk ke sana selalu berubah bersama,
     * jadi keduanya tinggal di satu tempat (butir 514).
     */
    public static function canonicalClassLabel(string $label): string
    {
        return CanonicalRombel::canonicalLabel($label);
    }

    protected function identifier(mixed $value): ?string
    {
        $text = $this->text($value);

        return in_array(mb_strtolower($text), self::BLANK_TOKENS, true) ? null : $text;
    }

    protected function text(mixed $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) ($value ?? '')) ?? '');
    }

    protected function gender(string $value): string
    {
        return match (mb_strtoupper(trim($value))) {
            'L', 'LAKI-LAKI', 'LAKI LAKI', 'PRIA' => 'L',
            'P', 'PEREMPUAN', 'WANITA' => 'P',
            default => '',
        };
    }
}
