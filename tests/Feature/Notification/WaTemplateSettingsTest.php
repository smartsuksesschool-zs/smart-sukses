<?php

namespace Tests\Feature\Notification;

use App\Enums\AuditAction;
use App\Enums\RoleName;
use App\Filament\Pages\PengaturanNotifikasi;
use App\Models\AuditLog;
use App\Models\School;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * NOTIF-03 poin 2 — "Template teks notifikasi dapat diedit oleh Admin Sekolah."
 *
 * Kewenangannya diuji sebagai matriks, bukan sebagai satu peran yang lolos:
 * kalimat sumbernya menyebut satu peran saja, dan izin yang paling mudah
 * dipinjam untuk memagarinya — `notification.manage` — justru dipegang Kepala
 * Sekolah juga. Kesalahan yang sama sudah terjadi sekali pada NOTIF-02
 * (butir 223), jadi di sini setiap peran disebut namanya (butir 249).
 */
class WaTemplateSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected School $schoolA;

    protected School $schoolB;

    protected User $adminA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->schoolA = School::factory()->create(['name' => 'SMP Madani']);
        $this->schoolB = School::factory()->create(['name' => 'SMP Seberang']);

        $this->adminA = $this->userIn($this->schoolA, RoleName::SchoolAdmin);
    }

    protected function userIn(School $school, RoleName $role): User
    {
        return User::factory()->forSchool($school)->withRole($role)->create();
    }

    // ------------------------------------------------------ kewenangan

    public function test_the_school_admin_opens_the_template_page(): void
    {
        $this->actingAs($this->adminA)
            ->get(PengaturanNotifikasi::getUrl())
            ->assertOk();
    }

    public function test_the_super_admin_opens_the_template_page(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(PengaturanNotifikasi::getUrl())
            ->assertOk();
    }

    /**
     * @return array<string, array<int, RoleName>>
     */
    public static function deniedPanelRoles(): array
    {
        return [
            // NOTIF-03 poin 2 menyebut Admin Sekolah. Kepala Sekolah memegang
            // notification.manage tetapi tidak disebut di sini.
            'kepala sekolah' => [RoleName::KepalaSekolah],
            'bendahara' => [RoleName::Bendahara],
            'guru' => [RoleName::Guru],
            'wali kelas' => [RoleName::WaliKelas],
        ];
    }

    #[DataProvider('deniedPanelRoles')]
    public function test_other_panel_roles_cannot_open_the_template_page(RoleName $role): void
    {
        $this->actingAs($this->userIn($this->schoolA, $role))
            ->get(PengaturanNotifikasi::getUrl())
            ->assertForbidden();
    }

    /**
     * @return array<string, array<int, RoleName>>
     */
    public static function portalRoles(): array
    {
        return [
            'orang tua' => [RoleName::OrangTua],
            'siswa' => [RoleName::Siswa],
        ];
    }

    #[DataProvider('portalRoles')]
    public function test_portal_roles_cannot_reach_the_panel_at_all(RoleName $role): void
    {
        $this->actingAs($this->userIn($this->schoolA, $role))
            ->get(PengaturanNotifikasi::getUrl())
            ->assertForbidden();
    }

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $this->get(PengaturanNotifikasi::getUrl())->assertRedirect();
    }

    public function test_the_school_admin_never_sees_the_branch_picker(): void
    {
        Livewire::actingAs($this->adminA)
            ->test(PengaturanNotifikasi::class)
            ->assertFormFieldIsHidden('school_id');
    }

    public function test_the_super_admin_sees_the_branch_picker(): void
    {
        Livewire::actingAs(User::factory()->superAdmin()->create())
            ->test(PengaturanNotifikasi::class)
            ->assertFormFieldExists('school_id');
    }

    // ------------------------------------------------------ penyimpanan

    public function test_all_three_templates_are_saved(): void
    {
        Livewire::actingAs($this->adminA)
            ->test(PengaturanNotifikasi::class)
            ->fillForm([
                'wa_template_ppdb' => 'Halo [ortu], status [nama].',
                'wa_template_spp' => 'Tagihan [nama] di [sekolah].',
                'wa_template_rapor' => 'Rapor [nama] terbit.',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->schoolA->refresh();

        $this->assertSame('Halo [ortu], status [nama].', $this->schoolA->wa_template_ppdb);
        $this->assertSame('Tagihan [nama] di [sekolah].', $this->schoolA->wa_template_spp);
        $this->assertSame('Rapor [nama] terbit.', $this->schoolA->wa_template_rapor);
    }

    public function test_an_emptied_template_is_stored_as_null_rather_than_a_blank_string(): void
    {
        // "Belum diisi" hanya punya satu bentuk, supaya pemilihan teks bawaan
        // tidak bergantung pada bagaimana kolomnya kebetulan dikosongkan.
        $this->schoolA->update(['wa_template_spp' => 'Ada isinya.']);

        Livewire::actingAs($this->adminA)
            ->test(PengaturanNotifikasi::class)
            ->fillForm(['wa_template_spp' => '   '])
            ->call('save');

        $this->assertNull($this->schoolA->refresh()->wa_template_spp);
    }

    public function test_saving_templates_leaves_the_other_school_settings_untouched(): void
    {
        $this->schoolA->update([
            'primary_color' => '#123456',
            'secondary_color' => '#654321',
            'phone' => '0211234567',
        ]);

        Livewire::actingAs($this->adminA)
            ->test(PengaturanNotifikasi::class)
            ->fillForm(['wa_template_spp' => 'Teks baru.'])
            ->call('save');

        $this->schoolA->refresh();

        $this->assertSame('#123456', $this->schoolA->primary_color);
        $this->assertSame('#654321', $this->schoolA->secondary_color);
        $this->assertSame('0211234567', $this->schoolA->phone);
        $this->assertSame('SMP Madani', $this->schoolA->name);
    }

    public function test_the_school_admin_cannot_write_to_another_branch(): void
    {
        // Pemilih cabang tidak pernah dirender bagi Admin Sekolah, tetapi state
        // Livewire tetap dapat dikirim apa adanya.
        Livewire::actingAs($this->adminA)
            ->test(PengaturanNotifikasi::class)
            ->set('data.school_id', $this->schoolB->id)
            ->set('data.wa_template_spp', 'Selundupan.')
            ->call('save');

        // Cabang seberang tidak tersentuh, dan tulisannya mendarat di cabang
        // pelakunya sendiri — nilai school_id yang diselundupkan diabaikan.
        $this->assertNull($this->schoolB->refresh()->wa_template_spp);
        $this->assertSame('Selundupan.', $this->schoolA->refresh()->wa_template_spp);
    }

    public function test_the_page_only_ever_loads_the_actors_own_branch(): void
    {
        $this->schoolA->update(['wa_template_spp' => 'Milik Madani.']);
        $this->schoolB->update(['wa_template_spp' => 'Milik Seberang.']);

        $component = Livewire::actingAs($this->adminA)->test(PengaturanNotifikasi::class);

        $this->assertSame('Milik Madani.', $component->get('data.wa_template_spp'));
        $this->assertSame($this->schoolA->id, $component->get('data.school_id'));
    }

    public function test_the_super_admin_writes_to_the_branch_they_picked(): void
    {
        Livewire::actingAs(User::factory()->superAdmin()->create())
            ->test(PengaturanNotifikasi::class)
            ->fillForm([
                'school_id' => $this->schoolB->id,
                'wa_template_rapor' => 'Untuk Seberang.',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Untuk Seberang.', $this->schoolB->refresh()->wa_template_rapor);
        $this->assertNull($this->schoolA->refresh()->wa_template_rapor);
    }

    // ------------------------------------------------------ teks apa adanya

    public function test_markup_in_a_template_stays_plain_text(): void
    {
        // Template adalah teks, dan tetap teks: tidak ada tempat di aplikasi
        // ini yang merendernya sebagai HTML.
        $markup = '<script>alert(1)</script> & "kutip"';

        Livewire::actingAs($this->adminA)
            ->test(PengaturanNotifikasi::class)
            ->fillForm(['wa_template_spp' => $markup])
            ->call('save');

        $this->assertSame($markup, $this->schoolA->refresh()->wa_template_spp);

        $this->actingAs($this->adminA)
            ->get(PengaturanNotifikasi::getUrl())
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_the_helper_text_advertises_only_the_established_placeholders(): void
    {
        // Kosakata token untuk SPP dan rapor tidak didefinisikan sumber mana
        // pun, jadi yang ditawarkan hanya tiga token yang maknanya sudah mapan
        // lewat PPDB — bukan nominal, jatuh tempo, atau periode (butir 238).
        $response = $this->actingAs($this->adminA)->get(PengaturanNotifikasi::getUrl())->assertOk();

        $response->assertSee('[nama]');
        $response->assertSee('[sekolah]');
        $response->assertSee('[ortu]');

        $response->assertDontSee('[nominal]');
        $response->assertDontSee('[jatuh_tempo]');
        $response->assertDontSee('[periode]');
        $response->assertDontSee('[semester]');
    }

    public function test_the_ppdb_field_advertises_the_ppdb_vocabulary(): void
    {
        $this->actingAs($this->adminA)
            ->get(PengaturanNotifikasi::getUrl())
            ->assertOk()
            ->assertSee('[nomor_pendaftaran]');
    }

    // ------------------------------------------------------ jejak audit

    public function test_a_template_edit_is_recorded_by_the_existing_audit_trail(): void
    {
        // Tidak ada pencatatan baru yang ditambahkan: listener CUD yang sudah
        // ada menangkap update School seperti update apa pun (butir 250).
        Livewire::actingAs($this->adminA)
            ->test(PengaturanNotifikasi::class)
            ->fillForm(['wa_template_spp' => 'Teks baru.'])
            ->call('save');

        $this->assertTrue(
            AuditLog::query()
                ->where('auditable_type', School::class)
                ->where('auditable_id', $this->schoolA->id)
                ->where('action', AuditAction::Updated->value)
                ->exists(),
        );
    }
}
