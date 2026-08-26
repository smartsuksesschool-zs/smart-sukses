<?php

namespace Tests\Feature\Ppdb;

use App\Enums\PpdbStatus;
use App\Enums\RoleName;
use App\Filament\Resources\PpdbRegistrationResource\Pages\ListPpdbRegistrations;
use App\Models\PpdbRegistration;
use App\Models\School;
use App\Models\User;
use App\Support\PpdbWaTemplate;
use App\Support\WhatsAppLink;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PPDB-04 — generate link wa.me notifikasi untuk calon siswa.
 * NOTIF-02 poin 1 — "wa.me/62[nomorHP]?text=[pesan_ter-encode]".
 * API 4.7 — GET /admin/ppdb/{id}/wa-link.
 */
class PpdbWaLinkTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->school = School::factory()->create(['code' => 'MADANI', 'name' => 'Smart Sukses Madani']);
    }

    /**
     * @return array<int, array<int, string|null>>
     */
    public static function phoneProvider(): array
    {
        return [
            'nol di depan' => ['081234567890', '6281234567890'],
            'plus enam dua' => ['+6281234567890', '6281234567890'],
            'dengan pemisah' => ['+62 812-3456-7890', '6281234567890'],
            'sudah 62' => ['6281234567890', '6281234567890'],
            'tanpa awalan' => ['81234567890', '6281234567890'],
            'dengan tanda kurung' => ['(0812) 3456 7890', '6281234567890'],
            'kosong' => ['', null],
            'terlalu pendek' => ['0812', null],
            // Nomor berkode negara lain tidak lagi diberi awalan 62. Sebelumnya
            // +65… menjadi 6265…, yaitu nomor Indonesia milik orang lain yang
            // akan menerima pesan sekolah tanpa pernah ada hubungannya — dan
            // pada NOTIF-02 kesalahan itu terjadi massal, satu baris per
            // penerima, tanpa Admin sempat memeriksa nomornya satu per satu
            // (butir 222).
            'kode negara lain' => ['+6591234567', null],
            'kode negara jauh' => ['+12025550123', null],
            'bukan angka sama sekali' => ['tidak punya', null],
        ];
    }

    #[DataProvider('phoneProvider')]
    public function test_phone_numbers_are_normalised_to_the_wa_me_format(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, WhatsAppLink::normalizePhone($input));
    }

    public function test_link_uses_the_wa_me_format_with_an_encoded_message(): void
    {
        $link = WhatsAppLink::to('081234567890', 'Halo Bapak/Ibu & selamat');

        $this->assertSame(
            'https://wa.me/6281234567890?text='.rawurlencode('Halo Bapak/Ibu & selamat'),
            $link,
        );
    }

    public function test_no_link_is_produced_without_a_phone_number(): void
    {
        $registration = PpdbRegistration::factory()->create([
            'school_id' => $this->school->id,
            'parent_phone' => null,
        ]);

        $this->assertNull($registration->waLink());
    }

    public function test_default_template_per_status_is_filled_with_the_registrant_data(): void
    {
        // PPDB-04 poin 1 — "Selamat, Ananda [nama] dinyatakan LULUS seleksi...".
        $registration = PpdbRegistration::factory()->passed()->create([
            'school_id' => $this->school->id,
            'full_name' => 'Ahmad Fauzi',
            'reg_number' => 'MADANI-2026-0001',
        ]);

        $message = $registration->waMessage();

        $this->assertStringContainsString('Ahmad Fauzi', $message);
        $this->assertStringContainsString('MADANI-2026-0001', $message);
        $this->assertStringContainsString('Smart Sukses Madani', $message);
        $this->assertStringContainsString('LULUS', $message);
        $this->assertStringNotContainsString('[nama]', $message);
    }

    public function test_each_status_has_its_own_default_template(): void
    {
        $registration = PpdbRegistration::factory()->create([
            'school_id' => $this->school->id,
            'full_name' => 'Ahmad Fauzi',
        ]);

        $messages = [];

        foreach (PpdbStatus::cases() as $status) {
            $registration->update(['status' => $status]);
            $messages[] = $registration->refresh()->waMessage();
        }

        $this->assertCount(count(PpdbStatus::cases()), array_unique($messages));
    }

    public function test_school_template_overrides_the_default_one(): void
    {
        // ERD 2.2 — schools.wa_template_ppdb; NOTIF-03 poin 2: template dapat diedit Admin.
        $this->school->update(['wa_template_ppdb' => 'Halo [ortu], status [nama]: [status].']);

        $registration = PpdbRegistration::factory()->passed()->create([
            'school_id' => $this->school->id,
            'full_name' => 'Ahmad Fauzi',
            'parent_name' => 'Bapak Fauzi',
        ]);

        $this->assertSame(
            'Halo Bapak Fauzi, status Ahmad Fauzi: '.PpdbStatus::Passed->label().'.',
            $registration->waMessage(),
        );
    }

    public function test_all_placeholders_are_replaced(): void
    {
        $registration = PpdbRegistration::factory()->create([
            'school_id' => $this->school->id,
            'status_notes' => 'Berkas lengkap',
        ]);

        $template = implode(' ', PpdbWaTemplate::placeholders());

        $rendered = PpdbWaTemplate::fill($template, $registration);

        foreach (PpdbWaTemplate::placeholders() as $placeholder) {
            $this->assertStringNotContainsString($placeholder, $rendered);
        }
    }

    public function test_admin_can_run_the_wa_link_action(): void
    {
        $admin = User::factory()->forSchool($this->school)->withRole(RoleName::SchoolAdmin)->create();
        $registration = PpdbRegistration::factory()->passed()->create([
            'school_id' => $this->school->id,
            'parent_phone' => '081234567890',
        ]);

        $this->actingAs($admin);

        Livewire::test(ListPpdbRegistrations::class)
            ->assertTableActionVisible('waLink', $registration)
            ->callTableAction('waLink', $registration, ['message' => 'Pesan uji untuk [nama]'])
            ->assertHasNoTableActionErrors();
    }

    public function test_the_action_is_hidden_when_the_parent_phone_is_unusable(): void
    {
        $admin = User::factory()->forSchool($this->school)->withRole(RoleName::SchoolAdmin)->create();
        $registration = PpdbRegistration::factory()->create([
            'school_id' => $this->school->id,
            'parent_phone' => null,
        ]);

        $this->actingAs($admin);

        Livewire::test(ListPpdbRegistrations::class)
            ->assertTableActionHidden('waLink', $registration);
    }
}
