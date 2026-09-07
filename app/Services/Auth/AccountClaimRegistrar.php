<?php

namespace App\Services\Auth;

use App\Enums\AccountClaimStatus;
use App\Enums\AccountClaimType;
use App\Enums\AuthProvider;
use App\Models\AccountClaim;
use App\Models\School;
use App\Models\Scopes\SchoolScope;
use App\Models\Student;
use App\Support\GoogleIdentity;
use InvalidArgumentException;

/**
 * Menaruh sebuah permintaan ke antrean admin — satu-satunya jalan masuknya.
 *
 * Yang dijaga di sini ada dua. Pertama, jenis permintaan selalu berasal dari
 * daftar putih `AccountClaim::SELF_SERVICE_TYPES`, dan **tidak satu pun jenis
 * itu berupa peran**: nilai `GURU` yang diselundupkan ke payload tidak punya
 * kolom untuk mendarat, apalagi tempat untuk berlaku (butir 544). Kedua,
 * permintaan yang sama tidak pernah menjadi dua baris — orang yang menekan
 * tombol dua kali sedang bertanya "sudah sampai mana", bukan meminta hal kedua.
 */
class AccountClaimRegistrar
{
    /**
     * Permintaan yang menunjuk seorang siswa — SISWA dan ORANG_TUA.
     *
     * @throws InvalidArgumentException bila jenisnya tidak mencocokkan siswa
     */
    public function registerForStudent(
        GoogleIdentity $identity,
        AccountClaimType $type,
        Student $student,
    ): AccountClaim {
        $this->assertSelfService($type);

        if (! $type->matchesStudent()) {
            throw new InvalidArgumentException("Jenis {$type->value} tidak merujuk siswa.");
        }

        /*
         * `school_id` diambil dari siswanya, bukan dari konteks tenant: yang
         * menulis baris ini adalah tamu, dan `SchoolScope::currentSchoolId()`
         * mengembalikan NULL untuknya. Cabangnya karena itu disebut eksplisit.
         */
        return $this->create($identity, $type, (int) $student->school_id, $student->getKey());
    }

    /**
     * Permintaan staf — tanpa siswa, dan tanpa satu pun pencocokan.
     *
     * Cabangnya dipilih pemohon dari daftar cabang aktif, yang memang sudah
     * publik. Ia bukan bukti apa-apa; ia hanya menentukan antrean siapa yang
     * akan membacanya (butir 546).
     */
    public function registerForStaff(GoogleIdentity $identity, School $school): AccountClaim
    {
        $this->assertSelfService(AccountClaimType::StafSekolah);

        return $this->create($identity, AccountClaimType::StafSekolah, (int) $school->getKey(), null);
    }

    protected function create(
        GoogleIdentity $identity,
        AccountClaimType $type,
        int $schoolId,
        ?int $studentId,
    ): AccountClaim {
        $existing = $this->openClaimFor($identity, $type, $studentId);

        if ($existing !== null) {
            return $existing;
        }

        return AccountClaim::query()->create([
            'school_id' => $schoolId,
            'provider' => AuthProvider::Google->value,
            'provider_subject' => $identity->subject,
            'email' => $identity->email,
            'name' => $identity->name,
            'requested_type' => $type->value,
            'student_id' => $studentId,
            'status' => AccountClaimStatus::Pending->value,
            'requested_at' => now(),
        ]);
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function assertSelfService(AccountClaimType $type): void
    {
        if (! in_array($type, AccountClaim::SELF_SERVICE_TYPES, true)) {
            throw new InvalidArgumentException("Jenis {$type->value} tidak dapat diminta sendiri.");
        }
    }

    /**
     * Permintaan yang masih menunggu untuk identitas + jenis + siswa ini.
     */
    public function openClaimFor(
        GoogleIdentity $identity,
        AccountClaimType $type,
        ?int $studentId,
    ): ?AccountClaim {
        return AccountClaim::query()
            ->forIdentity(AuthProvider::Google, $identity->subject)
            ->where('requested_type', $type->value)
            ->when(
                $studentId === null,
                fn ($query) => $query->whereNull('student_id'),
                fn ($query) => $query->where('student_id', $studentId),
            )
            ->pending()
            ->first();
    }

    /**
     * Permintaan yang masih menunggu dari identitas ini, apa pun jenisnya.
     *
     * Dipakai halaman status: selama masih ada satu yang menunggu, pemohon
     * melihat keadaan itu alih-alih formulir kosong yang mengundangnya
     * mengirim permintaan kedua.
     */
    public function anyPendingFor(GoogleIdentity $identity): ?AccountClaim
    {
        return AccountClaim::query()
            ->forIdentity(AuthProvider::Google, $identity->subject)
            ->pending()
            ->latest('requested_at')
            ->first();
    }

    /**
     * Permintaan terakhir dari identitas ini, apa pun keadaannya.
     */
    public function latestFor(GoogleIdentity $identity): ?AccountClaim
    {
        return AccountClaim::query()
            ->forIdentity(AuthProvider::Google, $identity->subject)
            ->latest('requested_at')
            ->latest('id')
            ->first();
    }

    /**
     * Pembatalan oleh pemohon sendiri.
     *
     * Bukan penolakan: yang membatalkan adalah orang yang mengirimkannya, dan
     * alasannya hampir selalu salah anak atau salah jenis. Tanpa ini ia
     * terkunci menunggu admin menolak sesuatu yang ia sendiri sudah tahu keliru.
     *
     * Kepemilikan dibuktikan identitas penyedia di sesi, bukan id pada request:
     * id permintaan orang lain yang disisipkan ke payload tidak cocok dengan
     * `provider_subject` mana pun yang sedang dipegang pemanggil.
     */
    public function cancel(GoogleIdentity $identity, AccountClaim $claim): bool
    {
        if ((string) $claim->provider_subject !== $identity->subject) {
            return false;
        }

        if (! $claim->isReviewable()) {
            return false;
        }

        $claim->update(['status' => AccountClaimStatus::Cancelled->value]);

        return true;
    }

    /**
     * Cabang aktif yang dapat dipilih pemohon staf.
     *
     * Daftar yang sama sudah tampil di halaman PPDB publik, jadi tidak ada
     * keterangan baru yang dibuka di sini.
     *
     * @return array<int, string>
     */
    public function selectableSchools(): array
    {
        return School::query()
            ->active()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Siswa yang barisnya dibaca ulang tanpa scope tenant.
     *
     * Pemanggilnya berjalan sebagai tamu, jadi baris yang sudah ditemukan
     * matcher perlu dibaca dengan cara yang sama ketika hendak dipakai lagi.
     */
    public function findStudent(int $id): ?Student
    {
        return Student::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->find($id);
    }
}
