<?php

namespace App\Services\Auth;

use App\Enums\AccountClaimStatus;
use App\Enums\AccountClaimType;
use App\Enums\AuthProvider;
use App\Enums\RoleName;
use App\Exceptions\AccountClaimException;
use App\Models\AccountClaim;
use App\Models\Scopes\SchoolScope;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Persetujuan dan penolakan permintaan akun.
 *
 * Semuanya terjadi di dalam satu transaksi, dan setiap syarat diperiksa **ulang
 * di dalamnya** atas baris yang sudah dikunci. Pemeriksaan yang dilakukan
 * sebelum transaksi — di formulir publik, di tombol Filament — hanya berlaku
 * untuk keadaan pada saat itu; yang menentukan adalah keadaan pada saat
 * penulisan. Dua admin yang menekan Setujui pada dua permintaan berbeda untuk
 * satu siswa yang sama adalah keadaan yang benar-benar mungkin, dan yang kedua
 * harus gagal, bukan menimpa yang pertama (butir 534).
 *
 * Lapisan terakhirnya bukan kode ini melainkan indeks unik: `approved_key` pada
 * `account_claims` untuk permintaan yang menunjuk siswa, dan
 * `(auth_provider, provider_subject)` pada `users` untuk seluruhnya.
 *
 * **Peran hasilnya tidak pernah datang dari pemohon.** Untuk siswa dan orang
 * tua ia mengikuti jenis permintaannya; untuk staf ia dipilih peninjau dari
 * `AccountClaim::REVIEWER_ASSIGNABLE_ROLES`, yang tidak pernah memuat
 * SCHOOL_ADMIN maupun SUPER_ADMIN (butir 547).
 */
class AccountClaimReviewer
{
    /**
     * @param  RoleName|null  $chosenRole  peran pilihan peninjau; wajib untuk
     *                                     permintaan staf, diabaikan untuk sisanya
     */
    public function approve(AccountClaim $claim, User $reviewer, ?RoleName $chosenRole = null): User
    {
        return DB::transaction(function () use ($claim, $reviewer, $chosenRole): User {
            $locked = $this->lockClaim($claim);

            if (! $locked->isReviewable()) {
                throw AccountClaimException::notReviewable();
            }

            $type = $locked->requested_type;

            if (! in_array($type, AccountClaim::SELF_SERVICE_TYPES, true)) {
                throw AccountClaimException::typeNotSelfService();
            }

            $role = $this->resolveRole($locked, $chosenRole);

            $student = $this->lockStudentFor($locked, $type);

            if ($student !== null && $this->linkColumnFor($student, $role) !== null) {
                throw AccountClaimException::studentAlreadyLinked();
            }

            $user = $this->resolveUser($locked, $student, $role);

            if ($student !== null) {
                $this->linkStudent($student, $role, $user);
            }

            $locked->update([
                'status' => AccountClaimStatus::Approved->value,
                'approved_role' => $role->value,
                'reviewed_at' => now(),
                'reviewed_by' => $reviewer->getKey(),
                'approved_user_id' => $user->getKey(),
            ]);

            $this->log('account claim approved', $locked, $reviewer);

            return $user;
        });
    }

    public function reject(AccountClaim $claim, User $reviewer, ?string $notes = null): void
    {
        DB::transaction(function () use ($claim, $reviewer, $notes): void {
            $locked = $this->lockClaim($claim);

            if (! $locked->isReviewable()) {
                throw AccountClaimException::notReviewable();
            }

            $locked->update([
                'status' => AccountClaimStatus::Rejected->value,
                'reviewed_at' => now(),
                'reviewed_by' => $reviewer->getKey(),
                'review_notes' => $notes,
            ]);

            $this->log('account claim rejected', $locked, $reviewer);
        });
    }

