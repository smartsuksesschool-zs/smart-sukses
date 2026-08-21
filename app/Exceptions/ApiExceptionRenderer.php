<?php

namespace App\Exceptions;

use App\Support\Api\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Menerjemahkan exception pada jalur API menjadi amplop galat API 4.1.
 *
 * Tanpa ini Laravel akan mengembalikan bentuknya sendiri — `{"message": ...}`
 * untuk 401/403, `{"message": ..., "errors": {...}}` untuk 422 — yang mirip
 * tetapi tidak punya kunci `success`. Klien yang memeriksa `success` akan
 * membaca respons galat sebagai sukses.
 *
 * Hanya berlaku pada request yang memang menuju API; panel Filament tetap
 * memakai penanganan bawaan Laravel sepenuhnya.
 */
class ApiExceptionRenderer
{
    public static function handles(Request $request): bool
    {
        return $request->is('api/*');
    }

    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! static::handles($request)) {
            return null;
        }

        return match (true) {
            $e instanceof ValidationException => ApiResponse::error(
                'Data yang dikirim tidak valid.',
                $e->errors(),
                422,
            ),

            $e instanceof AuthenticationException => ApiResponse::error(
                'Token tidak valid atau sudah tidak berlaku.',
                null,
                401,
            ),

            $e instanceof AuthorizationException => ApiResponse::error(
                $e->getMessage() !== '' ? $e->getMessage() : 'Anda tidak berwenang melakukan tindakan ini.',
                null,
                403,
            ),

            // Record milik cabang lain diselesaikan sebagai 404, bukan 403:
            // 403 mengonfirmasi bahwa id itu ada di suatu cabang, dan itu
            // sendiri sudah membocorkan sesuatu (butir 116).
            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException => ApiResponse::error(
                'Data tidak ditemukan.',
                null,
                404,
            ),

            $e instanceof TooManyRequestsHttpException => ApiResponse::error(
                'Terlalu banyak permintaan. Coba lagi beberapa saat lagi.',
                null,
                429,
            ),

            default => static::fallback($e, $request),
        };
    }

    /**
     * Exception lain: pesan HTTP-nya boleh keluar, sisanya tidak.
     *
     * Di luar mode debug, galat tak terduga tidak pernah membawa pesan
     * exception maupun jejak tumpukan — keduanya dapat memuat nama tabel,
     * potongan query, dan jalur berkas server.
     */
    protected static function fallback(Throwable $e, Request $request): JsonResponse
    {
        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();

            return ApiResponse::error(
                $e->getMessage() !== '' ? $e->getMessage() : 'Permintaan tidak dapat diproses.',
                null,
                $status,
            );
        }

        return ApiResponse::error(
            config('app.debug') ? $e->getMessage() : 'Terjadi kesalahan pada server.',
            null,
            500,
        );
    }
}
