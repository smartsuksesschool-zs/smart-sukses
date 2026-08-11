<?php

namespace App\Livewire\Ppdb;

use App\Enums\Gender;
use App\Models\AcademicYear;
use App\Models\PpdbRegistration;
use App\Models\School;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * PPDB-01 — "Sebagai Calon Siswa / Orang Tua, saya dapat mengisi formulir
 * pendaftaran PPDB secara online tanpa perlu login."
 *
 * API 4.7 — GET /ppdb/{schoolCode}/info + POST /ppdb/{schoolCode}/register (Public).
 */
class RegistrationForm extends Component
{
    use WithFileUploads;

    public School $school;

    // PPDB-01 poin 2 — Form: nama lengkap, jenis kelamin, tanggal lahir,
    // asal sekolah, nama ortu, no. HP, email.
    public string $full_name = '';

    public string $gender = '';

    public string $birth_date = '';

    public string $origin_school = '';

    public string $parent_name = '';

    public string $parent_phone = '';

    public string $parent_email = '';

    /**
     * ERD 2.2 — ppdb_registrations.documents (array URL berkas yang diunggah).
     *
     * @var array<int, mixed>
     */
    public array $documents = [];

    /**
     * PPDB-01 poin 3 — nomor pendaftaran unik yang tampil setelah submit.
     */
    public ?string $regNumber = null;

    public function mount(string $schoolCode): void
    {
        // PRD PPDB-01 poin 1 — URL publik: /ppdb/[kode_sekolah].
        $this->school = School::query()
            ->active()
            ->where('code', Str::upper($schoolCode))
            ->firstOrFail();
    }

    /**
     * ERD 2.2 — academic_year_id: "tahun ajaran yang didaftar".
     *
     * Di-resolve per request (bukan disimpan sebagai properti Livewire) supaya
     * halaman publik tidak bergantung pada global scope tenant milik pengunjung
     * yang kebetulan sedang login di cabang lain.
     */
    protected function academicYear(): ?AcademicYear
    {
        return AcademicYear::forSchool($this->school->id)->active()->first();
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:150'],
            'gender' => ['required', Rule::in(array_column(Gender::cases(), 'value'))],
            // Wajib di formulir publik: dipakai sebagai kunci cek status (API 4.7).
            'birth_date' => ['required', 'date', 'before:today'],
            'origin_school' => ['nullable', 'string', 'max:150'],
            'parent_name' => ['required', 'string', 'max:150'],
            // Wajib: menjadi tujuan link wa.me pada PPDB-04.
            'parent_phone' => ['required', 'string', 'max:20'],
            'parent_email' => ['nullable', 'email', 'max:150'],
            'documents' => ['nullable', 'array', 'max:5'],
            // Arsitektur 3.4 — File Upload: hanya JPG/PNG/PDF, validasi MIME + ukuran.
            'documents.*' => ['file', 'mimes:jpg,jpeg,png,pdf', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'full_name' => 'nama lengkap',
            'gender' => 'jenis kelamin',
            'birth_date' => 'tanggal lahir',
            'origin_school' => 'asal sekolah',
            'parent_name' => 'nama orang tua',
            'parent_phone' => 'no. HP orang tua',
            'parent_email' => 'email orang tua',
            'documents.*' => 'berkas',
        ];
    }

    public function submit(): void
    {
        $data = $this->validate();

        $registration = PpdbRegistration::registerPublicly($this->school, [
            'academic_year_id' => $this->academicYear()?->id,
            'full_name' => $data['full_name'],
            'gender' => $data['gender'],
            'birth_date' => $data['birth_date'],
            'origin_school' => $data['origin_school'] ?: null,
            'parent_name' => $data['parent_name'],
            'parent_phone' => $data['parent_phone'],
            'parent_email' => $data['parent_email'] ?: null,
            'documents' => $this->storeDocuments(),
        ]);

        $this->regNumber = $registration->reg_number;

        $this->reset([
            'full_name', 'gender', 'birth_date', 'origin_school',
            'parent_name', 'parent_phone', 'parent_email', 'documents',
        ]);
    }

    /**
     * @return array<int, string>|null
     */
    protected function storeDocuments(): ?array
    {
        if ($this->documents === []) {
            return null;
        }

        $paths = [];

        foreach ($this->documents as $document) {
            $paths[] = $document->store('ppdb/'.Str::lower($this->school->code), 'public');
        }

        return $paths;
    }

    public function render(): View
    {
        return view('livewire.ppdb.registration-form', [
            'genders' => Gender::options(),
            'academicYear' => $this->academicYear(),
        ])->layout('layouts.ppdb', [
            'school' => $this->school,
            'title' => 'Formulir Pendaftaran',
        ]);
    }
}
