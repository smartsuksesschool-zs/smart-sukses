<?php

namespace App\Imports;

use App\Enums\RoleName;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Events\BeforeSheet;

/**
 * PORTAL-04 AC-1 / AC-M0-12 — pembuatan akun guru dan siswa secara massal.
 *
 * Kelas ini **hanya memeriksa dan merencanakan**; ia tidak pernah menulis satu
 * baris pun. Yang menulis `App\Services\Admin\AccountProvisioner`, dalam satu
 * transaksi, dan hanya ketika seluruh berkas bersih.
 *
 * Pemisahan itu bukan kerapian. Importer siswa yang sudah ada memasukkan baris
 * yang sah dan melaporkan sisanya sebagai galat — bentuk yang tepat untuk data
 * induk, sebab 39 siswa yang masuk tetap berguna meski satu baris keliru. Akun
 * tidak begitu: setiap akun lahir bersama **sandi sementara yang hanya terlihat
 * sekali**. Impor yang berhenti di tengah meninggalkan sebagian akun hidup
 * dengan sandi yang tidak tercatat siapa pun, dan percobaan kedua akan
 * menabraknya sebagai "surel sudah dipakai" (butir 594).
 *
 * Struktur kolomnya mengisi kekosongan yang diakui dokumen — `06-API.md:722`
 * menyatakan "Struktur kolom template: **Belum dijelaskan dalam blueprint**" —
 * dan diturunkan dari payload `POST /users` (`name`, `email`, `phone`, `role`;
 * `06-API.md:2352`) serta kolom `users` yang memang ada.
 *
 * **Tidak ada kolom cabang.** Cabang ditentukan di luar berkas: cabang akun
 * Admin Sekolah, atau pilihan Super Admin. Dengan begitu tidak ada baris Excel
 * yang dapat menyeberang tenant — bukan karena ditolak, melainkan karena tidak
 * ada tempat untuk menuliskannya.
 */
class UserAccountsImport implements ToCollection, WithEvents, WithHeadingRow
{
    public const TEACHER_SHEET = 'Akun Guru';

    public const STUDENT_SHEET = 'Akun Siswa';

    /**
     * Judul kolom lembar guru -> atribut `users`.
     *
     * @var array<string, string>
     */
    public const TEACHER_COLUMNS = [
        'nama' => 'name',
        'email' => 'email',
        'peran' => 'role',
        'hp' => 'phone',
        'bahasa' => 'locale',
    ];

    /**
     * Lembar siswa tidak memuat nama: namanya diambil dari baris siswa yang
     * ditunjuk NIS, sehingga akun dan data induk tidak dapat berbeda nama.
     *
     * @var array<string, string>
     */
    public const STUDENT_COLUMNS = [
        'nis' => 'nis',
        'email' => 'email',
        'hp' => 'phone',
        'bahasa' => 'locale',
    ];

    /** @var array<int, string> */
    public const TEACHER_REQUIRED = ['nama', 'email', 'peran'];

    /** @var array<int, string> */
    public const STUDENT_REQUIRED = ['nis', 'email'];

    /**
     * Peran yang boleh lahir dari lembar guru.
     *
     * ASM-11 (`01-PRD.md:246`) menyatakan guru direpresentasikan sebagai `users`
     * berperan `GURU`/`WALI_KELAS`, bukan entitas tersendiri — jadi keduanya
     * adalah "guru" yang dimaksud AC-1, dan membatasi ke salah satunya akan
     * memaksa wali kelas dibuat satu per satu.
     *
     * @var array<int, string>
     */
    public const TEACHER_ROLES = [
        RoleName::Guru->value,
        RoleName::WaliKelas->value,
    ];

    /** @var array<int, string> */
    public const LOCALES = ['id', 'en'];

    /**
     * Rencana yang siap ditulis. Kosong selama masih ada galat.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $planned = [];

    /** @var array<int, string> */
    public array $errors = [];

    /**
     * Catatan per lembar: terbaca atau tidak, dan kolom wajib yang hilang.
     *
     * Maatwebsite memanggil `collection()` sekali untuk **setiap** lembar, bukan
     * sekali untuk setiap berkas (butir 504).
     *
     * @var array<string, array{rows: int, missing: array<int, string>}>
     */
    public array $sheets = [];

