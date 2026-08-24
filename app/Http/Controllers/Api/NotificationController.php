<?php

namespace App\Http\Controllers\Api;

use App\Enums\NotificationType;
use App\Enums\PermissionName;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\AdminNotificationResource;
use App\Http\Resources\Api\NotificationResource;
use App\Models\Notification;
use App\Models\Scopes\SchoolScope;
use App\Services\Notification\AnnouncementPublisher;
use App\Services\Notification\NotificationCenter;
use App\Support\Api\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * API 4.10 — Notifikasi.
 *
 * Dua kelompok endpoint yang sengaja tidak dicampur:
 *
 *  - **penerima** (`/notifications*`) — apa yang ditujukan kepada pengguna yang
 *    login. Auth Level "Auth", dan kewenangannya bukan izin melainkan
 *    *kepenerimaan*: tidak ada peran yang memberi hak membaca notifikasi orang
 *    lain (butir 203).
 *  - **admin** (`/admin/notifications`) — riwayat pengumuman satu cabang,
 *    termasuk draf. Dipagari izin `notification.manage` (butir 201).
 *
 * Admin pun tetap memakai umpan penerima untuk notifikasi yang ditujukan
 * kepadanya sendiri; riwayat admin bukan penggantinya.
 */
class NotificationController extends Controller
{
    /**
     * GET /notifications — "Daftar notifikasi untuk user yang login.
     * Filter: type, is_read. Limit: 50 terbaru".
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'type' => ['nullable', Rule::enum(NotificationType::class)],
            'is_read' => ['nullable', 'boolean'],
        ]);

        $feed = app(NotificationCenter::class)->feed($request->user(), [
            'type' => isset($filters['type']) ? NotificationType::from($filters['type']) : null,
            'is_read' => array_key_exists('is_read', $filters) && $filters['is_read'] !== null
                ? $request->boolean('is_read')
                : null,
        ]);

        return ApiResponse::success(NotificationResource::collection($feed)->resolve());
    }

    /**
     * GET /notifications/unread-count — "Jumlah notifikasi belum dibaca (untuk
     * badge)".
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return ApiResponse::success([
            'unread_count' => app(NotificationCenter::class)->unreadCount($request->user()),
        ]);
    }

    /**
     * GET /notifications/{id} — detail satu notifikasi miliknya.
     *
     * @throws ModelNotFoundException
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $notification = app(NotificationCenter::class)->show($request->user(), $id);

        return ApiResponse::success((new NotificationResource($notification))->resolve());
    }

    /**
     * POST /notifications — "Buat dan kirim notifikasi baru (target:
     * ALL/CLASS/INDIVIDUAL)".
     *
     * `action` memisahkan draf dari kirim secara eksplisit, alih-alih menerima
     * `is_draft` dan `sent_at` yang dapat saling bertentangan — dan keduanya
     * memang tidak pernah diterima dari klien (butir 195, 200).
     *
     * @throws AuthorizationException
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'school_id' => ['nullable', 'integer'],
            'title' => ['required', 'string', 'max:200'],
            'message' => ['required', 'string'],
            'type' => ['required', 'string'],
            'target_type' => ['required', 'string'],
            'target_id' => ['nullable', 'integer'],
            'action' => ['required', Rule::in(['draft', 'send'])],
        ]);

        $notification = app(AnnouncementPublisher::class)->create(
            $data,
            $request->user(),
            send: $data['action'] === 'send',
        );

        $notification->loadMissing('sender');

        return ApiResponse::success(
            (new AdminNotificationResource($notification))->resolve(),
            $data['action'] === 'send' ? 'Pengumuman terkirim.' : 'Draf pengumuman tersimpan.',
            201,
        );
    }

    /**
     * PATCH /notifications/{id}/read — "Tandai notifikasi sebagai dibaca".
     *
     * @throws ModelNotFoundException
     */
    public function markRead(Request $request, int $id): JsonResponse
    {
        $read = app(NotificationCenter::class)->markRead($request->user(), $id);

        return ApiResponse::success([
            'id' => $id,
            'is_read' => true,
            // Pemanggilan kedua mengembalikan waktu baca pertama, bukan waktu
            // sekarang (butir 192).
            'read_at' => $read->read_at?->toIso8601String(),
        ]);
    }

    /**
     * POST /notifications/mark-all-read — "Tandai semua notifikasi sebagai
     * dibaca".
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $marked = app(NotificationCenter::class)->markAllRead($request->user());

        return ApiResponse::success([
            'marked' => $marked,
            'unread_count' => app(NotificationCenter::class)->unreadCount($request->user()),
        ]);
    }

    /**
     * GET /admin/notifications — "Semua notifikasi yang pernah dibuat di cabang
     * ini (termasuk draft)".
     *
     * Super Admin tidak dibiarkan menerima gabungan seluruh cabang hanya karena
     * global scope melewatinya: ia wajib memilih cabang, sama seperti pada
     * operasi satu-cabang lainnya (butir 202).
     *
     * @throws AuthorizationException
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->can(PermissionName::NotificationManage->value)) {
            throw new AuthorizationException('Anda tidak berwenang membaca riwayat pengumuman.');
        }

        $filters = $request->validate([
            'school_id' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::enum(NotificationType::class)],
            'status' => ['nullable', Rule::in(['draft', 'sent'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $schoolId = $user->isSuperAdmin()
            ? $this->requireSchoolId($filters['school_id'] ?? null)
            : (int) $user->school_id;

        $query = Notification::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $schoolId)
            ->with('sender:id,name')
            ->when(
                $filters['type'] ?? null,
                fn (Builder $q, $type) => $q->where('type', $type),
            )
            ->when(
                ($filters['status'] ?? null) === 'draft',
                fn (Builder $q) => $q->draft(),
            )
            ->when(
                ($filters['status'] ?? null) === 'sent',
                fn (Builder $q) => $q->sent(),
            )
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        return ApiResponse::paginated(
            $query->paginate($filters['per_page'] ?? 25),
            AdminNotificationResource::class,
        );
    }

    /**
     * @throws ValidationException
     */
    protected function requireSchoolId(mixed $schoolId): int
    {
        if (blank($schoolId) || ! is_numeric($schoolId)) {
            throw ValidationException::withMessages([
                'school_id' => 'Cabang sekolah wajib dipilih.',
            ]);
        }

        return (int) $schoolId;
    }
}