    /**
     * Peran yang akan diberikan — pagar terpenting seluruh alur staf.
     *
     * Untuk siswa dan orang tua, pilihan peninjau **diabaikan sepenuhnya**:
     * jenisnya sudah menentukan perannya, dan menerima nilai lain di sini
     * berarti membuka jalan bagi permintaan siswa yang disetujui sebagai
     * bendahara.
     */
    protected function resolveRole(AccountClaim $claim, ?RoleName $chosenRole): RoleName
    {
        $implied = $claim->requested_type->impliedRole();

        if ($implied !== null) {
            return $implied;
        }

        if ($chosenRole === null) {
            throw AccountClaimException::roleRequired();
        }

        if (! in_array($chosenRole, AccountClaim::REVIEWER_ASSIGNABLE_ROLES, true)) {
            throw AccountClaimException::roleNotAssignable();
        }

        return $chosenRole;
    }

    /**
     * Baris siswa yang dikunci, atau NULL bila jenisnya memang tidak menunjuk
     * siapa pun.
     */
    protected function lockStudentFor(AccountClaim $claim, AccountClaimType $type): ?Student
    {
        if (! $type->matchesStudent()) {
            return null;
        }

        if ($claim->student_id === null) {
            // Jenis yang menuntut siswa tetapi barisnya tidak menyebut satu pun:
            // keadaan yang seharusnya mustahil, dan karena itu tidak ditebak.
            throw AccountClaimException::studentMissing();
        }

        return Student::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->lockForUpdate()
            ->findOrFail((int) $claim->student_id);
    }

    /**
     * Akun yang akan memiliki peran ini — dipakai ulang bila memang sudah ada.
     *
     * Satu identitas Google berhak atas **satu** akun. Orang tua dengan dua anak
     * karena itu tetap satu pengguna dengan dua tautan, bukan dua pengguna yang
     * kebetulan bersurel sama (butir 537).
     */
    protected function resolveUser(AccountClaim $claim, ?Student $student, RoleName $role): User
    {
        $schoolId = (int) ($student?->school_id ?? $claim->school_id);

        $existing = User::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('auth_provider', AuthProvider::Google->value)
            ->where('provider_subject', $claim->provider_subject)
            ->first();

        if ($existing !== null) {
            return $this->reuse($existing, $schoolId, $role);
        }

        /*
         * Surel Google yang sama dengan surel akun berkata sandi yang sudah ada
         * **tidak** digabungkan diam-diam. Google membuktikan penguasaan kotak
         * surel; ia tidak membuktikan bahwa pemilik kotak itu adalah orang yang
         * dulu diberi akun staf dengan alamat tersebut. Penggabungan yang salah
         * di sini berarti seseorang mewarisi peran orang lain, jadi keadaan ini
         * berhenti sebagai keputusan admin, bukan diselesaikan sendiri.
         */
        $emailTaken = User::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('email', $claim->email)
            ->exists();

        if ($emailTaken) {
            throw AccountClaimException::emailAlreadyUsed();
        }

        $user = new User([
            'school_id' => $schoolId,
            'name' => $this->displayNameFor($claim, $student, $role),
            'email' => $claim->email,
            'is_active' => true,
            // Akun Google tidak punya kata sandi sementara, jadi tidak ada yang
            // wajib diganti — dan tidak ada kata sandi yang perlu diberitahukan
            // kepada siapa pun (butir 538).
            'must_change_password' => false,
            'auth_provider' => AuthProvider::Google->value,
            'provider_subject' => $claim->provider_subject,
        ]);

        // Tanpa kata sandi sama sekali. `AbstractHasher::check()` menolak hash
        // NULL, sehingga `Auth::attempt()` atas akun ini selalu gagal.
        $user->password = null;
        $user->email_verified_at = now();
        $user->save();

        // `syncRoles`, bukan `assignRole`: yang dijamin bukan "peran ini ada"
        // melainkan "tepat peran ini, dan tidak ada yang lain" (PRD 1.1.1).
        $user->syncRoles([$role->value]);

        return $user->refresh();
    }

