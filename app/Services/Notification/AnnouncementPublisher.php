<?php

namespace App\Services\Notification;

use App\Enums\NotificationTargetType;
use App\Enums\NotificationType;
use App\Enums\PermissionName;
use App\Models\Notification;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Scopes\SchoolScope;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * NOTIF-01 — "Sebagai Admin Sekolah, saya dapat membuat pengumuman dengan
 * target (semua, per kelas, atau individu)."
 *
 * Satu-satunya jalur tulis `notifications`. Halaman panel dan endpoint API
 * sama-sama memanggilnya, sehingga kewenangan, cabang, pengirim, dan
 * pemeriksaan target tidak dapat dilewati oleh salah satu jalur.
 */
class AnnouncementPublisher
{
    /**
     * Membuat pengumuman manual, sebagai draf atau langsung terkirim.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException|ValidationException
     */
    public function create(array $input, User $actor, bool $send): Notification
    {
        $this->authorize($actor);

        $schoolId = $this->resolveSchoolId($input['school_id'] ?? null, $actor);
        $type = $this->resolveType($input['type'] ?? null);
        $targetType = $this->resolveTargetType($input['target_type'] ?? null);
        $targetId = $this->resolveTargetId($targetType, $input['target_id'] ?? null, $schoolId);

        return DB::transaction(fn (): Notification => Notification::query()->create([
            'school_id' => $schoolId,
            // Pengirim selalu dari sesi, tidak pernah dari payload: pengumuman
            // yang mengatasnamakan orang lain adalah pemalsuan (butir 200).
            'sender_id' => $actor->getKey(),
            'title' => $this->resolveText($input['title'] ?? null, 'title', 200),
            'message' => $this->resolveText($input['message'] ?? null, 'message'),
            'type' => $type->value,
            'target_type' => $targetType->value,
            'target_id' => $targetId,
            'is_draft' => ! $send,
            // Waktu terbit ditetapkan server. Menerimanya dari klien berarti
            // riwayat pengumuman dapat ditulis ulang (butir 195).
            'sent_at' => $send ? now() : null,
        ]));
    }

    /**
     * Mengubah draf yang belum terkirim.
     *
     * Pengumuman yang **sudah terkirim** tidak dapat diubah: penerimanya sudah
     * membacanya, dan mengubah isinya setelah itu membuat riwayat tidak lagi
     * menggambarkan apa yang benar-benar dikirim. Blueprint tidak menyebutkan
     * pengeditan sama sekali, jadi yang dipilih adalah yang menjaga jejak
     * (butir 195).
     *
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException|ValidationException
     */
    public function update(Notification $notification, array $input, User $actor, bool $send): Notification
    {
        $this->authorize($actor);
        $this->guardSameSchool($notification, $actor);

        if ($notification->isSent()) {
            throw ValidationException::withMessages([
                'id' => 'Pengumuman yang sudah terkirim tidak dapat diubah.',
            ]);
        }

        $schoolId = (int) $notification->school_id;
        $type = $this->resolveType($input['type'] ?? null);
        $targetType = $this->resolveTargetType($input['target_type'] ?? null);
        $targetId = $this->resolveTargetId($targetType, $input['target_id'] ?? null, $schoolId);

        return DB::transaction(function () use ($notification, $input, $type, $targetType, $targetId, $send): Notification {
            $notification->forceFill([
                'title' => $this->resolveText($input['title'] ?? null, 'title', 200),
                'message' => $this->resolveText($input['message'] ?? null, 'message'),
                'type' => $type->value,
                'target_type' => $targetType->value,
                'target_id' => $targetId,
                'is_draft' => ! $send,
                'sent_at' => $send ? now() : null,
            ])->save();

            return $notification;
        });
    }

    /**
     * Menerbitkan draf yang sudah ada.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function send(Notification $notification, User $actor): Notification
    {
        $this->authorize($actor);
        $this->guardSameSchool($notification, $actor);

        if ($notification->isSent()) {
            throw ValidationException::withMessages([
                'id' => 'Pengumuman ini sudah terkirim.',
            ]);
        }

        // Target diperiksa ulang saat kirim: kelas atau pengguna yang menjadi
        // sasaran dapat berubah antara saat draf dibuat dan saat diterbitkan.
        $this->resolveTargetId(
            $notification->target_type,
            $notification->target_id,
            (int) $notification->school_id,
        );

        return DB::transaction(function () use ($notification): Notification {
            $notification->forceFill([
                'is_draft' => false,
                'sent_at' => now(),
            ])->save();

            return $notification;
        });
    }

    /**
     * API 4.10 memberi `POST /notifications` label Auth Level **Admin**, dan
     * API 4.1 mendefinisikan Admin sebagai SCHOOL_ADMIN / SUPER_ADMIN.
     *
     * Tetapi NOTIF-01 dan matriks PRD 1.1.2 baris "Notifikasi (buat)" memberi
     * kewenangannya kepada SUPER_ADMIN ✅, SCHOOL_ADMIN ✅, **dan KEPALA ✅**.
     * Kewenangan fungsional yang spesifik tidak boleh hilang hanya karena label
     * generik di tabel API, jadi yang dipakai izin `notification.manage` —
     * yang RolePermissionSeeder memang sudah berikan persis kepada ketiga peran
     * itu. `auth_level:admin` sengaja tidak dipasang, karena akan menutup
     * Kepala Sekolah (butir 201).
     *
     * @throws AuthorizationException
     */
    protected function authorize(User $actor): void
    {
        if (! $actor->can(PermissionName::NotificationManage->value)) {
            throw new AuthorizationException('Anda tidak berwenang membuat pengumuman.');
        }
    }

