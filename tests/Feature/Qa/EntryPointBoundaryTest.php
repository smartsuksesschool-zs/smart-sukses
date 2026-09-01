<?php

namespace Tests\Feature\Qa;

use App\Enums\RoleName;
use App\Enums\StudentStatus;
use App\Http\Middleware\EnsureParentPortalAccess;
use App\Http\Middleware\EnsureStudentPortalAccess;
use App\Http\Middleware\EnsureTeacherPortalAccess;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Tests\TestCase;

/**
 * S9.5 §7 — audit pintu masuk.
 *
 * Setiap pemeriksaan pintu masuk yang ada sebelumnya menyebut rutenya satu per
 * satu. Konsekuensinya: sebuah rute **baru** yang lupa dipagari tidak
 * membatalkan tes mana pun, karena tidak ada tes yang tahu rute itu ada.
 *
 * Berkas ini membacanya dari tabel rute yang hidup, bukan dari daftar yang
 * ditulis tangan. Menambahkan `Route::get('/siswa/rapor-baru', ...)` di luar
 * grup middleware akan menggagalkan tes di bawah tanpa siapa pun perlu ingat
 * memperbaruinya (butir 402).
 */
class EntryPointBoundaryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Pintu portal, awalan URI-nya, dan pagar yang seharusnya menempel.
     */
    protected const PORTALS = [
        'siswa' => [
            'guard' => EnsureStudentPortalAccess::class,
            'login' => 'student.login',
        ],
        'portal' => [
            'guard' => EnsureParentPortalAccess::class,
            'login' => 'portal.login',
        ],
        'teacher' => [
            'guard' => EnsureTeacherPortalAccess::class,
            'login' => 'filament.admin.auth.login',
        ],
    ];

    /**
     * Yang memang publik, dan disengaja. Segala hal lain di bawah ketiga
     * awalan di atas harus berpagar.
     */
    protected const PUBLIC_URIS = [
        '/',
        'ppdb',
        'ppdb/cek-status',
        'ppdb/{schoolCode}',
        'login',
        'siswa/masuk',
        'portal/masuk',
        'bahasa/{locale}',
    ];

    protected School $school;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->school = School::factory()->create();
    }

    // ------------------------------------------------------- struktur pagar

    /**
     * Tidak ada satu pun rute portal yang lolos tanpa middleware-nya.
     */
    public function test_every_portal_route_carries_its_guard(): void
    {
        $checked = 0;

        foreach ($this->webRoutes() as $route) {
            $prefix = strtok($route->uri(), '/');

            if (! isset(static::PORTALS[$prefix])) {
                continue;
            }

            if (in_array($route->uri(), static::PUBLIC_URIS, true)) {
                continue;
            }

            // Logout menutup sesi; ia justru tidak boleh menuntut sesi yang sah.
            if (str_ends_with($route->getName() ?? '', '.logout')) {
                continue;
            }

            $this->assertContains(
                static::PORTALS[$prefix]['guard'],
                $route->gatherMiddleware(),
                $route->uri().' reaches a portal without its access guard',
            );

            $checked++;
        }

        // Kalau penyaringnya salah dan tidak ada rute yang teruji, tes ini
        // hanya akan berpura-pura hijau.
        $this->assertGreaterThanOrEqual(15, $checked);
    }

    /**
     * Panel admin tidak punya rute yang tidak terautentikasi selain halaman
     * masuknya sendiri.
     */
    public function test_every_admin_panel_route_is_authenticated(): void
    {
        $authenticated = [];
        $open = [];

        foreach ($this->webRoutes() as $route) {
            if (strtok($route->uri(), '/') !== 'admin') {
                continue;
            }

            $guarded = collect($route->gatherMiddleware())->contains(
                fn ($m): bool => is_string($m) && str_contains($m, 'Authenticate'),
            );

            $guarded
                ? $authenticated[] = $route->uri()
                : $open[] = $route->uri();
        }

        // Panelnya besar; kalau penyaringnya salah, daftarnya akan kosong dan
        // tes ini hanya berpura-pura hijau.
        $this->assertGreaterThanOrEqual(40, count($authenticated));

        // Yang tersisa terbuka hanya halaman masuk dan pemulihan kata sandi
        // milik Filament sendiri.
        foreach ($open as $uri) {
            $this->assertMatchesRegularExpression(
                '#^admin/(login|logout|password-reset)#',
                $uri,
                $uri.' is an unauthenticated admin route',
            );
        }
    }

    // ---------------------------------------------------------------- tamu

    /**
     * Tamu diantar ke pintu yang benar, bukan ke 403 dan bukan ke pintu portal
     * lain. Sistem ini punya tiga pintu masuk berbeda; mengirim siswa ke
     * halaman masuk panel akan menyesatkan.
     */
    public function test_a_guest_is_sent_to_the_right_door(): void
    {
        $checked = 0;

        foreach ($this->guardedPortalRoutes() as $route) {
            $prefix = strtok($route->uri(), '/');

            $this->get($this->urlFor($route))
                ->assertRedirect(route(static::PORTALS[$prefix]['login']));

            $checked++;
        }

        $this->assertGreaterThanOrEqual(15, $checked);
    }

    public function test_the_public_entry_points_stay_reachable_without_a_session(): void
    {
        $this->get('/')->assertOk();
        $this->get(route('ppdb.schools'))->assertOk();
        $this->get(route('ppdb.check-status'))->assertOk();
        $this->get(route('ppdb.register', ['schoolCode' => $this->school->code]))->assertOk();
        // Pintu masuk tunggal (butir 437).
        $this->get(route('login'))->assertOk();

        // Ketiga alamat lama tetap publik; kini sebagai pengalihan (butir 443).
        $this->get(route('student.login'))->assertRedirect(route('login'));
        $this->get(route('portal.login'))->assertRedirect(route('login'));
        $this->get('/admin/login')->assertRedirect(route('login'));
    }

    // --------------------------------------------------------- lintas portal

    /**
     * Satu peran tidak boleh berjalan masuk ke portal peran lain.
     *
     * Ini bukan sekadar pengulangan pemeriksaan peran: yang diuji adalah
     * **setiap** rute di balik pagar, bukan hanya dasbornya. Halaman rapor,
     * tagihan, dan hasil ujian sama pekanya dengan dasbor.
     */
    public function test_a_role_may_not_walk_into_another_portal(): void
    {
        $actors = [
            'siswa' => $this->studentUser(),
            'portal' => $this->parentUser(),
            'teacher' => $this->userWith(RoleName::Guru),
        ];

        $checked = 0;

        foreach ($this->guardedPortalRoutes() as $route) {
            $prefix = strtok($route->uri(), '/');

            foreach ($actors as $home => $actor) {
                if ($home === $prefix) {
                    continue;
                }

                $this->actingAs($actor)
                    ->get($this->urlFor($route))
                    ->assertForbidden();

                $checked++;
            }
        }

        $this->assertGreaterThanOrEqual(30, $checked);
    }

    /**
     * Admin sekolah tidak menjadi orang tua, siswa, atau guru siapa pun hanya
     * karena ia berhasil login.
     */
    public function test_a_school_admin_gets_no_portal(): void
    {
        $admin = $this->userWith(RoleName::SchoolAdmin);

        $this->actingAs($admin)->get(route('student.dashboard'))->assertForbidden();
        $this->actingAs($admin)->get(route('portal.dashboard'))->assertForbidden();
        $this->actingAs($admin)->get(route('teacher.dashboard'))->assertForbidden();
    }

    // ------------------------------------------------------------ penunjang

    /**
     * @return list<Route>
     */
    protected function webRoutes(): array
    {
        return collect(Router::getRoutes()->getRoutes())
            ->reject(fn (Route $r): bool => str_starts_with($r->uri(), 'livewire'))
            ->filter(fn (Route $r): bool => in_array('web', $r->gatherMiddleware(), true)
                || str_starts_with($r->uri(), 'admin'))
            ->values()
            ->all();
    }

    /**
     * Rute GET di balik ketiga pagar portal.
     *
     * @return list<Route>
     */
    protected function guardedPortalRoutes(): array
    {
        return collect($this->webRoutes())
            ->filter(fn (Route $r): bool => isset(static::PORTALS[strtok($r->uri(), '/')]))
            ->filter(fn (Route $r): bool => in_array('GET', $r->methods(), true))
            ->reject(fn (Route $r): bool => in_array($r->uri(), static::PUBLIC_URIS, true))
            ->values()
            ->all();
    }

    /**
     * Parameter apa pun diisi `1`: pagarnya berjalan sebelum komponen dimuat,
     * jadi nilainya tidak perlu ada di basis data.
     */
    protected function urlFor(Route $route): string
    {
        return '/'.ltrim((string) preg_replace('/\{[^}]+\}/', '1', $route->uri()), '/');
    }

    protected function userWith(RoleName $role): User
    {
        return User::factory()->forSchool($this->school)->withRole($role)->create();
    }

    protected function studentUser(): User
    {
        $user = $this->userWith(RoleName::Siswa);

        Student::factory()->create([
            'school_id' => $this->school->id,
            'user_id' => $user->id,
            'status' => StudentStatus::Active->value,
        ]);

        return $user;
    }

    protected function parentUser(): User
    {
        $user = $this->userWith(RoleName::OrangTua);

        Student::factory()->create([
            'school_id' => $this->school->id,
            'parent_user_id' => $user->id,
            'status' => StudentStatus::Active->value,
        ]);

        return $user;
    }
}