    /**
     * Akun Google yang sudah ada — hanya boleh dipakai ulang oleh peran dan
     * cabang yang sama persis.
     */
    protected function reuse(User $existing, int $schoolId, RoleName $role): User
    {
        /*
         * Anak kedua di cabang lain tidak dapat ditaut hari ini: `users` hanya
         * punya satu `school_id`, sehingga satu akun tidak dapat berada di dua
         * cabang sekaligus. Ini batasan skema, bukan aturan produk — dilaporkan
         * apa adanya alih-alih diakali dengan akun kedua (butir 539).
         */
        if ((int) $existing->school_id !== $schoolId) {
            throw AccountClaimException::crossSchool();
        }

        /*
         * Hanya ORANG_TUA yang punya alasan sah memakai ulang akunnya: anak
         * kedua. SISWA menaut ke satu baris siswa, dan staf sudah punya
         * akunnya — untuk keduanya, "pakai ulang" hanya nama lain dari
         * pengambilalihan (butir 549).
         */
        if ($role !== RoleName::OrangTua) {
            throw AccountClaimException::identityAlreadyUsed();
        }

        if (! $existing->hasRole($role->value)) {
            throw AccountClaimException::identityAlreadyUsed();
        }

        if (! $existing->is_active) {
            throw AccountClaimException::identityAlreadyUsed();
        }

        return $existing;
    }

    /**
     * Nama akun.
     *
     * Untuk siswa, nama pada data induk adalah kebenarannya — bukan nama
     * tampilan Google, yang dapat berupa julukan apa pun. Untuk orang tua dan
     * staf, data induk tidak memuat namanya sama sekali, sehingga nama Google
     * dipakai bila ada.
     */
    protected function displayNameFor(AccountClaim $claim, ?Student $student, RoleName $role): string
    {
        if ($role === RoleName::Siswa && $student !== null) {
            return (string) $student->full_name;
        }

        $name = trim((string) ($claim->name ?? ''));

        if ($name !== '') {
            return mb_substr($name, 0, 150);
        }

        $fallback = trim((string) ($student->parent_name ?? ''));

        if ($fallback !== '') {
            return mb_substr($fallback, 0, 150);
        }

        // Tanpa nama sama sekali: surel lebih baik daripada string kosong, dan
        // jauh lebih baik daripada nama karangan.
        return mb_substr((string) $claim->email, 0, 150);
    }

    protected function linkStudent(Student $student, RoleName $role, User $user): void
    {
        $column = $role === RoleName::Siswa ? 'user_id' : 'parent_user_id';

        $student->forceFill([$column => $user->getKey()])->save();
    }

    protected function linkColumnFor(Student $student, RoleName $role): ?int
    {
        $value = $role === RoleName::Siswa ? $student->user_id : $student->parent_user_id;

        return $value === null ? null : (int) $value;
    }

    /**
     * Baris permintaan yang dikunci sampai transaksi selesai.
     */
    protected function lockClaim(AccountClaim $claim): AccountClaim
    {
        return AccountClaim::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->lockForUpdate()
            ->findOrFail($claim->getKey());
    }

    /**
     * Jejak keputusan — id saja, tanpa surel, tanpa NIS, tanpa NISN.
     *
     * Baris audit CUD-nya sudah ditulis listener global; yang ini melengkapi
     * jejak operasional tanpa menaruh satu pun pengenal siswa di log (butir 540).
     */
    protected function log(string $message, AccountClaim $claim, User $reviewer): void
    {
        Log::info($message, [
            'claim_id' => $claim->getKey(),
            'requested_type' => $claim->requested_type->value,
            'approved_role' => $claim->approved_role?->value,
            'student_id' => $claim->student_id,
            'status' => $claim->status->value,
            'reviewer_id' => $reviewer->getKey(),
        ]);
    }
}