    /**
     * @throws AuthorizationException
     */
    protected function guardSameSchool(Notification $notification, User $actor): void
    {
        if ($actor->isSuperAdmin()) {
            return;
        }

        if ((int) $actor->school_id !== (int) $notification->school_id) {
            throw new AuthorizationException('Pengumuman ini bukan milik cabang Anda.');
        }
    }

    /**
     * Cabang tempat pengumuman diterbitkan.
     *
     * `notifications.school_id` NOT NULL sedangkan Super Admin `school_id`-nya
     * NULL, jadi Super Admin wajib memilih cabang secara eksplisit. Bagi peran
     * cabang, apa pun yang muncul di payload diabaikan — aturannya sama dengan
     * seluruh operasi satu-cabang lain di project ini (butir 202).
     *
     * @throws ValidationException
     */
    protected function resolveSchoolId(mixed $requested, User $actor): int
    {
        if (! $actor->isSuperAdmin()) {
            if ($actor->school_id === null) {
                throw ValidationException::withMessages([
                    'school_id' => 'Akun Anda belum terhubung ke cabang mana pun.',
                ]);
            }

            return (int) $actor->school_id;
        }

        if (blank($requested) || ! is_numeric($requested)) {
            throw ValidationException::withMessages([
                'school_id' => 'Cabang sekolah wajib dipilih.',
            ]);
        }

        $exists = School::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->whereKey((int) $requested)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'school_id' => 'Cabang sekolah tidak ditemukan.',
            ]);
        }

        return (int) $requested;
    }

    /**
     * @throws ValidationException
     */
    protected function resolveType(mixed $type): NotificationType
    {
        $resolved = $type instanceof NotificationType
            ? $type
            : NotificationType::fromInput(is_string($type) ? $type : null);

        if ($resolved === null || ! in_array($resolved, NotificationType::manualCases(), true)) {
            throw ValidationException::withMessages([
                'type' => 'Kategori notifikasi tidak dikenali.',
            ]);
        }

        return $resolved;
    }

    /**
     * @throws ValidationException
     */
    protected function resolveTargetType(mixed $targetType): NotificationTargetType
    {
        $resolved = $targetType instanceof NotificationTargetType
            ? $targetType
            : NotificationTargetType::tryFrom(is_string($targetType) ? $targetType : '');

        if ($resolved === null) {
            throw ValidationException::withMessages([
                'target_type' => 'Target notifikasi harus ALL, CLASS, atau INDIVIDUAL.',
            ]);
        }

        return $resolved;
    }

    /**
     * Memeriksa arti `target_id`, yang tidak dapat dijaga foreign key.
     *
     * ALL wajib tanpa target. CLASS dan INDIVIDUAL wajib menunjuk record yang
     * benar-benar ada **dan berada di cabang yang sama** — id dari cabang lain
     * adalah selundupan, bukan salah ketik (butir 194).
     *
     * @throws ValidationException
     */
    protected function resolveTargetId(NotificationTargetType $targetType, mixed $targetId, int $schoolId): ?int
    {
        if (! $targetType->needsTargetId()) {
            if (filled($targetId)) {
                throw ValidationException::withMessages([
                    'target_id' => 'Target "semua" tidak memakai pilihan penerima.',
                ]);
            }

            return null;
        }

        if (blank($targetId) || ! is_numeric($targetId)) {
            throw ValidationException::withMessages([
                'target_id' => 'Penerima wajib dipilih.',
            ]);
        }

        $targetId = (int) $targetId;

        $exists = match ($targetType) {
            NotificationTargetType::SchoolClass => SchoolClass::query()
                ->withoutGlobalScope(SchoolScope::class)
                ->where('school_id', $schoolId)
                ->whereKey($targetId)
                ->exists(),
            NotificationTargetType::Individual => User::query()
                ->withoutGlobalScope(SchoolScope::class)
                ->where('school_id', $schoolId)
                // Pengumuman baru ditujukan kepada akun yang masih aktif;
                // mengirim ke akun nonaktif tidak menjangkau siapa pun karena
                // resolver penerima pun menyaring akun aktif (butir 198).
                ->active()
                ->whereKey($targetId)
                ->exists(),
            default => false,
        };

        if (! $exists) {
            throw ValidationException::withMessages([
                'target_id' => 'Penerima tidak ditemukan pada cabang ini.',
            ]);
        }

        return $targetId;
    }

    /**
     * @throws ValidationException
     */
    protected function resolveText(mixed $value, string $key, ?int $max = null): string
    {
        $value = is_string($value) ? trim($value) : '';

        if ($value === '') {
            throw ValidationException::withMessages([
                $key => 'Kolom ini wajib diisi.',
            ]);
        }

        if ($max !== null && mb_strlen($value) > $max) {
            throw ValidationException::withMessages([
                $key => "Maksimal {$max} karakter.",
            ]);
        }

        return $value;
    }
}
