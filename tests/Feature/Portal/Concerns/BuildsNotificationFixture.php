<?php

namespace Tests\Feature\Portal\Concerns;

use App\Enums\NotificationTargetType;
use App\Enums\NotificationType;
use App\Enums\RoleName;
use App\Enums\StudentClassStatus;
use App\Enums\StudentStatus;
use App\Models\AcademicYear;
use App\Models\Notification;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Services\Notification\AnnouncementPublisher;

/**
 * Matriks penerima yang dipakai keempat suite notifikasi portal.
 *
 * Ketiga portal harus diuji terhadap kombinasi target yang sama persis — kalau
 * tidak, satu portal dapat lolos justru pada kombinasi yang tidak diujikan
 * padanya. Empat salinan fiksur ini akan perlahan berbeda, dan perbedaannya
 * baru terasa ketika salah satu portal membocorkan notifikasi yang bukan
 * miliknya. Karena itu fiksurnya satu, di sini (butir 215).
 *
 * Isinya dua cabang lengkap, dan penerbitnya menyediakan setiap target: ALL,
 * CLASS (kelas anaknya dan kelas lain), INDIVIDUAL (kepada masing-masing peran
 * dan kepada orang lain), draf, serta satu ALL milik cabang seberang.
 */
trait BuildsNotificationFixture
{
    protected School $schoolA;

    protected School $schoolB;

    protected AcademicYear $yearA;

    protected SchoolClass $classA;

    protected SchoolClass $otherClassA;

    protected User $adminA;

    protected User $kepalaA;

    protected User $bendaharaA;

    protected User $superAdmin;

    protected User $parentA;

    protected User $otherParentA;

    protected User $teacherA;

    protected User $waliA;

    protected User $studentUserA;

    protected Student $childA;

    protected User $adminB;

    protected User $parentB;

    protected User $teacherB;

    protected User $studentUserB;

    protected SchoolClass $classB;

    protected function buildNotificationFixture(): void
    {
        $this->schoolA = School::factory()->create(['name' => 'SMP Madani', 'primary_color' => '#123456']);
        $this->schoolB = School::factory()->create(['name' => 'SMP Seberang']);

        $this->yearA = AcademicYear::factory()->create([
            'school_id' => $this->schoolA->id,
            'is_active' => true,
            'name' => '2026/2027',
            'semester' => 1,
        ]);

        $yearB = AcademicYear::factory()->create([
            'school_id' => $this->schoolB->id,
            'is_active' => true,
            'name' => '2026/2027',
            'semester' => 1,
        ]);

        $this->classA = $this->classFor($this->schoolA, $this->yearA, '7A');
        $this->otherClassA = $this->classFor($this->schoolA, $this->yearA, '7B');
        $this->classB = $this->classFor($this->schoolB, $yearB, '7A');

        $this->adminA = $this->userFor($this->schoolA, RoleName::SchoolAdmin, ['name' => 'Admin Madani']);
        $this->kepalaA = $this->userFor($this->schoolA, RoleName::KepalaSekolah, ['name' => 'Kepala Madani']);
        $this->bendaharaA = $this->userFor($this->schoolA, RoleName::Bendahara, ['name' => 'Bendahara Madani']);

        // Platform Level: school_id NULL. Ia bukan pengguna cabang mana pun, dan
        // karena itu bukan penerima notifikasi cabang mana pun (butir 198).
        $this->superAdmin = User::factory()->superAdmin()->create(['name' => 'Super Admin']);
        $this->parentA = $this->userFor($this->schoolA, RoleName::OrangTua, ['name' => 'Bapak Ahmad']);
        $this->otherParentA = $this->userFor($this->schoolA, RoleName::OrangTua, ['name' => 'Ibu Lain']);
        $this->teacherA = $this->userFor($this->schoolA, RoleName::Guru, ['name' => 'Pak Rudi']);
        $this->waliA = $this->userFor($this->schoolA, RoleName::WaliKelas, ['name' => 'Bu Sari']);
        $this->studentUserA = $this->userFor($this->schoolA, RoleName::Siswa, ['name' => 'Ahmad Fauzi']);

        // Anak parentA duduk di classA; anak otherParentA di kelas lain, supaya
        // target CLASS punya sisi yang memang harus tertutup.
        $this->childA = $this->studentFor(
            $this->schoolA,
            $this->classA,
            'Ahmad Fauzi',
            $this->studentUserA,
            $this->parentA,
        );

        $this->studentFor($this->schoolA, $this->otherClassA, 'Anak Kelas Lain', null, $this->otherParentA);

        $this->adminB = $this->userFor($this->schoolB, RoleName::SchoolAdmin);
        $this->parentB = $this->userFor($this->schoolB, RoleName::OrangTua);
        $this->teacherB = $this->userFor($this->schoolB, RoleName::Guru);
        $this->studentUserB = $this->userFor($this->schoolB, RoleName::Siswa);

        $this->studentFor($this->schoolB, $this->classB, 'Siswa Seberang', $this->studentUserB, $this->parentB);
    }

