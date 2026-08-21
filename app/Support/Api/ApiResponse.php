<?php

namespace App\Support\Api;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Amplop respons API — API 4.1.
 *
 * Sukses: `{ "success": true, "data": {...}, "message": "..." }`
 * Galat:  `{ "success": false, "message": "...", "errors": {...} }`
 *
 * Bentuknya ditulis di satu tempat supaya tidak ada endpoint yang perlahan
 * menyimpang: klien yang menguraikan `success` dan `data` harus dapat
 * mengandalkan keduanya ada pada setiap respons, termasuk respons galat yang
 * dibentuk handler exception.
 */
class ApiResponse
{
    public static function success(mixed $data = null, ?string $message = null, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => static::normalize($data),
            'message' => $message,
        ], $status);
    }

    /**
     * @param  array<string, mixed>|null  $errors
     */
    public static function error(string $message, ?array $errors = null, int $status = 400): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], $status);
    }

    /**
     * Daftar berhalaman — API 4.1:
     * `{ "data": [...], "meta": { "total", "page", "per_page", "last_page" } }`
     *
     * `meta` ditulis sendiri, bukan memakai bawaan Laravel: kunci bawaannya
     * `current_page`, sedangkan blueprint menyebut `page`. Tautan `links` juga
     * tidak disertakan karena tidak ada di konvensi.
     */
    public static function paginated(
        LengthAwarePaginator $paginator,
        string $resourceClass,
        ?string $message = null,
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'data' => $resourceClass::collection($paginator->items())->resolve(),
            'meta' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
            ],
            'message' => $message,
        ]);
    }

    /**
     * API Resource diselesaikan lebih dulu supaya `data` selalu berupa
     * array/nilai biasa, bukan objek resource yang akan membungkus dirinya
     * sendiri dengan kunci `data` kedua.
     */
    protected static function normalize(mixed $data): mixed
    {
        if ($data instanceof ResourceCollection || $data instanceof JsonResource) {
            return $data->resolve();
        }

        return $data;
    }
}
