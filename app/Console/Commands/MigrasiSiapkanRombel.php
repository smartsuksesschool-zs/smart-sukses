<?php

namespace App\Console\Commands;

use App\Models\AcademicYear;
use App\Models\School;
use App\Models\SchoolClass;
use App\Support\Migration\CanonicalRombel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * M4 — menyiapkan rombel resmi satu tahun ajaran.
 *
 * Dibuat sebagai perintah tersendiri, **bukan** sebagai efek samping importer
 * siswa. Importer yang membuat rombelnya sendiri akan menuliskan nama rombel
 * yang belum pernah dinyatakan sekolah, dan kekeliruannya baru terlihat setelah
 * ada nilai serta rapor yang menggantung padanya (butir 490, dipertahankan).
 *
 * Bentuknya mengikuti keluarga `migrasi:` yang sudah ada — `--school` dan
 * `--tahun-ajaran` yang sama, mode kering sebagai bawaan, dan `--konfirmasi`
 * untuk menulis. Yang membedakannya dari `migrasi:terapkan-uji`: perintah ini
 * **tidak** dipagari basis data uji.
 *
 * Alasannya jelas dan disengaja. Yang ditulis di sini bukan data pribadi
 * siapa pun, melainkan empat baris struktur yang memang harus ada di produksi
 * juga. Memagarinya ke basis data uji akan membuat sekolah tidak punya jalan
 * resmi menyiapkan rombelnya sendiri, dan jalan yang tidak resmi adalah
 * mengedit baris basis data dengan tangan (butir 515).
 *
 * Kode keluar: 0 selesai, 1 ada yang perlu diputuskan, 2 gagal dijalankan.
 */
class MigrasiSiapkanRombel extends Command
{
    protected $signature = 'migrasi:siapkan-rombel
        {--school=PUSAT : Kode cabang, bukan id numerik}
        {--tahun-ajaran= : Nama tahun ajaran tujuan; bawaan: yang sedang aktif}
        {--konfirmasi : Tulis rombel yang belum ada. Tanpa ini perintah hanya mencetak rencana.}';

    protected $description = 'Menyiapkan rombel resmi satu tahun ajaran. Tidak pernah menyentuh data siswa.';

