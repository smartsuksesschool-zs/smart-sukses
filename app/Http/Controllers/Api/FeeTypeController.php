<?php

namespace App\Http\Controllers\Api;

use App\Enums\FeeFrequency;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\FeeTypeResource;
use App\Models\AcademicYear;
use App\Models\FeeType;
use App\Models\Scopes\SchoolScope;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * API 4.9.1 — /fee-types (SPP-01).
 *
 * Kewenangannya memakai FeeTypePolicy yang sama dengan panel; tidak ada aturan
 * baru di sini. Penghapusan tidak ada, sesuai SPP-01 poin 2 (butir 50).
 */
class FeeTypeController extends Controller
{
    /**
     * GET /fee-types — Auth: "daftar jenis tagihan aktif sekolah".
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', FeeType::class);

        $filters = $request->validate([
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        // SchoolScope global sudah membatasi ke cabang pengguna; Super Admin
        // memang melihat seluruh cabang (Arsitektur 3.2.2).
        $query = FeeType::query()
            ->when(
                array_key_exists('is_active', $filters) && $filters['is_active'] !== null,
                fn ($q) => $q->where('is_active', (bool) $filters['is_active']),
            )
            ->orderBy('name');

        return ApiResponse::paginated(
            $query->paginate($filters['per_page'] ?? 25),
            FeeTypeResource::class,
        );
    }

    /**
     * POST /fee-types — Admin: "buat jenis tagihan baru".
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', FeeType::class);

        $data = $this->validated($request);
        $data['school_id'] = $this->resolveSchoolId($request);

        $this->guardAcademicYear($data);

        $feeType = FeeType::query()->create($data);

        return ApiResponse::success(
            (new FeeTypeResource($feeType))->resolve(),
            'Jenis tagihan dibuat.',
            201,
        );
    }

    /**
     * PUT /fee-types/{id} — Admin: "update jenis tagihan".
     *
     * `school_id` tidak ikut berubah: memindahkan jenis tagihan antar cabang
     * memutuskannya dari tagihan siswa yang sudah terbit memakainya — larangan
     * yang sama dengan panel.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $feeType = FeeType::query()->findOrFail($id);

        $this->authorize('update', $feeType);

        $data = $this->validated($request);
        $data['school_id'] = (int) $feeType->school_id;

        $this->guardAcademicYear($data);

        unset($data['school_id']);

        $feeType->forceFill($data)->save();

        return ApiResponse::success(
            (new FeeTypeResource($feeType->fresh()))->resolve(),
            'Jenis tagihan diperbarui.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            // ERD: DECIMAL(12,2). Nominal nol atau negatif bukan tagihan.
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999.99'],
            'frequency' => ['required', Rule::enum(FeeFrequency::class)],
            'academic_year_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * Tahun ajaran wajib milik cabang jenis tagihannya — kalau tidak, satu
     * cabang dapat menautkan tagihannya ke tahun ajaran cabang lain.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    protected function guardAcademicYear(array $data): void
    {
        if (blank($data['academic_year_id'] ?? null)) {
            return;
        }

        $exists = AcademicYear::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->whereKey((int) $data['academic_year_id'])
            ->where('school_id', $data['school_id'])
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'academic_year_id' => 'Tahun ajaran tidak ditemukan pada cabang ini.',
            ]);
        }
    }

    /**
     * Cabang tempat jenis tagihan dibuat.
     *
     * Peran School Level selalu terikat cabangnya sendiri; `school_id` dari
     * payload mereka diabaikan. Super Admin tidak punya cabang dan karena itu
     * wajib memilihnya — pola yang sama dengan FeeTypeResource.
     *
     * @throws ValidationException
     */
    protected function resolveSchoolId(Request $request): int
    {
        $user = $request->user();

        if (! $user->isSuperAdmin()) {
            if ($user->school_id === null) {
                throw ValidationException::withMessages([
                    'school_id' => 'Akun Anda belum terhubung ke cabang mana pun.',
                ]);
            }

            return (int) $user->school_id;
        }

        $validated = $request->validate([
            'school_id' => ['required', 'integer', 'exists:schools,id'],
        ]);

        return (int) $validated['school_id'];
    }
}
