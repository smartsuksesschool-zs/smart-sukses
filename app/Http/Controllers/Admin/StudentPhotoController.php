<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Support\StudentPhoto;
use App\Support\TeacherClassVisibility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Foto siswa, satu-satunya jalan menuju berkasnya (butir 587).
 *
 * Rutenya didaftarkan lewat `authenticatedRoutes()` milik panel, sama seperti
 * unduhan berkas PPDB, sehingga ia melewati tumpukan middleware yang persis
 * sama dengan halaman panel. SISWA dan ORANG_TUA tidak pernah sampai ke sini:
 * panel menolak keduanya, dan portal mereka memang hanya menerima `has_photo`,
 * bukan fotonya.
 *
 * Tiga pagar, masing-masing untuk pertanyaan yang berbeda:
 *
 *  1. Route model binding menjalankan SchoolScope — siswa cabang lain tidak
 *     ditemukan (404), dan keberadaannya pun tidak terbocorkan.
 *  2. TeacherClassVisibility — guru hanya melihat foto siswa yang memang
 *     tampil di daftar siswanya, persis seperti StudentResource.
 *  3. `StudentPolicy::view` — izin modul dan cabang, apa adanya. Super Admin
 *     lolos lewat `Gate::before` (Arsitektur 3.2.2). Tidak ada izin baru.
 */
class StudentPhotoController extends Controller
{
    public function __invoke(Request $request, Student $student): StreamedResponse
    {
        $visible = TeacherClassVisibility::constrainStudents(
            Student::query()->whereKey($student->getKey()),
            $request->user(),
        )->exists();

        abort_unless($visible, 404);

        Gate::authorize('view', $student);

        // Jalurnya dibaca dari basis data dan disaring; tidak pernah dirakit
        // dari request. Berkas yang hilang adalah 404, bukan galat 500.
        $path = StudentPhoto::storedPath($student);

        abort_if($path === null, 404);

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return Storage::disk(StudentPhoto::disk())->response(
            $path,
            "foto-siswa-{$student->getKey()}.{$extension}",
            [
                // Hanya cache peramban pengguna ini; proxy di antaranya tidak
                // boleh menyimpan foto anak untuk dibagikan ke permintaan lain.
                'Cache-Control' => 'private, max-age=300',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