    public function handle(): int
    {
        $write = (bool) $this->option('konfirmasi');

        if (! $write) {
            $this->components->warn('MODE KERING — tidak ada rombel yang dibuat. Tambahkan --konfirmasi untuk menulis.');
        }

        $school = School::query()->where('code', $this->option('school'))->first();

        if ($school === null) {
            $this->components->error('Kode cabang tidak ditemukan: '.$this->option('school'));

            return 2;
        }

        $year = $this->resolveYear($school);

        if ($year === null) {
            return 2;
        }

        // Sasaran ditampilkan lengkap sebelum apa pun ditulis. Perintah ini
        // sengaja dapat berjalan di luar basis data uji (butir 515), jadi yang
        // menggantikan pagar itu adalah kejelasan: siapa pun yang menekan
        // enter harus lebih dulu melihat lingkungan, cabang, tahun ajaran, dan
        // keempat nama rombel yang akan dibuat (butir 518).
        $this->line('');
        $this->components->twoColumnDetail('<fg=gray>Lingkungan</>', $this->environmentLabel());
        $this->components->twoColumnDetail('<fg=gray>Basis data</>', DB::connection()->getDatabaseName() ?? '-');
        $this->components->twoColumnDetail('<fg=gray>Cabang</>', $school->code.' — '.$school->name);
        $this->components->twoColumnDetail(
            '<fg=gray>Tahun ajaran</>',
            $year->name.' (semester '.($year->semester ?? '?').')',
        );
        $this->components->twoColumnDetail(
            '<fg=gray>Rombel yang dikelola</>',
            implode(', ', CanonicalRombel::names()),
        );

        $this->line('');
        $this->components->info('ROMBEL RESMI');

        $created = 0;
        $existing = 0;
        $mismatched = [];
        $ambiguous = [];

        foreach (CanonicalRombel::CLASSES as $name => $grade) {
            // Pencocokan pada (cabang, tahun ajaran, nama) — kunci alami sebuah
            // rombel. `classes` tidak punya unique index untuk itu, sehingga
            // idempotensi ditegakkan di sini, bukan diandalkan pada basis data
            // (butir 516).
            $matches = SchoolClass::query()
                ->where('school_id', $school->id)
                ->where('academic_year_id', $year->id)
                ->where('name', $name)
                ->orderBy('id')
                ->get();

            // Dua rombel bernama sama bukan keadaan sehat, dan melaporkannya
            // sebagai "sudah ada" akan menyembunyikan justru masalah yang
            // paling merugikan: impor siswa menolak menempatkan siapa pun ke
            // label itu, dan operator tidak tahu sebabnya (butir 517).
            //
            // Tidak diperbaiki sendiri. Menggabungkan, menghapus, atau
            // mengganti nama rombel yang mungkin sudah punya siswa, nilai, dan
            // rapor adalah keputusan manusia.
            if ($matches->count() > 1) {
                $ambiguous[] = "{$name} ({$matches->count()} baris)";
                $this->components->twoColumnDetail(
                    "{$name}  <fg=gray>tingkat {$grade}</>",
                    "<fg=red>GANDA — {$matches->count()} rombel bernama sama</>",
                );

                continue;
            }

            $class = $matches->first();

            if ($class !== null) {
                $existing++;

                // Tingkat yang berbeda dari daftar resmi dilaporkan, tidak
                // diperbaiki diam-diam: rombel yang sudah dipakai mungkin punya
                // sejarah yang tidak terlihat dari sini.
                if ((int) $class->grade_level !== $grade) {
                    $mismatched[] = "{$name} (tingkat {$class->grade_level}, seharusnya {$grade})";
                    $this->components->twoColumnDetail(
                        "{$name}  <fg=gray>tingkat {$grade}</>",
                        "<fg=red>ADA, tetapi tingkatnya {$class->grade_level}</>",
                    );

                    continue;
                }

                $this->components->twoColumnDetail("{$name}  <fg=gray>tingkat {$grade}</>", '<fg=gray>sudah ada</>');

                continue;
            }

            if (! $write) {
                $created++;
                $this->components->twoColumnDetail("{$name}  <fg=gray>tingkat {$grade}</>", '<fg=yellow>akan dibuat</>');

                continue;
            }

            SchoolClass::query()->create([
                'school_id' => $school->id,
                'academic_year_id' => $year->id,
                'name' => $name,
                'grade_level' => $grade,
                // Wali kelas sengaja dikosongkan. Indeks unik
                // (academic_year_id, homeroom_teacher_id) mengizinkan banyak
                // NULL, dan menebak wali kelas berarti mengarang penugasan
                // orang.
                'homeroom_teacher_id' => null,
            ]);

            $created++;
            $this->components->twoColumnDetail("{$name}  <fg=gray>tingkat {$grade}</>", '<fg=green>dibuat</>');
        }

        $this->line('');
        $this->components->info('RINGKASAN');
        $this->components->twoColumnDetail('rombel resmi', (string) count(CanonicalRombel::CLASSES));
        $this->components->twoColumnDetail($write ? 'dibuat' : 'akan dibuat', (string) $created);
        $this->components->twoColumnDetail('sudah ada', (string) $existing);
        $this->components->twoColumnDetail('tingkat tidak cocok', (string) count($mismatched));
        $this->components->twoColumnDetail('nama ganda', (string) count($ambiguous));

        $this->line('');
        $this->components->twoColumnDetail(
            'siswa disentuh',
            '<fg=green>0 — perintah ini tidak pernah menulis siswa, penempatan, atau akun</>',
        );

        if ($ambiguous !== []) {
            $this->line('');
            $this->components->error(
                'Rombel berikut punya lebih dari satu baris bernama sama pada cabang dan tahun '
                .'ajaran ini. Tidak satu pun diubah, dan impor siswa TIDAK akan menempatkan '
                .'siapa pun ke label tersebut sampai duplikatnya dibereskan lewat panel:'
            );
            $this->components->bulletList($ambiguous);
        }

        if ($mismatched !== []) {
            $this->line('');
            $this->components->error('Tingkat rombel berikut berbeda dari daftar resmi dan TIDAK diubah:');
            $this->components->bulletList($mismatched);
        }

        return $ambiguous === [] && $mismatched === [] ? 0 : 1;
    }

    /**
     * Nama lingkungan, ditandai bila ia bukan mesin pengembang.
     *
     * Tidak pernah memuat kredensial maupun data pribadi — hanya nama
     * lingkungan yang sudah tertulis di `.env`.
     */
    protected function environmentLabel(): string
    {
        $env = app()->environment();

        return app()->environment(['local', 'testing'])
            ? $env
            : "<fg=yellow>{$env}</> — periksa sekali lagi sebelum menulis";
    }

    /**
     * Tahun ajaran tujuan. Tidak pernah dibuat perintah ini, dan tidak pernah
     * ditebak: tahun ajaran yang salah akan menempatkan seluruh siswa di tahun
     * yang keliru, dan itu baru terlihat pada rapor.
     */
    protected function resolveYear(School $school): ?AcademicYear
    {
        $name = trim((string) $this->option('tahun-ajaran'));

        if ($name === '') {
            $year = AcademicYear::query()
                ->where('school_id', $school->id)
                ->where('is_active', true)
                ->first();

            if ($year === null) {
                $this->components->error(
                    'Tidak ada tahun ajaran aktif di cabang ini. Buat tahun ajarannya lebih dulu '
                    .'lewat panel admin, atau sebut namanya dengan --tahun-ajaran.'
                );
            }

            return $year;
        }

        $matches = AcademicYear::query()
            ->where('school_id', $school->id)
            ->where('name', $name)
            ->get();

        if ($matches->count() > 1) {
            $this->components->error("Tahun ajaran \"{$name}\" ganda di cabang ini; perbaiki datanya lebih dulu.");

            return null;
        }

        if ($matches->isEmpty()) {
            $this->components->error(
                "Tahun ajaran \"{$name}\" tidak ada di cabang ini. Perintah ini tidak membuat tahun ajaran."
            );

            return null;
        }

        return $matches->first();
    }
}
