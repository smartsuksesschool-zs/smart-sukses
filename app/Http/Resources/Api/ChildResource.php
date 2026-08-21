<?php

namespace App\Http\Resources\Api;

use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API 4.11 — GET /parent/children.
 *
 * Daftar-izin. Yang sengaja **tidak** keluar:
 *
 *  - `parent_user_id` dan `school_id`: keduanya pagar kepemilikan, bukan
 *    informasi untuk orang tua. Mengirimkannya hanya memberi tahu penyerang
 *    nilai apa yang perlu ditebak.
 *  - `photo_url` mentah: berkasnya di disk privat, dan jalur penyimpanan tidak
 *    pernah menjadi bagian respons (butir 118).
 *  - catatan administratif, data orang tua lain, dan kolom internal lainnya.
 *
 * @mixin Student
 */
class ChildResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $placement = $this->activeStudentClass;

        return [
            'id' => $this->id,
            'nis' => $this->nis,
            // NISN adalah nomor induk nasional anaknya sendiri, dan orang tua
            // memang membutuhkannya untuk urusan administrasi sekolah.
            'nisn' => $this->nisn,
            'full_name' => $this->full_name,
            'status' => $this->status,
            'has_photo' => filled($this->photo_url),
            // Penempatan kelas hanya ada bila anak memang sedang terdaftar di
            // satu kelas pada tahun ajaran yang berjalan. Anak yang sudah lulus
            // atau belum ditempatkan mengembalikan NULL, bukan kelas terakhir
            // yang sudah tidak berlaku (butir 149).
            'current_class' => $placement === null ? null : [
                'id' => $placement->schoolClass?->getKey(),
                'name' => $placement->schoolClass?->name,
                'academic_year' => $placement->academicYear?->name,
            ],
        ];
    }
}
