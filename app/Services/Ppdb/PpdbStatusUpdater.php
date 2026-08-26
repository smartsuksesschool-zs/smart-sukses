<?php

namespace App\Services\Ppdb;

use App\Enums\NotificationType;
use App\Enums\PpdbStatus;
use App\Models\PpdbRegistration;
use App\Models\School;
use App\Models\Scopes\SchoolScope;
use App\Models\Student;
use App\Models\User;
use App\Services\Notification\SystemNotificationPublisher;
use App\Support\PpdbWaTemplate;
use Illuminate\Support\Facades\DB;

/**
 * PPDB-03 poin 3 — "Update status pendaftaran + catatan alasan", dan NOTIF-03
 * poin 1 — trigger otomatis saat "PPDB status berubah".
 *
 * Sampai batch ini pembaruan status ditulis langsung di dalam aksi Filament.
 * Ia dipindahkan ke sini karena kini ada dua tulisan yang harus hidup atau mati
 * bersama — statusnya dan notifikasinya — dan karena "benar-benar berubah"
 * menjadi pertanyaan yang perlu dijawab di satu tempat, bukan di setiap
 * pemanggil (butir 247).
 */
class PpdbStatusUpdater
{
    /**
     * Memperbarui status dan catatannya.
     *
     * Mengembalikan `true` bila statusnya benar-benar berpindah. Menyimpan
     * catatan baru pada status yang sama tetap tersimpan, tetapi **bukan**
     * perubahan status dan karena itu tidak memicu notifikasi apa pun: yang
     * NOTIF-03 sebut adalah "PPDB status berubah" (butir 248).
     */
    public function update(PpdbRegistration $registration, PpdbStatus $status, ?string $notes): bool
    {
        $changed = $registration->status !== $status;

        DB::transaction(function () use ($registration, $status, $notes, $changed): void {
            $registration->update([
                'status' => $status,
                'status_notes' => $notes,
            ]);

            if ($changed) {
                $this->announce($registration->refresh());
            }
        });

        return $changed;
    }

    /**
     * Notifikasi otomatis untuk satu perpindahan status.
     *
     * Penerima in-app hanya ada bila pendaftarnya sudah menjadi siswa **dan**
     * siswa itu punya akun orang tua. Sebelum enroll, `ppdb_registrations`
     * tidak menyimpan satu pun rujukan ke `users` — yang ada hanya
     * `parent_name`, `parent_phone`, dan `parent_email` — sehingga tidak ada
     * `users.id` yang dapat dipakai tanpa mengarang. Dalam keadaan itu
     * publisher mengembalikan NULL dan tidak ada baris yang ditulis; kanal
     * manualnya tetap tautan wa.me PPDB yang sudah ada sejak Sprint 3
     * (butir 240).
     */
    protected function announce(PpdbRegistration $registration): void
    {
        $schoolId = (int) $registration->school_id;

        $student = $registration->converted_student_id === null
            ? null
            : Student::query()
                ->withoutGlobalScope(SchoolScope::class)
                ->where('school_id', $schoolId)
                ->find((int) $registration->converted_student_id);

        $parent = $student?->parent_user_id === null
            ? null
            : User::query()
                ->withoutGlobalScope(SchoolScope::class)
                ->where('school_id', $schoolId)
                ->active()
                ->find((int) $student->parent_user_id);

        if ($parent === null) {
            return;
        }

        $registration->setRelation(
            'school',
            School::query()->withoutGlobalScope(SchoolScope::class)->find($schoolId),
        );

        app(SystemNotificationPublisher::class)->toUser(
            $parent,
            $schoolId,
            // Tidak ada pemetaan kategori eksplisit di sumber. SYSTEM dipilih
            // karena ERD menyediakannya justru untuk notifikasi yang dipicu
            // sistem, dan perubahan status PPDB tidak jatuh ke kategori
            // akademik maupun tagihan (butir 236).
            NotificationType::System,
            'Status PPDB diperbarui',
            sprintf(
                'Status pendaftaran %s (No. %s) kini %s.',
                $registration->full_name,
                $registration->reg_number,
                $registration->status->label(),
            ),
            // Template PPDB yang sudah ada dipakai apa adanya, lengkap dengan
            // kosakata placeholder-nya sendiri — tidak ada sintaks kedua yang
            // dibuat untuk modul ini (butir 237).
            PpdbWaTemplate::render($registration),
        );
    }
}
