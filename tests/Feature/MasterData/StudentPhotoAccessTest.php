<?php

namespace Tests\Feature\MasterData;

use App\Enums\RoleName;
use App\Filament\Resources\StudentResource\Pages\EditStudent;
use App\Models\AcademicYear;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Support\StudentPhoto;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Foto siswa adalah data pribadi anak, bukan media publik (butir 587).
 *
 * Yang dijaga: berkasnya mendarat di disk privat, tidak pernah punya URL disk
 * publik, dan hanya dapat diambil lewat rute panel yang melewati SchoolScope,
 * visibilitas kelas guru, dan `StudentPolicy::view`.
 *
 * Seluruh berkas di sini gambar sintetis buatan `UploadedFile::fake()`.
 */
class StudentPhotoAccessTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;

    protected School $otherSchool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        Storage::fake(StudentPhoto::DISK);
        Storage::fake('public');

        $this->school = School::factory()->create();
        $this->otherSchool = School::factory()->create();
    }

    protected function userWith(RoleName $role, ?School $school = null): User
    {
        return User::factory()->forSchool($school ?? $this->school)->withRole($role)->create();
    }

    /** Siswa dengan satu foto sintetis di disk privat. */
    protected function studentWithPhoto(?School $school = null, string $name = 'contoh'): Student
    {
        $path = StudentPhoto::DIRECTORY."/{$name}.jpg";

        Storage::disk(StudentPhoto::DISK)->put($path, UploadedFile::fake()->image("{$name}.jpg", 40, 40)->getContent());

        return Student::factory()->create([
            'school_id' => ($school ?? $this->school)->id,
            'photo_url' => $path,
        ]);
    }

    protected function photoRoute(Student $student): string
    {
        return route('filament.admin.students.photo', ['student' => $student->getKey()]);
    }

    // ------------------------------------------------------------ penyimpanan

    public function test_disk_foto_siswa_privat_dan_dapat_dikonfigurasi(): void
    {
        $this->assertSame('local', StudentPhoto::disk());
        $this->assertSame(StudentPhoto::DISK, config('storage.student_photo_disk'));
        $this->assertNotSame('public', StudentPhoto::disk());
        $this->assertNull(config('filesystems.disks.'.StudentPhoto::disk().'.url'));

        config(['storage.student_photo_disk' => 's3']);

        $this->assertSame('s3', StudentPhoto::disk());

        // `STUDENT_PHOTO_DISK=` kosong berarti bawaan, bukan disk bawaan
        // aplikasi yang kebetulan sedang berlaku.
        config(['storage.student_photo_disk' => '', 'filesystems.default' => 'public']);

        $this->assertSame(StudentPhoto::DISK, StudentPhoto::disk());
    }

    public function test_unggahan_dari_panel_mendarat_di_disk_privat(): void
    {
        $student = Student::factory()->create(['school_id' => $this->school->id]);

        $this->actingAs($this->userWith(RoleName::SchoolAdmin));

        Livewire::test(EditStudent::class, ['record' => $student->getRouteKey()])
            ->fillForm(['photo_url' => [UploadedFile::fake()->image('siswa.jpg', 400, 400)]])
            ->call('save')
            ->assertHasNoFormErrors();

        $path = (string) $student->fresh()->photo_url;

        $this->assertStringStartsWith(StudentPhoto::DIRECTORY.'/', $path);
        Storage::disk(StudentPhoto::DISK)->assertExists($path);
        Storage::disk('public')->assertMissing($path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_svg_tidak_diterima_sebagai_foto_siswa(): void
    {
        $student = Student::factory()->create(['school_id' => $this->school->id]);

        $this->actingAs($this->userWith(RoleName::SchoolAdmin));

        Livewire::test(EditStudent::class, ['record' => $student->getRouteKey()])
            ->fillForm(['photo_url' => [UploadedFile::fake()->create('siswa.svg', 2, 'image/svg+xml')]])
            ->call('save')
            ->assertHasFormErrors(['photo_url']);

        $this->assertNull($student->fresh()->photo_url);
    }

    public function test_url_foto_adalah_rute_berwenang_bukan_url_storage(): void
    {
        $student = $this->studentWithPhoto();

        $url = (string) StudentPhoto::url($student);

        $this->assertStringStartsWith($this->photoRoute($student), $url);
        $this->assertStringNotContainsString('/storage/', $url);
        $this->assertStringNotContainsString('signature=', $url);
        $this->assertStringNotContainsString((string) $student->photo_url, $url);
    }

    public function test_pratinjau_form_edit_memakai_rute_berwenang(): void
    {
        $student = $this->studentWithPhoto();

        $this->actingAs($this->userWith(RoleName::SchoolAdmin));

        $upload = Livewire::test(EditStudent::class, ['record' => $student->getRouteKey()])
            ->instance()->form->getComponent('data.photo_url');

        $files = array_values($upload->getUploadedFiles() ?? []);

        $this->assertCount(1, $files);
        $this->assertStringStartsWith($this->photoRoute($student), $files[0]['url']);
        $this->assertStringNotContainsString('signature=', $files[0]['url']);
    }

    public function test_jalur_lama_di_disk_publik_tidak_pernah_disajikan(): void
    {
        // Baris dari sebelum butir 587: jalurnya menunjuk disk publik.
        Storage::disk('public')->put('students/lama.jpg', 'foto-lama-sintetis');

        $student = Student::factory()->create([
            'school_id' => $this->school->id,
            'photo_url' => 'students/lama.jpg',
        ]);

        $this->assertNull(StudentPhoto::url($student));

        $this->actingAs($this->userWith(RoleName::SchoolAdmin))
            ->get($this->photoRoute($student))
            ->assertNotFound();
    }

    // ---------------------------------------------------------------- akses

    public function test_tamu_tidak_dapat_mengambil_foto(): void
    {
        $student = $this->studentWithPhoto();

        $this->get($this->photoRoute($student))->assertRedirect();
    }

    public function test_admin_cabangnya_dapat_mengambil_foto(): void
    {
        $student = $this->studentWithPhoto();

        $response = $this->actingAs($this->userWith(RoleName::SchoolAdmin))
            ->get($this->photoRoute($student))
            ->assertOk();

        $this->assertSame(
            Storage::disk(StudentPhoto::DISK)->get((string) $student->photo_url),
            $response->streamedContent(),
        );
        $this->assertStringStartsWith('image/', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));

        // Nama berkas unduhan tidak membawa nama acak di penyimpanan.
        $this->assertStringNotContainsString(
            basename((string) $student->photo_url),
            (string) $response->headers->get('Content-Disposition'),
        );
    }

    public function test_admin_cabang_lain_tidak_menemukan_foto(): void
    {
        $student = $this->studentWithPhoto($this->otherSchool);

        $this->actingAs($this->userWith(RoleName::SchoolAdmin))
            ->get($this->photoRoute($student))
            ->assertNotFound();
    }

    public function test_super_admin_dapat_mengambil_foto_cabang_mana_pun(): void
    {
        $student = $this->studentWithPhoto($this->otherSchool);
        $superAdmin = User::factory()->withRole(RoleName::SuperAdmin)->create(['school_id' => null]);

        $this->actingAs($superAdmin)
            ->get($this->photoRoute($student))
            ->assertOk();
    }

    public function test_guru_hanya_melihat_foto_siswa_kelas_ajarnya(): void
    {
        $year = AcademicYear::factory()->create(['school_id' => $this->school->id, 'is_active' => true]);
        $guru = $this->userWith(RoleName::Guru);

        $class = SchoolClass::factory()->create([
            'school_id' => $this->school->id,
            'academic_year_id' => $year->id,
            'homeroom_teacher_id' => $guru->id,
        ]);

        $diKelasnya = $this->studentWithPhoto(name: 'di-kelas');
        $diLuarKelas = $this->studentWithPhoto(name: 'di-luar');

        StudentClass::factory()->create([
            'school_id' => $this->school->id,
            'student_id' => $diKelasnya->id,
            'class_id' => $class->id,
            'academic_year_id' => $year->id,
        ]);

        $this->actingAs($guru);

        $this->get($this->photoRoute($diKelasnya))->assertOk();
        $this->get($this->photoRoute($diLuarKelas))->assertNotFound();
    }

    public function test_siswa_dan_orang_tua_tidak_melewati_rute_panel(): void
    {
        $student = $this->studentWithPhoto();

        // Pemilik foto sendiri pun tidak: portal mereka hanya menerima
        // `has_photo`, dan rute ini milik panel staf.
        $siswa = $this->userWith(RoleName::Siswa);
        $orangTua = $this->userWith(RoleName::OrangTua);
        $student->update(['user_id' => $siswa->id, 'parent_user_id' => $orangTua->id]);

        $this->actingAs($siswa)->get($this->photoRoute($student))->assertForbidden();
        $this->actingAs($orangTua)->get($this->photoRoute($student))->assertForbidden();
    }

    public function test_berkas_yang_hilang_adalah_404_dan_tanpa_url(): void
    {
        $student = Student::factory()->create([
            'school_id' => $this->school->id,
            'photo_url' => StudentPhoto::DIRECTORY.'/tidak-ada.jpg',
        ]);

        $this->assertNull(StudentPhoto::url($student));

        $this->actingAs($this->userWith(RoleName::SchoolAdmin))
            ->get($this->photoRoute($student))
            ->assertNotFound();
    }

    public function test_jalur_yang_tidak_dapat_dipercaya_ditolak(): void
    {
        foreach ([
            StudentPhoto::DIRECTORY.'/../.env',
            '/etc/passwd',
            StudentPhoto::DIRECTORY.'/berkas.svg',
            StudentPhoto::DIRECTORY.'/berkas.php',
            'payment-proofs/1/bukti.jpg',
        ] as $path) {
            $this->assertNull(StudentPhoto::sanitise($path), $path);
        }
    }

    // --------------------------------------------------------- berkas yatim

    public function test_foto_lama_terhapus_sesudah_diganti(): void
    {
        $student = $this->studentWithPhoto(name: 'lama');
        $lama = (string) $student->photo_url;

        Storage::disk(StudentPhoto::DISK)->put(StudentPhoto::DIRECTORY.'/baru.jpg', 'foto-baru-sintetis');

        $student->update(['photo_url' => StudentPhoto::DIRECTORY.'/baru.jpg']);

        Storage::disk(StudentPhoto::DISK)->assertMissing($lama);
        Storage::disk(StudentPhoto::DISK)->assertExists(StudentPhoto::DIRECTORY.'/baru.jpg');
    }

    public function test_foto_yang_dikosongkan_ikut_terhapus(): void
    {
        $student = $this->studentWithPhoto();
        $lama = (string) $student->photo_url;

        $student->update(['photo_url' => null]);

        Storage::disk(StudentPhoto::DISK)->assertMissing($lama);
    }

    public function test_foto_yang_masih_dirujuk_siswa_cabang_lain_tidak_dihapus(): void
    {
        $student = $this->studentWithPhoto();
        $shared = (string) $student->photo_url;

        Student::factory()->create([
            'school_id' => $this->otherSchool->id,
            'photo_url' => $shared,
        ]);

        $student->update(['photo_url' => null]);

        Storage::disk(StudentPhoto::DISK)->assertExists($shared);
    }

    public function test_penggantian_lewat_panel_membuang_foto_lama(): void
    {
        $student = $this->studentWithPhoto(name: 'lama');
        $lama = (string) $student->photo_url;

        $this->actingAs($this->userWith(RoleName::SchoolAdmin));

        Livewire::test(EditStudent::class, ['record' => $student->getRouteKey()])
            ->fillForm(['photo_url' => [UploadedFile::fake()->image('baru.jpg', 400, 400)]])
            ->call('save')
            ->assertHasNoFormErrors();

        $baru = (string) $student->fresh()->photo_url;

        $this->assertNotSame($lama, $baru);
        Storage::disk(StudentPhoto::DISK)->assertMissing($lama);
        Storage::disk(StudentPhoto::DISK)->assertExists($baru);
    }
}
