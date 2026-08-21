<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\UserResource;
use App\Models\User;
use App\Support\Api\ApiResponse;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * API 4.2 — Autentikasi.
 *
 * Yang diimplementasikan pada gelombang ini: login, logout, dan me. Endpoint
 * lain pada 4.2 (`/auth/refresh`, forgot/reset password, PATCH /auth/me dan
 * /auth/me/password) belum — lihat butir 121.
 */
class AuthController extends Controller
{
    /**
     * POST /auth/login — Public.
     *
     * "Login email+password → return Bearer token + user info + school config
     * (logo, colors)."
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = User::query()
            // Pencarian login berjalan lintas cabang: pengguna belum punya
            // tenant sampai identitasnya diketahui (Arsitektur 3.2.2, dan
            // SchoolScope memang tidak aktif tanpa sesi).
            ->where('email', $credentials['email'])
            ->first();

        // Pesan yang sama untuk email tidak dikenal maupun password salah:
        // membedakannya memberi tahu penyerang alamat mana yang terdaftar.
        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'Email atau password salah.',
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => 'Akun Anda tidak aktif.',
            ]);
        }

        // Event yang sama dengan login panel, sehingga `last_login_at` tetap
        // ditulis listener yang ada — lewat saveQuietly, jadi tidak ada baris
        // audit palsu untuk sekadar penanda waktu (AppServiceProvider).
        event(new Login('web', $user, false));

        $token = $user->createToken($credentials['device_name'] ?? 'api');

        $user->loadMissing('school');

        return ApiResponse::success([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'user' => (new UserResource($user))->resolve(),
        ], 'Login berhasil.');
    }

    /**
     * POST /auth/logout — Auth: "Invalidate token sesi aktif".
     *
     * Hanya token yang dipakai request ini yang dicabut. Blueprint menulis
     * "token sesi aktif" — tunggal — sehingga perangkat lain tidak ikut
     * dikeluarkan (butir 115).
     */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return ApiResponse::success(null, 'Logout berhasil.');
    }

    /**
     * GET /auth/me — Auth.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing('school');

        return ApiResponse::success((new UserResource($user))->resolve());
    }
}
