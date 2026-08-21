<?php

namespace App\Livewire\Portal\Concerns;

use App\Models\Student;
use App\Services\Portal\ParentPortalService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Session;

/**
 * Satu mekanisme pemilihan anak untuk seluruh halaman portal.
 *
 * Empat halaman — ringkasan, nilai, tagihan, jadwal — memakai trait yang sama,
 * bukan empat pemilih yang berdiri sendiri. Kalau masing-masing menyimpan
 * pilihannya sendiri, orang tua dengan dua anak akan melihat anak yang berbeda
 * di tiap tab tanpa pernah merasa berpindah (butir 167).
 *
 * Pilihannya disimpan di sesi, bukan di query string: nilai dari URL adalah
 * masukan pengguna, dan menjadikannya sumber kebenaran berarti mengundang
 * percobaan mengganti id anak lewat alamat. Apa pun yang tersimpan tetap
 * diperiksa ulang terhadap daftar anak miliknya sebelum dipakai.
 */
trait SelectsChild
{
    /**
     * Id anak yang sedang dilihat.
     *
     * `#[Session]` membuatnya bertahan antar halaman portal dalam satu sesi
     * pengguna — dan hanya di sana.
     */
    #[Session(key: 'portal.selected-child')]
    public ?int $selectedChildId = null;

    public function mountSelectsChild(): void
    {
        $this->ensureSelectedChildIsOwned();
    }

    /**
     * Berpindah profil anak — PORTAL-01 poin 2.
     *
     * Id yang bukan miliknya diabaikan sepenuhnya, dan pilihannya tetap pada
     * anak sebelumnya. Tidak ada pesan yang membedakan "anak orang lain" dari
     * "anak tidak ada": keduanya sama-sama bukan urusannya (butir 156).
     */
    public function selectChild(int $studentId): void
    {
        if ($this->owns($studentId)) {
            $this->selectedChildId = $studentId;
        }
    }

    /**
     * Anak-anak milik orang tua ini, diambil sekali per request.
     *
     * @return Collection<int, Student>
     */
    public function children(): Collection
    {
        return once(fn () => app(ParentPortalService::class)->children(Auth::user()));
    }

    public function selectedChild(): ?Student
    {
        if ($this->selectedChildId === null) {
            return null;
        }

        return $this->children()
            ->first(fn (Student $child) => $child->getKey() === $this->selectedChildId);
    }

    protected function owns(int $studentId): bool
    {
        return $this->children()->contains(fn (Student $child) => $child->getKey() === $studentId);
    }

    /**
     * Menjaga pilihan yang tersimpan tetap sah.
     *
     * Sesi dapat membawa id dari keadaan yang sudah berubah — anak yang
     * tautannya dicabut, atau nilai yang memang tidak pernah miliknya. Dalam
     * kedua hal itu pilihannya jatuh kembali ke anak pertama, bukan
     * dipertahankan.
     */
    protected function ensureSelectedChildIsOwned(): void
    {
        if ($this->selectedChildId !== null && $this->owns($this->selectedChildId)) {
            return;
        }

        $this->selectedChildId = $this->children()->first()?->getKey();
    }
}
