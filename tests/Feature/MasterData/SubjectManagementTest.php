<?php

namespace Tests\Feature\MasterData;

use App\Enums\RoleName;
use App\Filament\Resources\SubjectResource\Pages\ManageSubjects;
use App\Models\School;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * AC-KELAS-03 — "Admin dapat membuat mata pelajaran per cabang (nama, kode,
 * jam pelajaran)" (`docs/blueprint/01-PRD.md:1076`).
 *
 * Tiga kata pada requirement itu yang diuji di sini, dan ketiganya perilaku:
 * ketiga field benar-benar tersimpan, kodenya benar-benar unik **per cabang**,
 * dan cabang lain benar-benar boleh memakai kode yang sama (butir 592).
 */
class SubjectManagementTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->school = School::factory()->create(['code' => 'PUSAT']);
        $this->admin = User::factory()
            ->forSchool($this->school)
            ->withRole(RoleName::SchoolAdmin)
            ->create();

        $this->actingAs($this->admin);
    }

    public function test_admin_membuat_mata_pelajaran_dengan_nama_kode_dan_jam_pelajaran(): void
    {
        Livewire::test(ManageSubjects::class)
            ->callAction('create', data: [
                'name' => 'Matematika',
                'code' => 'MTK',
                'credit_hours' => 4,
            ])
            ->assertHasNoActionErrors();

        $subject = Subject::query()->where('code', 'MTK')->first();

        $this->assertNotNull($subject);
        $this->assertSame('Matematika', $subject->name);
        $this->assertSame(4, (int) $subject->credit_hours);
        $this->assertSame($this->school->id, $subject->school_id);
    }

    /**
     * Kode ganda di satu cabang harus menjadi pesan validasi, bukan galat
     * basis data. Indeks `subjects_school_id_code_unique` memang menolaknya di
     * lapis bawah — tetapi penolakan yang sampai ke admin sebagai layar galat
     * 500 tidak memberi tahu apa yang harus ia perbaiki.
     */
    public function test_kode_mata_pelajaran_ganda_di_satu_cabang_ditolak_sebagai_validasi(): void
    {
        Subject::factory()->create([
            'school_id' => $this->school->id,
            'code' => 'MTK',
            'name' => 'Matematika',
        ]);

        Livewire::test(ManageSubjects::class)
            ->callAction('create', data: [
                'name' => 'Matematika Peminatan',
                'code' => 'MTK',
                'credit_hours' => 2,
            ])
            ->assertHasActionErrors(['code']);

        $this->assertSame(1, Subject::query()->where('code', 'MTK')->count());
    }

    /**
     * "Per cabang" pada requirement bukan hiasan: kode yang sama di cabang lain
     * adalah keadaan yang sah, dan pagar di atas tidak boleh ikut melarangnya.
     */
    public function test_kode_yang_sama_boleh_dipakai_cabang_lain(): void
    {
        $cabangLain = School::factory()->create(['code' => 'CABANG2']);
        Subject::factory()->create([
            'school_id' => $cabangLain->id,
            'code' => 'MTK',
            'name' => 'Matematika',
        ]);

        Livewire::test(ManageSubjects::class)
            ->callAction('create', data: [
                'name' => 'Matematika',
                'code' => 'MTK',
                'credit_hours' => 4,
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(2, Subject::withoutGlobalScopes()->where('code', 'MTK')->count());
        $this->assertSame(
            $this->school->id,
            Subject::query()->where('code', 'MTK')->value('school_id'),
        );
    }

    public function test_nama_dan_kode_wajib_diisi(): void
    {
        Livewire::test(ManageSubjects::class)
            ->callAction('create', data: [
                'name' => '',
                'code' => '',
                'credit_hours' => 4,
            ])
            ->assertHasActionErrors(['name', 'code']);

        $this->assertSame(0, Subject::query()->count());
    }
}
