<?php

namespace App\Services\Admin;

use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Menulis akun hasil impor massal — seluruhnya, atau tidak satu pun.
 *
 * Satu-satunya tempat sandi sementara dibuat untuk impor massal, dan satu-satunya
 * tempat ia pernah berada dalam bentuk terbaca: nilai kembaliannya. Ia **tidak**
 * dicatat ke log, tidak disimpan ke disk, dan tidak pernah kembali setelah
 * pemanggilan ini berakhir (butir 594).
 *
 * `must_change_password = true` pada setiap akun: sandi yang dibagikan lewat
 * kanal di luar sistem harus mati pada pemakaian pertamanya. Untuk peran staf,
 * `EnsurePasswordIsChanged` memaksanya di dalam panel. Untuk `SISWA` panel itu
 * tidak ada — dan karena itu akun siswa hasil impor **tidak** menyalakan penanda
 * tersebut; lihat `provision()`.
 */
class AccountProvisioner
{
    /**
     * Panjang sandi sementara, sama dengan aksi Reset Password yang sudah ada.
     */
    public const PASSWORD_LENGTH = 12;

    /**
     * @param  array<int, array<string, mixed>>  $planned
     * @return array<int, array{name: string, email: string, role: string, password: string}>
     */
    public function provision(int $schoolId, array $planned): array
    {
        if ($planned === []) {
            return [];
        }

        $credentials = [];

        DB::transaction(function () use ($schoolId, $planned, &$credentials): void {
            foreach ($planned as $row) {
                $password = Str::password(self::PASSWORD_LENGTH);

                $user = new User;
                $user->forceFill([
                    'school_id' => $schoolId,
                    'name' => $row['name'],
                    'email' => $row['email'],
                    'phone' => $row['phone'],
                    'locale' => $row['locale'],
                    'is_active' => true,
                    'password' => Hash::make($password),
                    /*
                     * Peran portal tidak memiliki halaman ganti sandi, sehingga
                     * penanda ini akan mengunci akunnya alih-alih memaksanya
                     * berganti (butir 593). Admin yang menyetel ulang sandi siswa
                     * melakukannya lewat Ubah Pengguna.
                     */
                    'must_change_password' => $row['student_id'] === null,
                ])->save();

                $user->syncRoles([$row['role']]);

                if ($row['student_id'] !== null) {
                    $this->linkStudent((int) $row['student_id'], $schoolId, (int) $user->getKey());
                }

                $credentials[] = [
                    'name' => $row['name'],
                    'email' => $row['email'],
                    'role' => $row['role'],
                    'password' => $password,
                ];
            }
        });

        return $credentials;
    }

    /**
     * Menautkan akun ke baris siswa, dengan pemeriksaan ulang di dalam transaksi.
     *
     * Pemeriksaan yang sama sudah dilakukan importer, dan diulang di sini bukan
     * karena ragu melainkan karena jarak waktunya: validasi berjalan ketika
     * berkas diunggah, penulisan ketika admin menekan Terapkan. Di antara
     * keduanya seorang admin lain dapat menautkan siswa itu — dan tautan yang
     * tertimpa berarti satu akun kehilangan siswanya tanpa jejak.
     */
    protected function linkStudent(int $studentId, int $schoolId, int $userId): void
    {
        $affected = Student::query()
            ->withoutGlobalScopes()
            ->whereKey($studentId)
            ->where('school_id', $schoolId)
            ->whereNull('user_id')
            ->whereNull('deleted_at')
            ->update(['user_id' => $userId]);

        if ($affected !== 1) {
            throw new AccountProvisioningConflict(
                __('Data siswa berubah saat impor berjalan. Tidak ada akun yang dibuat; jalankan ulang impor.')
            );
        }
    }
}
