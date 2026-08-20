<?php

namespace Tests\Feature\Finance;

use App\Enums\RoleName;
use App\Filament\Pages\GenerateTagihan;
use App\Jobs\GenerateStudentFees;
use App\Models\AcademicYear;
use App\Models\FeeType;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentFee;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * PRD 1.1.2 modul "Tagihan SPP" dan Arsitektur 3.2 — siapa yang boleh
 * menerbitkan tagihan massal, dan untuk cabang mana.
 */
class GenerateStudentFeeAccessTest extends TestCase
{
    use RefreshDatabase;

    protected School $schoolA;

    protected School $schoolB;

    protected FeeType $feeTypeA;

    protected FeeType $feeTypeB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->schoolA = School::factory()->create();
        $this->schoolB = School::factory()->create();

        AcademicYear::factory()->create(['school_id' => $this->schoolA->id, 'is_active' => true]);
        AcademicYear::factory()->create(['school_id' => $this->schoolB->id, 'is_active' => true]);

        $this->feeTypeA = FeeType::factory()->create(['school_id' => $this->schoolA->id, 'name' => 'SPP A']);
        $this->feeTypeB = FeeType::factory()->create(['school_id' => $this->schoolB->id, 'name' => 'SPP B']);

        Student::factory()->create(['school_id' => $this->schoolA->id, 'full_name' => 'Siswa A']);
        Student::factory()->create(['school_id' => $this->schoolB->id, 'full_name' => 'Siswa B']);
    }

    /**
     * @return array<string, array{RoleName}>
     */
    public static function allowedRoles(): array
    {
        return [
            'bendahara' => [RoleName::Bendahara],
            'admin sekolah' => [RoleName::SchoolAdmin],
        ];
    }

    #[DataProvider('allowedRoles')]
    public function test_authorized_roles_reach_the_page(RoleName $role): void
    {
        $this->actingAs(User::factory()->forSchool($this->schoolA)->withRole($role)->create());

        $this->assertTrue(GenerateTagihan::canAccess());
        $this->get(GenerateTagihan::getUrl())->assertSuccessful();
    }

    /**
     * @return array<string, array{RoleName}>
     */
    public static function deniedRoles(): array
    {
        return [
            'kepala sekolah' => [RoleName::KepalaSekolah],
            'guru' => [RoleName::Guru],
            'wali kelas' => [RoleName::WaliKelas],
        ];
    }

    /**
     * KEPALA ⭕ pada matriks: boleh melihat tagihan, tidak boleh menerbitkan.
     */
    #[DataProvider('deniedRoles')]
    public function test_unauthorized_roles_cannot_open_the_page(RoleName $role): void
    {
        $this->actingAs(User::factory()->forSchool($this->schoolA)->withRole($role)->create());

        $this->assertFalse(GenerateTagihan::canAccess());
        $this->get(GenerateTagihan::getUrl())->assertForbidden();
    }

    /**
     * Tombol yang tidak dirender bukan penjagaan, dan pemeriksaan di `mount()`
     * saja tidak cukup: komponen yang sudah terlanjur hidup tetap menerima
     * permintaan berikutnya. Karena itu `preview()` dan `generate()` memeriksa
     * kewenangan sendiri, setiap kali dipanggil.
     */
    #[DataProvider('deniedRoles')]
    public function test_preview_and_generate_refuse_unauthorized_callers(RoleName $role): void
    {
        $this->actingAs(User::factory()->forSchool($this->schoolA)->withRole($role)->create());

        Queue::fake();

        foreach (['preview', 'generate'] as $method) {
            try {
                // Dipanggil langsung pada komponennya, melewati `mount()` —
                // persis yang dilakukan payload Livewire yang dikarang sendiri.
                (new GenerateTagihan)->{$method}();
                $this->fail("Aksi {$method} seharusnya ditolak untuk peran tanpa fee.manage.");
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }

        Queue::assertNothingPushed();
        $this->assertSame(0, StudentFee::query()->withoutGlobalScopes()->count());
    }

    /**
     * Peran School Level selalu terikat cabangnya sendiri; `school_id` yang
     * diselundupkan ke state Livewire diabaikan.
     */
    public function test_a_forged_school_id_is_ignored_for_school_level_users(): void
    {
        $bendahara = User::factory()->forSchool($this->schoolA)->withRole(RoleName::Bendahara)->create();

        $this->actingAs($bendahara);

        $this->assertSame($this->schoolA->id, GenerateTagihan::resolveSchoolId($this->schoolB->id));

        Queue::fake();

        Livewire::test(GenerateTagihan::class)
            ->fillForm([
                'fee_type_id' => $this->feeTypeA->id,
                'period' => '2026-08',
                'due_date' => '2026-08-10',
            ])
            ->set('data.school_id', $this->schoolB->id)
            // Field cabang hanya dirender untuk Super Admin, tetapi hook
            // "ganti cabang" tetap mengosongkan jenis tagihan; isian itu
            // dikembalikan supaya yang benar-benar diuji adalah cabang mana
            // yang dipakai, bukan form yang tidak lengkap.
            ->set('data.fee_type_id', $this->feeTypeA->id)
            ->call('preview')
            ->call('generate');

        Queue::assertPushed(
            GenerateStudentFees::class,
            fn (GenerateStudentFees $job) => $job->schoolId === $this->schoolA->id,
        );
    }

    /**
     * Jenis tagihan cabang lain ditolak di lapisan validasi, bukan sekadar
     * disembunyikan dari daftar opsi.
     */
    public function test_a_fee_type_from_another_tenant_is_rejected(): void
    {
        $this->actingAs(User::factory()->forSchool($this->schoolA)->withRole(RoleName::Bendahara)->create());

        Queue::fake();

        Livewire::test(GenerateTagihan::class)
            ->fillForm([
                'fee_type_id' => $this->feeTypeB->id,
                'period' => '2026-08',
                'due_date' => '2026-08-10',
            ])
            ->call('preview')
            ->assertHasFormErrors(['fee_type_id']);

        Queue::assertNothingPushed();
    }

    /**
     * Jenis tagihan yang sudah dinonaktifkan tidak boleh dipakai menerbitkan
     * tagihan baru (SPP-01 poin 2 — penonaktifan memang untuk itu).
     */
    public function test_an_inactive_fee_type_is_rejected(): void
    {
        $this->actingAs(User::factory()->forSchool($this->schoolA)->withRole(RoleName::Bendahara)->create());

        $this->feeTypeA->forceFill(['is_active' => false])->save();

        Livewire::test(GenerateTagihan::class)
            ->fillForm([
                'fee_type_id' => $this->feeTypeA->id,
                'period' => '2026-08',
                'due_date' => '2026-08-10',
            ])
            ->call('preview')
            ->assertHasFormErrors(['fee_type_id']);
    }

    /**
     * Super Admin (`school_id` NULL) wajib memilih cabang, dan seluruh isian
     * lain mengikuti pilihan itu.
     */
    public function test_super_admin_must_choose_a_branch(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        Queue::fake();

        Livewire::test(GenerateTagihan::class)
            ->fillForm([
                'fee_type_id' => $this->feeTypeA->id,
                'period' => '2026-08',
                'due_date' => '2026-08-10',
            ])
            ->call('preview')
            ->assertHasFormErrors(['school_id']);

        Queue::assertNothingPushed();
    }

    public function test_super_admin_generates_for_the_chosen_branch(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        Queue::fake();

        $component = Livewire::test(GenerateTagihan::class)
            ->fillForm([
                'school_id' => $this->schoolB->id,
                'fee_type_id' => $this->feeTypeB->id,
                'period' => '2026-08',
                'due_date' => '2026-08-10',
            ])
            ->call('preview');

        $preview = $component->get('preview');

        // Hanya siswa cabang B yang masuk hitungan.
        $this->assertSame(1, $preview['active_count']);
        $this->assertStringContainsString('Siswa B', implode(' ', $preview['targets']));

        $component->call('generate');

        Queue::assertPushed(
            GenerateStudentFees::class,
            fn (GenerateStudentFees $job) => $job->schoolId === $this->schoolB->id
                && $job->feeTypeId === $this->feeTypeB->id,
        );
    }

    /**
     * Super Admin pun tidak boleh mencampur cabang: jenis tagihan harus milik
     * cabang yang dipilih.
     */
    public function test_super_admin_cannot_mix_branches(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        Queue::fake();

        Livewire::test(GenerateTagihan::class)
            ->fillForm([
                'school_id' => $this->schoolB->id,
                'fee_type_id' => $this->feeTypeA->id,
                'period' => '2026-08',
                'due_date' => '2026-08-10',
            ])
            ->call('preview')
            ->assertHasFormErrors(['fee_type_id']);

        Queue::assertNothingPushed();
    }
}
