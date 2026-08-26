<?php

namespace App\Services\Notification;

use App\Enums\NotificationTargetType;
use App\Enums\StudentClassStatus;
use App\Enums\WhatsAppUnavailableReason;
use App\Models\Notification;
use App\Models\Scopes\SchoolScope;
use App\Models\Student;
use App\Models\User;
use App\Support\WhatsAppLink;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * NOTIF-02 — "untuk setiap notifikasi saya mendapatkan daftar link wa.me siap
 * kirim per penerima".
 *
 * Pengirimannya tetap manual: yang dibuat di sini hanya tautan. Tidak ada pesan
 * WhatsApp yang dikirim, tidak ada antrean, dan tidak ada apa pun yang ditandai
 * "sudah terkirim ke WhatsApp" — Phase 1 memang memakai wa.me manual (Ringkasan
 * Eksekutif), dan mencatat status kirim akan mengarang fakta yang tidak dimiliki
 * sistem (butir 228).
 *
 * Siapa penerimanya **tidak** dijawab di sini: itu milik
 * NotificationRecipientResolver, satu-satunya definisi penerima di aplikasi ini
 * (butir 196). Yang dikerjakan kelas ini hanya mencarikan nomor bagi penerima
 * yang sudah ditentukan resolver, menormalkannya, dan menyusun tautannya.
 *
 * Kelas ini sekaligus satu-satunya daftar-putih untuk data telepon: API maupun
 * panel memakainya, sehingga tidak ada permukaan yang dapat perlahan
 * membocorkan kolom yang tidak diminta (butir 227).
 */
class NotificationWaLinkService
{
    public function __construct(protected NotificationRecipientResolver $recipients) {}

    /**
     * Daftar tautan untuk satu notifikasi.
     *
     * Membaca saja: tidak ada notification_reads yang dibuat, tidak ada sent_at
     * yang bergeser, dan tidak ada nomor yang ditulis balik ke users maupun
     * students.
     *
     * @param  array{search?: ?string, availability?: ?string}  $filters
     * @return array<string, mixed>
     */
    public function linksFor(Notification $notification, array $filters = []): array
    {
        $message = $this->messageOf($notification);
        $recipients = $this->recipients->recipientsOf($notification);
        $fallbacks = $this->parentPhoneFallbacks($notification, $recipients);

        $rows = collect($recipients->all())
            ->map(fn (User $recipient): array => $this->rowFor($recipient, $message, $fallbacks));

        return [
            'notification' => [
                'id' => $notification->getKey(),
                'title' => $notification->title,
            ],
            // Ringkasan selalu menggambarkan **seluruh** penerima, bukan hanya
            // baris yang lolos filter. Justru itu gunanya: menyaring ke "tidak
            // tersedia" tetap memperlihatkan berapa yang terjangkau, sehingga
            // Admin tidak pernah mengira daftar yang tampil adalah semuanya
            // (butir 226).
            'summary' => [
                'recipient_count' => $rows->count(),
                'available_count' => $rows->where('wa_available', true)->count(),
                'unavailable_count' => $rows->where('wa_available', false)->count(),
            ],
            'recipients' => $this->filtered($rows, $filters)->values()->all(),
        ];
    }

    /**
     * NOTIF-02 memakai teks notifikasi apa adanya.
     *
     * notifications.wa_template dipakai bila memang sudah berisi teks WA yang
     * dimaksud; bila kosong, isi pengumumannya sendiri yang dikirim. Tidak ada
     * placeholder yang diproses di batch ini, dan tidak ada kolom template yang
     * diubah (butir 229).
     */
    protected function messageOf(Notification $notification): string
    {
        $template = trim((string) $notification->wa_template);

        return $template !== '' ? $template : (string) $notification->message;
    }

    /**
     * @param  array<int, array<string, string>>  $fallbacks
     * @return array<string, mixed>
     */
    protected function rowFor(User $recipient, string $message, array $fallbacks): array
    {
        [$phone, $reason] = $this->phoneFor($recipient, $fallbacks);

        $normalized = WhatsAppLink::normalizePhone($phone);

        if ($reason === null && $normalized === null) {
            $reason = trim((string) $phone) === ''
                ? WhatsAppUnavailableReason::MissingPhone
                : WhatsAppUnavailableReason::InvalidPhone;
        }

        $available = $reason === null && $normalized !== null;

        return [
            'name' => $recipient->name,
            'phone' => $phone,
            'normalized_phone' => $available ? $normalized : null,
            'wa_available' => $available,
            'wa_url' => $available ? WhatsAppLink::to($phone, $message) : null,
            'reason' => $reason?->value,
            'reason_label' => $reason?->label(),
        ];
    }