    protected string $currentSheet = '';

    /** @var array<string, int> surel yang sudah dipakai di berkas ini -> baris */
    protected array $seenEmails = [];

    /** @var array<string, int> NIS yang sudah dipakai di berkas ini -> baris */
    protected array $seenNis = [];

    public function __construct(protected int $schoolId) {}

    /**
     * @return array<class-string, callable>
     */
    public function registerEvents(): array
    {
        return [
            BeforeSheet::class => function (BeforeSheet $event): void {
                $this->currentSheet = trim((string) $event->getSheet()->getTitle());
            },
        ];
    }

    public function collection(Collection $rows): void
    {
        $sheet = $this->currentSheet;

        if (! in_array($sheet, [self::TEACHER_SHEET, self::STUDENT_SHEET], true)) {
            return;
        }

        $isTeacher = $sheet === self::TEACHER_SHEET;
        $required = $isTeacher ? self::TEACHER_REQUIRED : self::STUDENT_REQUIRED;

        $headings = $rows->first() instanceof Collection
            ? array_keys($rows->first()->all())
            : array_keys((array) ($rows->first() ?? []));

        $missing = array_values(array_diff($required, $headings));

        $this->sheets[$sheet] = ['rows' => $rows->count(), 'missing' => $missing];

        if ($rows->isEmpty()) {
            return;
        }

        if ($missing !== []) {
            $this->errors[] = __('Lembar :sheet kehilangan kolom wajib: :columns.', [
                'sheet' => $sheet,
                'columns' => implode(', ', $missing),
            ]);

            return;
        }

        foreach ($rows as $index => $row) {
            // Baris 1 adalah judul kolom, sehingga data dimulai pada baris 2.
            $line = $index + 2;
            $data = $row instanceof Collection ? $row->all() : (array) $row;

            $isTeacher
                ? $this->planTeacher($data, $line, $sheet)
                : $this->planStudent($data, $line, $sheet);
        }
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function matchedAnySheet(): bool
    {
        return $this->sheets !== [];
    }

    // ================================================== lembar guru

    /**
     * @param  array<string, mixed>  $data
     */
    protected function planTeacher(array $data, int $line, string $sheet): void
    {
        if ($this->isBlankRow($data, self::TEACHER_REQUIRED)) {
            return;
        }

        $email = $this->normaliseEmail($data['email'] ?? null);
        $role = strtoupper(trim((string) ($data['peran'] ?? '')));

        $validator = Validator::make([
            'nama' => trim((string) ($data['nama'] ?? '')),
            'email' => $email,
            'peran' => $role,
            'hp' => $this->optional($data['hp'] ?? null),
            'bahasa' => $this->locale($data['bahasa'] ?? null),
        ], [
            'nama' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150'],
            'peran' => ['required', Rule::in(self::TEACHER_ROLES)],
            'hp' => ['nullable', 'string', 'max:20'],
            'bahasa' => ['required', Rule::in(self::LOCALES)],
        ], [
            'peran.in' => __('kolom peran harus salah satu dari :values.', [
                'values' => implode(', ', self::TEACHER_ROLES),
            ]),
        ]);

        if ($validator->fails()) {
            $this->fail($sheet, $line, implode(' ', $validator->errors()->all()));

            return;
        }

        if (! $this->emailIsFree($email, $sheet, $line)) {
            return;
        }

        $this->planned[] = [
            'sheet' => $sheet,
            'line' => $line,
            'role' => $role,
            'name' => trim((string) $data['nama']),
            'email' => $email,
            'phone' => $this->optional($data['hp'] ?? null),
            'locale' => $this->locale($data['bahasa'] ?? null),
            'student_id' => null,
        ];
    }

    // ================================================== lembar siswa

    /**
     * @param  array<string, mixed>  $data
     */
    protected function planStudent(array $data, int $line, string $sheet): void
    {
        if ($this->isBlankRow($data, self::STUDENT_REQUIRED)) {
            return;
        }

        $email = $this->normaliseEmail($data['email'] ?? null);
        $nis = trim((string) ($data['nis'] ?? ''));

        $validator = Validator::make([
            'nis' => $nis,
            'email' => $email,
            'hp' => $this->optional($data['hp'] ?? null),
            'bahasa' => $this->locale($data['bahasa'] ?? null),
        ], [
            'nis' => ['required', 'string', 'max:20'],
            'email' => ['required', 'email', 'max:150'],
            'hp' => ['nullable', 'string', 'max:20'],
            'bahasa' => ['required', Rule::in(self::LOCALES)],
        ]);

        if ($validator->fails()) {
            $this->fail($sheet, $line, implode(' ', $validator->errors()->all()));

            return;
        }

        if (isset($this->seenNis[$nis])) {
            $this->fail($sheet, $line, __('NIS :nis sudah dipakai baris :first pada berkas yang sama.', [
                'nis' => $nis,
                'first' => $this->seenNis[$nis],
            ]));

            return;
        }

        /*
         * Siswanya harus **sudah ada**. Importer ini tidak membuat baris siswa:
         * `StudentsImport` pemiliknya, dan dua tempat yang dapat melahirkan
         * siswa berarti dua sumber kebenaran untuk NIS.
         *
         * `withTrashed()` dipakai untuk **membedakan** siswa terarsip dari siswa
         * yang memang tidak ada — keduanya menghasilkan kalimat yang berbeda,
         * sebab yang pertama dapat dipulihkan (butir 594).
         */
        $student = Student::query()
            ->withTrashed()
            ->where('school_id', $this->schoolId)
            ->where('nis', $nis)
            ->first();

        if ($student === null) {
            $this->fail($sheet, $line, __('NIS :nis tidak ditemukan di cabang ini.', ['nis' => $nis]));

            return;
        }

        if ($student->trashed()) {
            $this->fail($sheet, $line, __('Siswa ber-NIS :nis sudah diarsipkan. Pulihkan lebih dahulu.', ['nis' => $nis]));

            return;
        }

        if ($student->user_id !== null) {
            $this->fail($sheet, $line, __('Siswa ber-NIS :nis sudah memiliki akun portal.', ['nis' => $nis]));

            return;
        }

        if (! $this->emailIsFree($email, $sheet, $line)) {
            return;
        }

        $this->seenNis[$nis] = $line;

        $this->planned[] = [
            'sheet' => $sheet,
            'line' => $line,
            'role' => RoleName::Siswa->value,
            // Nama akun mengikuti data induk, bukan kolom berkas: akun dan baris
            // siswa tidak boleh berbeda nama.
            'name' => (string) $student->full_name,
            'email' => $email,
            'phone' => $this->optional($data['hp'] ?? null),
            'locale' => $this->locale($data['bahasa'] ?? null),
            'student_id' => (int) $student->getKey(),
        ];
    }

    // ================================================== perkakas

    /**
     * Surel unik **lintas seluruh platform**, bukan per cabang (ASM-12), jadi
     * pemeriksaannya melepas scope tenant dengan sengaja.
     */
    protected function emailIsFree(string $email, string $sheet, int $line): bool
    {
        if (isset($this->seenEmails[$email])) {
            $this->fail($sheet, $line, __('Surel sudah dipakai baris :first pada berkas yang sama.', [
                'first' => $this->seenEmails[$email],
            ]));

            return false;
        }

        $taken = User::query()
            ->withoutGlobalScopes()
            ->where('email', $email)
            ->exists();

        if ($taken) {
            $this->fail($sheet, $line, __('Surel sudah dipakai akun lain.'));

            return false;
        }

        $this->seenEmails[$email] = $line;

        return true;
    }

    protected function fail(string $sheet, int $line, string $message): void
    {
        $this->errors[] = __('Lembar :sheet baris :line: :message', [
            'sheet' => $sheet,
            'line' => $line,
            'message' => $message,
        ]);
    }

    /**
     * Baris yang seluruh kolom wajibnya kosong adalah baris sisa, bukan galat.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $required
     */
    protected function isBlankRow(array $data, array $required): bool
    {
        foreach ($required as $column) {
            if (filled($data[$column] ?? null)) {
                return false;
            }
        }

        return true;
    }

    protected function normaliseEmail(mixed $value): string
    {
        return strtolower(trim((string) $value));
    }

    protected function optional(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    protected function locale(mixed $value): string
    {
        $value = strtolower(trim((string) $value));

        return $value === '' ? 'id' : $value;
    }
}
