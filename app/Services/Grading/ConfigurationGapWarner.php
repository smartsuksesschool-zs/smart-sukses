<?php

namespace App\Services\Grading;

use App\Enums\AssessmentType;
use App\Enums\GradeType;
use App\Models\ClassSubject;
use App\Models\GradeConfig;
use Filament\Notifications\Notification;

/**
 * Dua keadaan yang membuat nilai sumatif tidak ikut menghitung rapor, keduanya
 * senyap tanpa peringatan ini:
 *
 *   C-6      — komponennya tidak tercantum di Grade Config yang aktif;
 *   LOCKED   — tidak ada konfigurasi aktif sama sekali karena versi terakhirnya
 *              sudah dikunci setelah rapor terbit.
 *
 * Keduanya hanya memberi tahu. Tidak ada konfigurasi yang dibuka, dibuat, atau
 * diaktifkan otomatis — siklus DRAFT → ACTIVE → LOCKED tetap milik Admin
 * (keputusan Sprint 4 butir 4).
 *
 * Dipisahkan dari halaman Input Nilai agar setiap jalur input memberi peringatan
 * yang sama: input massal (API 4.8 `POST /grades/bulk`) dan import Excel
 * (`POST /grades/import`) sama-sama dapat menyimpan komponen yang tidak
 * dikonfigurasi, dan keduanya sama-sama perlu mengatakannya.
 */
class ConfigurationGapWarner
{
    public function warn(ClassSubject $classSubject, ?GradeType $type, ?AssessmentType $assessment): void
    {
        // Formatif memang tidak dihitung, dan sikap dilaporkan terpisah sebagai
        // predikat — keduanya bukan kelalaian konfigurasi.
        if ($type === null || ! $type->isAcademic() || $assessment !== AssessmentType::Summative) {
            return;
        }

        $subjectId = (int) $classSubject->subject_id;
        $academicYearId = (int) $classSubject->academic_year_id;

        $config = GradeConfig::activeFor($subjectId, $academicYearId);

        if ($config === null) {
            $this->warnIfConfigurationIsLocked($subjectId, $academicYearId);

            return;
        }

        if (in_array($type, $config->componentTypes(), true)) {
            return;
        }

        Notification::make()
            ->title(__('Komponen ini tidak masuk nilai akhir'))
            ->body(sprintf(
                'Nilai tersimpan, tetapi komponen %s tidak ada di Grade Config %s sehingga '
                    .'tidak ikut menghitung nilai rapor. Tambahkan komponen itu lewat versi '
                    .'konfigurasi baru bila memang seharusnya dihitung.',
                $type->label(),
                $config->label(),
            ))
            ->warning()
            ->persistent()
            ->send();
    }

    /**
     * Tidak adanya konfigurasi aktif punya dua sebab yang sangat berbeda. Mapel
     * yang memang belum pernah dikonfigurasi bukan masalah — nilainya mendapat
     * snapshot begitu Admin mengaktifkan konfigurasi pertama. Yang perlu
     * diperingatkan adalah mapel yang konfigurasinya sudah LOCKED: nilai baru
     * tersimpan tanpa bobot dan tidak akan pernah masuk rapor sampai ada versi
     * baru yang diaktifkan. Ini persis yang dialami siswa yang masuk setelah
     * rapor sekelas terbit.
     */
    protected function warnIfConfigurationIsLocked(int $subjectId, int $academicYearId): void
    {
        $locked = GradeConfig::lockedFor($subjectId, $academicYearId);

        if ($locked === null) {
            return;
        }

        Notification::make()
            ->title(__('Grade Config sudah terkunci'))
            ->body(sprintf(
                'Nilai tersimpan tanpa bobot karena Grade Config %s berstatus LOCKED dan '
                    .'tidak ada versi aktif sebagai acuan. Nilai ini belum akan masuk rapor '
                    .'sampai Admin membuat versi Grade Config baru lewat "Buat Versi Baru" '
                    .'lalu mengaktifkannya.',
                $locked->label(),
            ))
            ->warning()
            ->persistent()
            ->send();
    }
}