    /**
     * Nomor mana yang dipakai untuk seorang penerima.
     *
     * Identitas penerima adalah **akun**, jadi users.phone selalu didahulukan.
     * students.parent_phone hanya cadangan, dan hanya bila hubungannya jelas —
     * nomor siswa sendiri tidak pernah dipakai sebagai nomor orang tuanya,
     * karena kolom itu memang bukan nomor mereka (butir 225).
     *
     * @param  array<int, array<string, string>>  $fallbacks
     * @return array{0: ?string, 1: ?WhatsAppUnavailableReason}
     */
    protected function phoneFor(User $recipient, array $fallbacks): array
    {
        if (trim((string) $recipient->phone) !== '') {
            return [$recipient->phone, null];
        }

        $candidates = $fallbacks[$recipient->getKey()] ?? [];

        return match (count($candidates)) {
            0 => [null, null],
            1 => [reset($candidates), null],
            // Dua anak dengan nomor orang tua berbeda: tidak ada dasar untuk
            // memilih salah satunya, dan menebak berarti mengirim ke nomor yang
            // mungkin bukan miliknya. Statusnya dilaporkan apa adanya.
            default => [null, WhatsAppUnavailableReason::AmbiguousPhone],
        };
    }

    /**
     * Nomor cadangan dari data anak, dimuat sekaligus untuk seluruh penerima.
     *
     * Satu query, bukan satu query per orang tua: daftar penerima target ALL
     * bisa sebesar satu cabang, dan mencarikan nomor per baris akan membuat
     * biayanya tumbuh bersama jumlah penerima (butir 230).
     *
     * Yang menjadi syarat adalah **hubungannya** (students.parent_user_id),
     * bukan label perannya: hubungan itulah yang membuat sebuah nomor relevan
     * bagi akun ini, dan memeriksanya lewat relasi menghindari satu query peran
     * tambahan.
     *
     * @param  EloquentCollection<int, User>  $recipients
     * @return array<int, array<string, string>>
     */
    protected function parentPhoneFallbacks(Notification $notification, EloquentCollection $recipients): array
    {
        $needing = $recipients
            ->filter(fn (User $recipient): bool => trim((string) $recipient->phone) === '')
            ->modelKeys();

        if ($needing === []) {
            return [];
        }

        $students = Student::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', (int) $notification->school_id)
            ->whereIn('parent_user_id', $needing)
            ->whereNotNull('parent_phone')
            ->when(
                $notification->target_type === NotificationTargetType::SchoolClass,
                // Target CLASS: hanya anak di kelas yang ditarget yang membuat
                // sebuah nomor relevan bagi pengumuman ini.
                fn (Builder $query) => $query->whereHas(
                    'studentClasses',
                    fn (Builder $placement) => $placement
                        ->withoutGlobalScope(SchoolScope::class)
                        ->where('class_id', (int) $notification->target_id)
                        ->where('status', StudentClassStatus::Active->value),
                ),
            )
            ->get(['id', 'parent_user_id', 'parent_phone']);

        $byParent = [];

        foreach ($students as $student) {
            $phone = trim((string) $student->parent_phone);

            if ($phone === '') {
                continue;
            }

            // Nomor dipakai sebagai kunci: dua anak dengan nomor yang sama
            // menghasilkan satu calon, bukan dua — yang berbeda barulah ambigu.
            $byParent[(int) $student->parent_user_id][$phone] = $phone;
        }

        return $byParent;
    }

    /**
     * NOTIF-02 poin 2 — "Daftar dapat difilter".
     *
     * Penyaringan dikerjakan atas daftar yang sudah tersusun, bukan sebagai
     * query: nomor sebagian penerima berasal dari data anak, sehingga menyaring
     * di database berarti menyaring atas kolom yang belum tentu dipakai.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array{search?: ?string, availability?: ?string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    protected function filtered(Collection $rows, array $filters): Collection
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $availability = $filters['availability'] ?? null;

        return $rows
            ->when(
                $availability === 'available',
                fn (Collection $filtered) => $filtered->where('wa_available', true),
            )
            ->when(
                $availability === 'unavailable',
                fn (Collection $filtered) => $filtered->where('wa_available', false),
            )
            ->when(
                $search !== '',
                fn (Collection $filtered) => $filtered->filter(
                    fn (array $row): bool => Str::contains((string) $row['name'], $search, ignoreCase: true)
                        || Str::contains((string) $row['phone'], $search, ignoreCase: true)
                        || Str::contains((string) $row['normalized_phone'], $search, ignoreCase: true),
                ),
            );
    }
}
