<?php

namespace App\Livewire\Portal;

use App\Models\Student;
use App\Services\Portal\ParentPortalService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * PORTAL-01 — "Sebagai Orang Tua, setelah login saya dapat melihat dashboard
 * anak yang menampilkan informasi penting secara ringkas."
 *
 * Halaman penuh Livewire, mengikuti pola yang sudah dipakai halaman PPDB:
 * rute web biasa, guard `web` yang sama dengan panel, dan tata letak sendiri
 * yang mengambil warna cabang. Bukan panel Filament kedua (butir 147).
 *
 * Seluruh datanya dari ParentPortalService — service yang sama dengan yang
 * dipakai endpoint API, sehingga layar dan API tidak dapat berbeda tentang
 * anak siapa yang boleh dilihat maupun berapa tagihan yang tertunggak.
 */
class ParentDashboard extends Component
{
    /**
     * Anak yang sedang dilihat.
     *
     * Disimpan sebagai id di state komponen Livewire, bukan dibaca dari query
     * string: apa pun yang dikirim akan diperiksa ulang terhadap daftar anak
     * milik orang tua ini sebelum dipakai (butir 156).
     */
    public ?int $selectedChildId = null;

    public function mount(): void
    {
        $this->selectedChildId = $this->children()->first()?->getKey();
    }

    /**
     * Berpindah profil anak — PORTAL-01 poin 2.
     *
     * Id yang tidak ada di daftar anak miliknya diabaikan sepenuhnya, dan
     * pilihannya tetap pada anak sebelumnya. Tidak ada pesan yang membedakan
     * "anak orang lain" dari "anak tidak ada": keduanya sama-sama bukan
     * urusannya.
     */
    public function selectChild(int $studentId): void
    {
        if ($this->children()->contains(fn (Student $child) => $child->getKey() === $studentId)) {
            $this->selectedChildId = $studentId;
        }
    }

    /**
     * Anak-anak milik orang tua ini.
     *
     * Diambil sekali per request lalu ditahan: dashboard membacanya beberapa
     * kali (pemilih, ringkasan, judul) dan tidak boleh menghasilkan query
     * berulang.
     *
     * @return Collection<int, Student>
     */
    public function children(): Collection
    {
        return once(fn () => app(ParentPortalService::class)->children(Auth::user()));
    }

    /**
     * Ringkasan anak terpilih, atau NULL bila orang tua ini belum punya anak
     * yang tertaut sama sekali.
     *
     * @return array<string, mixed>|null
     */
    public function summary(): ?array
    {
        if ($this->selectedChildId === null) {
            return null;
        }

        return app(ParentPortalService::class)
            ->summary(Auth::user(), $this->selectedChildId);
    }

    public function render(): View
    {
        $summary = $this->summary();

        return view('livewire.portal.parent-dashboard', [
            'children' => $this->children(),
            'summary' => $summary,
            // PORTAL-01 poin 1 menyebut "3 nilai terbaru"; API menyediakan
            // lima. Yang dipotong tampilannya, bukan datanya (butir 150).
            'grades' => array_slice(
                $summary['latest_grades'] ?? [],
                0,
                ParentPortalService::DASHBOARD_SUBJECTS,
            ),
        ])->layout('layouts.portal', [
            'title' => 'Dashboard Orang Tua',
        ]);
    }
}
