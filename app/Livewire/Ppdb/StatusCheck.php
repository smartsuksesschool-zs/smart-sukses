<?php

namespace App\Livewire\Ppdb;

use App\Models\PpdbRegistration;
use App\Models\School;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * PPDB-02 — "Sebagai Calon Siswa, saya dapat mengecek status pendaftaran".
 * API 4.7 — GET /ppdb/check-status (Public): "Cek status pendaftaran
 * berdasarkan nomor daftar + tanggal lahir".
 */
class StatusCheck extends Component
{
    #[Validate('required|string|max:20')]
    public string $regNumber = '';

    #[Validate('required|date')]
    public string $birthDate = '';

    /**
     * Hasil pencarian dalam bentuk data siap tampil — bukan model — agar
     * halaman publik tidak pernah membawa record utuh ke sisi klien.
     *
     * @var array<string, string|null>|null
     */
    public ?array $result = null;

    public ?School $school = null;

    public function check(): void
    {
        $this->validate();

        $this->result = null;
        $this->school = null;

        $registration = PpdbRegistration::query()
            // Endpoint publik: pencarian lintas cabang, dikunci oleh kombinasi
            // nomor pendaftaran (unik) + tanggal lahir.
            ->withoutGlobalScopes()
            ->with('school')
            ->where('reg_number', Str::upper(trim($this->regNumber)))
            ->whereDate('birth_date', $this->birthDate)
            ->first();

        if ($registration === null) {
            $this->addError('regNumber', __('Data pendaftaran tidak ditemukan. Periksa kembali nomor pendaftaran dan tanggal lahir.'));

            return;
        }

        $this->school = $registration->school;

        // PPDB-02 poin 2 — tampil status terkini.
        $this->result = [
            'reg_number' => $registration->reg_number,
            'full_name' => $registration->full_name,
            'school_name' => $registration->school?->name,
            'status' => $registration->status->value,
            'status_label' => $registration->status->label(),
            'status_notes' => $registration->status_notes,
            'registered_at' => $registration->registered_at?->format('d M Y H:i'),
        ];
    }

    public function render(): View
    {
        return view('livewire.ppdb.status-check')
            ->layout('layouts.ppdb', [
                'school' => $this->school,
                'title' => 'Cek Status Pendaftaran',
            ]);
    }
}