    /**
     * Permintaan API sebagai pengguna ini, lewat token Sanctum seperti suite
     * portal lainnya — bukan lewat sesi, supaya jalurnya sama dengan produksi.
     */
    protected function asUser(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('test')->plainTextToken);
    }

    protected function classFor(School $school, AcademicYear $year, string $name): SchoolClass
    {
        return SchoolClass::factory()->create([
            'school_id' => $school->id,
            'academic_year_id' => $year->id,
            'name' => $name,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function userFor(School $school, RoleName $role, array $overrides = []): User
    {
        return User::factory()->forSchool($school)->withRole($role)->create($overrides);
    }

    protected function studentFor(
        School $school,
        SchoolClass $class,
        string $name,
        ?User $account = null,
        ?User $parent = null,
    ): Student {
        $student = Student::factory()->create([
            'school_id' => $school->id,
            'user_id' => $account?->getKey(),
            'parent_user_id' => $parent?->getKey(),
            'full_name' => $name,
            'status' => StudentStatus::Active->value,
        ]);

        StudentClass::factory()->create([
            'school_id' => $school->id,
            'student_id' => $student->id,
            'class_id' => $class->id,
            'academic_year_id' => $class->academic_year_id,
            'status' => StudentClassStatus::Active->value,
        ]);

        return $student;
    }

    /**
     * Diterbitkan lewat AnnouncementPublisher, bukan langsung lewat factory:
     * pengirim, waktu kirim, dan cabangnya harus ditentukan jalur yang sama
     * dengan produksi (butir 200).
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function announce(array $overrides = [], ?User $actor = null, bool $send = true): Notification
    {
        return app(AnnouncementPublisher::class)->create([
            'title' => 'Pengumuman Sekolah',
            'message' => 'Isi pengumuman.',
            'type' => NotificationType::Announcement->value,
            'target_type' => NotificationTargetType::All->value,
            ...$overrides,
        ], $actor ?? $this->adminA, $send);
    }

    /** Target ALL cabang A. */
    protected function announceToAll(): Notification
    {
        return $this->announce(['title' => 'Untuk Semua Cabang A']);
    }

    /** Target CLASS, bawaannya kelas anak parentA. */
    protected function announceToClass(?SchoolClass $class = null): Notification
    {
        $class ??= $this->classA;

        return $this->announce([
            'title' => 'Untuk Kelas '.$class->name,
            'target_type' => NotificationTargetType::SchoolClass->value,
            'target_id' => $class->id,
        ]);
    }

    /** Target INDIVIDUAL kepada satu pengguna. */
    protected function announceTo(User $user): Notification
    {
        return $this->announce([
            'title' => 'Untuk '.$user->name,
            'target_type' => NotificationTargetType::Individual->value,
            'target_id' => $user->getKey(),
        ]);
    }

    /** Draf cabang A: tidak pernah terlihat penerima mana pun. */
    protected function draftAnnouncement(): Notification
    {
        return $this->announce(['title' => 'Draf Belum Terkirim'], null, false);
    }

    /** Target ALL cabang seberang. */
    protected function announceToOtherSchool(): Notification
    {
        return $this->announce(['title' => 'Untuk Semua Cabang B'], $this->adminB);
    }
}
