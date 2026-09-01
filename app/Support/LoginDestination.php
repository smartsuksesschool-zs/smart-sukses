<?php

namespace App\Support;

use App\Enums\RoleName;
use App\Models\User;
use Filament\Facades\Filament;

/**
 * Ke mana sebuah akun yang baru masuk seharusnya dibawa.
 *
 * Sampai batch ini ada tiga pintu masuk terpisah, dan pengunjung harus tahu
 * lebih dulu ia "jenis pengguna apa" sebelum boleh mengetikkan kata sandinya.
 * Keputusan pemilik menggantinya dengan satu pintu: kredensial dulu, tujuannya
 * ditentukan sesudahnya — oleh server, dari basis data (butir 437).
 *
 * **Perannya tidak pernah datang dari request.** Tidak dari URL, tidak dari
 * field tersembunyi, tidak dari query string, dan tidak dari pilihan apa pun di
 * formulir. Satu-satunya sumbernya relasi `roles` milik pengguna yang sudah
 * terbukti kredensialnya. Formulirnya sendiri memang tidak punya field peran —
 * tetapi yang menjadikannya aman bukan ketiadaan field itu, melainkan bahwa
 * kelas ini tidak pernah membaca request sama sekali.
 *
 * Kelas ini hanya memilih **tujuan**. Ia bukan pengganti otorisasi: policy,
 * `canAccessPanel()`, global scope tenant, dan seluruh middleware tetap
 * berlaku sesudahnya, dan tetap berhak menolak.
 */
class LoginDestination
{
    /**
     * Peran staf — seluruhnya bermuara ke panel yang sama.
     *
     * Guru **tidak** diarahkan ke `/teacher` hanya karena rute itu ada: hari
     * ini guru masuk lewat panel dan mendarat di panel, dan batch ini tidak
     * memindahkan siapa pun tanpa alasan dari sumber.
     */
    public const STAFF = [
        RoleName::SuperAdmin,
        RoleName::SchoolAdmin,
        RoleName::KepalaSekolah,
        RoleName::Guru,
        RoleName::WaliKelas,
        RoleName::Bendahara,
    ];

    /**
     * URL tujuan, atau NULL bila akun ini tidak boleh masuk.
     */
    public static function urlFor(?User $user): ?string
    {
        $role = self::soleRoleOf($user);

        if ($role === null) {
            return null;
        }

        if ($role === RoleName::Siswa) {
            return PortalEligibility::allows($user, [RoleName::Siswa])
                ? route('student.dashboard')
                : null;
        }

        if ($role === RoleName::OrangTua) {
            return PortalEligibility::allows($user, [RoleName::OrangTua])
                ? route('portal.dashboard')
                : null;
        }

        if (! in_array($role, self::STAFF, true)) {
            return null;
        }

        /*
         * Pagar panel dipakai apa adanya, bukan disalin. Bila `canAccessPanel()`
         * berubah, halaman masuk ikut berubah bersamanya — dan tidak ada
         * kesempatan bagi keduanya untuk perlahan berbeda pendapat.
         *
         * `must_change_password` sengaja **tidak** diperiksa di sini: yang
         * menanganinya `EnsurePasswordIsChanged` di dalam panel, dan
         * menuliskannya lagi di sini berarti dua salinan aturan yang sama
         * (butir 438).
         */
        return $user->canAccessPanel(Filament::getPanel('admin'))
            ? Filament::getPanel('admin')->getUrl()
            : null;
    }

    /**
     * Peran tunggal akun ini, atau NULL bila keadaannya tidak sah.
     *
     * Aturan produknya satu peran utama per pengguna (PRD 1.1.1, ditegakkan
     * `UserResource` lewat `maxItems(1)`). Skema Spatie sendiri mengizinkan
     * lebih, jadi keadaan itu **mungkin** terjadi lewat jalur lain — dan ketika
     * terjadi, ia dianggap cacat data, bukan sesuatu yang boleh ditebak.
     *
     * `roles->first()` sengaja tidak dipakai: urutannya tidak ditentukan apa
     * pun, sehingga tujuan pengguna akan bergantung pada urutan baris yang
     * kebetulan dikembalikan basis data (butir 439).
     */
    public static function soleRoleOf(?User $user): ?RoleName
    {
        if ($user === null || ! $user->is_active) {
            return null;
        }

        $names = $user->roles()->pluck('name');

        if ($names->count() !== 1) {
            return null;
        }

        return RoleName::tryFrom((string) $names->first());
    }

    /**
     * Keterangan untuk catatan internal — **tidak pernah** ditampilkan.
     *
     * Pengguna selalu melihat satu kalimat yang sama (§14): membedakannya akan
     * memberi tahu apakah surel itu terdaftar, apa perannya, dan bagaimana
     * keadaan akunnya. Yang dicatat di sini tidak memuat kata sandi, tidak
     * memuat surel, dan tidak memuat nama.
     */
    public static function diagnosisFor(?User $user): string
    {
        if ($user === null) {
            return 'no user';
        }

        if (! $user->is_active) {
            return 'inactive account';
        }

        $count = $user->roles()->count();

        if ($count === 0) {
            return 'account has no role';
        }

        if ($count > 1) {
            return "account holds {$count} roles; exactly one is required";
        }

        $role = self::soleRoleOf($user);

        if ($role === null) {
            return 'role name is not a known RoleName';
        }

        if (in_array($role, self::STAFF, true)) {
            return "staff role {$role->value} refused by canAccessPanel()";
        }

        return "portal role {$role->value} refused by PortalEligibility";
    }
}
