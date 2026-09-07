<?php

namespace Tests\Feature\Migration;

use App\Models\AcademicYear;
use App\Models\School;
use App\Support\Migration\ImportFingerprint;
use App\Support\Migration\StudentImportPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Baris sumber yang dinyatakan sekolah **bukan siswa terdaftar** (M8).
 *
 * Keadaan yang memunculkannya nyata: berkas sekolah memuat empat puluh baris,
 * tiga puluh sembilan di antaranya siswa resmi, dan satu baris tanpa NIS milik
 * anak yang ikut kegiatan tanpa pernah terdaftar. Menahannya di antrean
 * "tertunda" berarti menyimpan pekerjaan yang tidak akan pernah selesai — dan
 * gerbang impor produksi yang menuntut nol tertunda tidak akan pernah terbuka
 * (butir 559).
 *
 * Seluruh baris pada berkas ini karangan: nama, NIS, dan NISN tidak menunjuk
 * siapa pun. Berkas sekolah yang sungguhan tidak pernah masuk repositori.
 */
class ExcludedSourceRowTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;

    protected AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create(['code' => 'PUSAT', 'is_active' => true]);

        $this->year = AcademicYear::factory()->create([
            'school_id' => $this->school->id,
            'is_active' => true,
        ]);
    }

    public function test_tanpa_pengecualian_baris_tanpa_nis_tetap_tertunda(): void
    {
        // Perilaku sebelum M8, dan tetap menjadi bawaannya: tidak ada baris
        // yang hilang hanya karena NIS-nya kosong.
        $plan = $this->plan($this->rows());

        $this->assertSame(1, $plan['outcomes'][StudentImportPlan::PENDING_MISSING_NIS]);
        $this->assertSame(1, $plan['reconciliation']['pending']);
        $this->assertSame(0, $plan['reconciliation']['excluded']);
        $this->assertArrayNotHasKey(StudentImportPlan::EXCLUDED_NOT_REGISTERED, $plan['outcomes']);
    }

    public function test_baris_yang_dikecualikan_keluar_dari_antrean_tertunda(): void
    {
        $plan = $this->plan($this->rows(), excluded: [3]);

        $this->assertSame(1, $plan['outcomes'][StudentImportPlan::EXCLUDED_NOT_REGISTERED]);
        $this->assertArrayNotHasKey(StudentImportPlan::PENDING_MISSING_NIS, $plan['outcomes']);

        $r = $plan['reconciliation'];

        // Inilah bentuk yang diharapkan operasionalnya: siap penuh, nol
        // tertunda, satu dikecualikan.
        $this->assertSame(3, $r['source']);
        $this->assertSame(2, $r['ready']);
        $this->assertSame(0, $r['pending']);
        $this->assertSame(0, $r['rejected']);
        $this->assertSame(1, $r['excluded']);
        $this->assertTrue($r['balanced']);
    }

    public function test_baris_yang_dikecualikan_tidak_pernah_ikut_ditulis(): void
    {
        $plan = $this->plan($this->rows(), excluded: [3]);

        $excludedRow = collect($plan['rows'])
            ->firstWhere('outcome', StudentImportPlan::EXCLUDED_NOT_REGISTERED);

        $this->assertNotNull($excludedRow);
        $this->assertNotContains(
            StudentImportPlan::EXCLUDED_NOT_REGISTERED,
            StudentImportPlan::WRITABLE,
        );

        // Tanpa penempatan dan tanpa kelas: ia tidak menuju ke mana pun.
        $this->assertNull($excludedRow['placement']);
        $this->assertNull($excludedRow['class_id']);
        $this->assertNull($excludedRow['nis']);
    }

    public function test_baris_ber_nis_tidak_dapat_dikecualikan(): void
    {
        /*
         * Pagar terpenting seluruh perubahan ini. Kalau daftar pengecualian
         * dapat mengeluarkan baris yang punya NIS, ia berubah menjadi cara
         * menghapus siswa resmi dari impor tanpa seorang pun menyadarinya
         * (butir 561).
         */
        $plan = $this->plan($this->rows(), excluded: [1]);

        $this->assertArrayNotHasKey(StudentImportPlan::EXCLUDED_NOT_REGISTERED, $plan['outcomes']);
        $this->assertSame(2, $plan['reconciliation']['ready']);
        $this->assertSame(1, $plan['reconciliation']['pending']);
        $this->assertSame(0, $plan['reconciliation']['excluded']);
    }

    public function test_permintaan_yang_tidak_berlaku_dilaporkan_bukan_didiamkan(): void
    {
        // Nomor yang punya NIS, dan nomor yang tidak ada di berkas sama sekali.
        $plan = $this->plan($this->rows(), excluded: [1, 99]);

        $this->assertEqualsCanonicalizing(['1', '99'], $plan['reconciliation']['excluded_requested']);

        $this->assertSame(
            ['1' => 'tidak berlaku', '99' => 'tidak berlaku'],
            collect($plan['reconciliation']['excluded_ignored'])
                ->pluck('reason', 'locator')
                ->all(),
        );
    }

    public function test_permintaan_yang_berlaku_tidak_ikut_dilaporkan_diabaikan(): void
    {
        $plan = $this->plan($this->rows(), excluded: [3]);

        $this->assertSame(['3'], $plan['reconciliation']['excluded_requested']);
        $this->assertSame([], $plan['reconciliation']['excluded_ignored']);
        $this->assertSame('Siswa:3', $plan['reconciliation']['excluded_signature']);
    }

    public function test_baris_tanpa_nama_tetap_ditolak_bukan_dikecualikan(): void
    {
        // Urutannya penting: data induk yang tidak lengkap adalah penolakan,
        // dan pengecualian tidak boleh menutupinya.
        $rows = $this->rows();
        $rows[] = [
            'sheet' => 'Siswa', 'source_line' => 4, 'nis' => null, 'nisn' => null,
            'full_name' => '', 'gender' => '', 'class_label' => 'X Terbuka - 1',
        ];

        $plan = $this->plan($rows, excluded: [4]);

        $this->assertSame(1, $plan['outcomes'][StudentImportPlan::REJECTED_MASTER_INCOMPLETE]);
        $this->assertSame(1, $plan['reconciliation']['rejected']);
        $this->assertSame(0, $plan['reconciliation']['excluded']);
        $this->assertSame(
            [['locator' => '4', 'reason' => 'tidak berlaku']],
            $plan['reconciliation']['excluded_ignored'],
        );
    }

    public function test_rekonsiliasi_tetap_tertutup_di_setiap_bentuk(): void
    {
        foreach ([[], [3], [1], [1, 3, 99]] as $excluded) {
            $r = $this->plan($this->rows(), excluded: $excluded)['reconciliation'];

            $this->assertTrue($r['balanced'], 'tidak seimbang untuk '.json_encode($excluded));
            $this->assertSame(
                $r['source'],
                $r['ready'] + $r['pending'] + $r['rejected'] + $r['excluded'],
            );
        }
    }

    public function test_daftar_pengecualian_ikut_menentukan_sidik_jari(): void
    {
        /*
         * Sidik jari menghubungkan analisis kering yang ditinjau dengan impor
         * produksi yang diterapkan. Karena rekonsiliasi ikut dihitung, rencana
         * yang ditinjau tanpa pengecualian tidak dapat diterapkan dengan
         * pengecualian — dan sebaliknya (butir 562).
         */
        // Berkas tiruan; isinya tidak dibaca, hanya di-hash.
        $path = tempnam(sys_get_temp_dir(), 'm8').'.xlsx';
        file_put_contents($path, 'berkas tiruan');

        try {
            $tanpa = ImportFingerprint::of($path, $this->school, $this->year, $this->plan($this->rows()));
            $dengan = ImportFingerprint::of($path, $this->school, $this->year, $this->plan($this->rows(), excluded: [3]));

            $this->assertFalse($dengan->matches($tanpa->value));
        } finally {
            @unlink($path);
        }
    }

    // ------------------------------------------------- berkas banyak lembar

    public function test_nomor_baris_tanpa_lembar_ditolak_ketika_ambigu(): void
    {
        /*
         * Berkas sekolah 2026/2027 memakai satu lembar per tingkat (butir 484),
         * dan nomor baris dihitung ulang dari satu di setiap lembar. "Baris 3"
         * karena itu ada di setiap lembar — dan kalau lebih dari satu di
         * antaranya layak, penunjuk tanpa nama lembar tidak boleh mengenai
         * semuanya (butir 566).
         */
        $plan = $this->plan($this->multiSheetRows(), excluded: ['3']);

        $this->assertSame(0, $plan['reconciliation']['excluded']);
        $this->assertSame(2, $plan['reconciliation']['pending']);
        $this->assertSame(
            [['locator' => '3', 'reason' => 'ambigu']],
            $plan['reconciliation']['excluded_ignored'],
        );
    }

    public function test_penunjuk_lembar_dan_baris_mengenai_tepat_satu_baris(): void
    {
        $plan = $this->plan($this->multiSheetRows(), excluded: ['Kelas 11:3']);

        $this->assertSame(1, $plan['reconciliation']['excluded']);
        $this->assertSame(1, $plan['reconciliation']['pending']);
        $this->assertSame([], $plan['reconciliation']['excluded_ignored']);
        $this->assertSame('Kelas 11:3', $plan['reconciliation']['excluded_signature']);

        // Yang berpindah memang baris di lembar yang disebut.
        $excludedRow = collect($plan['rows'])
            ->firstWhere('outcome', StudentImportPlan::EXCLUDED_NOT_REGISTERED);

        $this->assertSame('Kelas 11', $excludedRow['sheet']);
        $this->assertSame(3, $excludedRow['line']);
    }

    public function test_nama_lembar_tidak_peka_huruf_besar_kecil(): void
    {
        // Operator mengetiknya sendiri; "kelas 11" dan "Kelas 11" lembar yang sama.
        $plan = $this->plan($this->multiSheetRows(), excluded: ['  kelas 11 : 3 ']);

        $this->assertSame(1, $plan['reconciliation']['excluded']);
        $this->assertSame([], $plan['reconciliation']['excluded_ignored']);
    }

    public function test_lembar_yang_tidak_ada_dilaporkan_bukan_didiamkan(): void
    {
        $plan = $this->plan($this->multiSheetRows(), excluded: ['Kelas 99:3']);

        $this->assertSame(0, $plan['reconciliation']['excluded']);
        $this->assertSame(
            [['locator' => 'Kelas 99:3', 'reason' => 'tidak berlaku']],
            $plan['reconciliation']['excluded_ignored'],
        );
    }

    public function test_nomor_tanpa_lembar_tetap_berlaku_bila_hanya_satu_yang_layak(): void
    {
        // Berkas satu lembar, atau nomor yang kebetulan hanya cocok di satu
        // tempat: bentuk singkatnya tetap dapat dipakai.
        $rows = $this->multiSheetRows();
        $rows[3]['nis'] = '2026777';

        $plan = $this->plan($rows, excluded: ['3']);

        $this->assertSame(1, $plan['reconciliation']['excluded']);
        $this->assertSame('Kelas 11:3', $plan['reconciliation']['excluded_signature']);
    }

    public function test_sidik_jari_berbeda_untuk_baris_yang_berbeda(): void
    {
        /*
         * Sebelum M8.1 hanya **jumlah** yang masuk sidik jari, karena
         * `totals()` melewati nilai non-skalar. Dua rencana yang sama-sama
         * mengecualikan satu baris — tetapi baris yang berbeda — karena itu
         * bersidik jari sama, dan impor produksi menerima rencana yang bukan
         * yang ditinjau (butir 567).
         */
        $path = tempnam(sys_get_temp_dir(), 'm81').'.xlsx';
        file_put_contents($path, 'berkas tiruan');

        try {
            $a = ImportFingerprint::of($path, $this->school, $this->year, $this->plan($this->multiSheetRows(), excluded: ['Kelas 10:3']));
            $b = ImportFingerprint::of($path, $this->school, $this->year, $this->plan($this->multiSheetRows(), excluded: ['Kelas 11:3']));

            // Keduanya mengecualikan tepat satu baris; yang membedakan hanya
            // baris mana.
            $this->assertFalse($b->matches($a->value));
        } finally {
            @unlink($path);
        }
    }

    // ------------------------------------------------------- sasaran akhir M8

    public function test_empat_puluh_baris_menjadi_39_siap_dan_1_dikecualikan(): void
    {
        $plan = $this->plan($this->fortyRows(), excluded: ['Kelas 12:15']);

        $r = $plan['reconciliation'];

        $this->assertSame(40, $r['source']);
        $this->assertSame(39, $r['ready']);
        $this->assertSame(1, $r['excluded']);
        $this->assertSame(0, $r['pending']);
        $this->assertSame(0, $r['rejected']);
        $this->assertTrue($r['balanced']);

        $this->assertSame(39, $plan['outcomes'][StudentImportPlan::READY_CREATE]);
        $this->assertSame(1, $plan['outcomes'][StudentImportPlan::EXCLUDED_NOT_REGISTERED]);
        $this->assertArrayNotHasKey(StudentImportPlan::PENDING_MISSING_NIS, $plan['outcomes']);
        $this->assertSame([], $r['excluded_ignored']);
    }

    public function test_tanpa_pengecualian_empat_puluh_baris_masih_menyisakan_satu_tertunda(): void
    {
        // Keadaan sebelum sekolah menyatakannya — dan sebabnya gerbang produksi
        // yang menuntut nol tertunda tidak pernah terbuka (butir 559).
        $r = $this->plan($this->fortyRows())['reconciliation'];

        $this->assertSame(40, $r['source']);
        $this->assertSame(39, $r['ready']);
        $this->assertSame(1, $r['pending']);
        $this->assertSame(0, $r['excluded']);
    }

    /**
     * Tiga lembar tingkat; dua di antaranya punya "baris 3" tanpa NIS.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function multiSheetRows(): array
    {
        $rows = [];

        foreach (['Kelas 10', 'Kelas 11', 'Kelas 12'] as $index => $sheet) {
            $rows[] = [
                'sheet' => $sheet, 'source_line' => 2,
                'nis' => '20260'.$index.'1', 'nisn' => '00912345'.$index.'1',
                'full_name' => 'Siswa Karangan '.$sheet, 'gender' => 'L',
                'class_label' => 'X Terbuka - 1',
            ];
        }

        $rows[] = [
            'sheet' => 'Kelas 10', 'source_line' => 3,
            'nis' => null, 'nisn' => null,
            'full_name' => 'Peserta Karangan A', 'gender' => 'L',
            'class_label' => 'X Terbuka - 1',
        ];
        $rows[] = [
            'sheet' => 'Kelas 11', 'source_line' => 3,
            'nis' => null, 'nisn' => null,
            'full_name' => 'Peserta Karangan B', 'gender' => 'P',
            'class_label' => 'XI Terbuka - 1',
        ];

        return $rows;
    }

    /**
     * Empat puluh baris karangan: 39 ber-NIS, satu tanpa NIS.
     *
     * Bentuknya meniru berkas sekolah — tiga lembar tingkat — tetapi tidak satu
     * pun nilainya menunjuk orang sungguhan.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function fortyRows(): array
    {
        $sheets = ['Kelas 10' => 'X Terbuka - 1', 'Kelas 11' => 'XI Terbuka - 1', 'Kelas 12' => 'XII Terbuka - 1'];
        $rows = [];
        $n = 0;

        foreach ($sheets as $sheet => $label) {
            for ($line = 2; $line <= 14; $line++) {
                $n++;

                $rows[] = [
                    'sheet' => $sheet, 'source_line' => $line,
                    'nis' => str_pad((string) (2026000 + $n), 7, '0', STR_PAD_LEFT),
                    'nisn' => str_pad((string) (9000000000 + $n), 10, '0', STR_PAD_LEFT),
                    'full_name' => 'Siswa Karangan '.$n, 'gender' => $n % 2 === 0 ? 'P' : 'L',
                    'class_label' => $label,
                ];
            }
        }

        // 39 baris ber-NIS di atas. Yang ke-40 ditambahkan tersendiri, tanpa
        // NIS: inilah baris yang dinyatakan sekolah bukan siswa terdaftar.
        $rows[] = [
            'sheet' => 'Kelas 12', 'source_line' => 15,
            'nis' => null, 'nisn' => null,
            'full_name' => 'Peserta Tidak Terdaftar', 'gender' => 'L',
            'class_label' => 'XII Terbuka - 1',
        ];

        return $rows;
    }

    /**
     * Tiga baris karangan: dua siswa ber-NIS, satu tanpa NIS.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function rows(): array
    {
        return [
            [
                'sheet' => 'Siswa', 'source_line' => 1,
                'nis' => '2026001', 'nisn' => '0091234567',
                'full_name' => 'Siswa Karangan Satu', 'gender' => 'L',
                'class_label' => 'X Terbuka - 1',
            ],
            [
                'sheet' => 'Siswa', 'source_line' => 2,
                'nis' => '2026002', 'nisn' => '0091234568',
                'full_name' => 'Siswa Karangan Dua', 'gender' => 'P',
                'class_label' => 'X Terbuka - 1',
            ],
            [
                // Tanpa NIS — inilah bentuk baris yang dapat dikecualikan.
                'sheet' => 'Siswa', 'source_line' => 3,
                'nis' => null, 'nisn' => null,
                'full_name' => 'Peserta Karangan Tiga', 'gender' => 'L',
                'class_label' => 'X Terbuka - 1',
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, int|string>  $excluded
     * @return array<string, mixed>
     */
    protected function plan(array $rows, array $excluded = []): array
    {
        return (new StudentImportPlan($this->school, $this->year))
            ->excludingRows($excluded)
            ->build($rows);
    }
}
