<?php

namespace App\Livewire\Auth;

use App\Enums\AccountClaimStatus;
use App\Enums\AccountClaimType;
use App\Enums\AuthProvider;
use App\Enums\RoleName;
use App\Models\AccountClaim;
use App\Models\School;
use App\Models\User;
use App\Services\Auth\AccountClaimRegistrar;
use App\Services\Auth\StudentClaimMatcher;
use App\Support\GoogleIdentity;
use App\Support\LoginDestination;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Menghubungkan identitas Google yang sudah terbukti dengan sekolah.
 *
 * Halaman ini hanya dapat dibuka oleh seseorang yang baru saja menyelesaikan
 * OAuth: syaratnya identitas di sesi, yang hanya ditulis callback. Membuka
 * alamatnya langsung tanpa itu berakhir di halaman masuk.
 *
 * Tiga jenis pemohon, dan **tidak satu pun di antaranya berupa peran**:
 *
 *  - `SISWA` dan `ORANG_TUA` — dicocokkan dengan NIS + NISN;
 *  - `STAF_SEKOLAH` — hanya memilih cabang, karena tidak ada pengenal induk
 *    pegawai yang dapat dicocokkan (butir 545).
 *
 * Yang terakhir itulah yang membuat pemisahan jenis dari peran menjadi penting.
 * Seorang staf tidak pernah mengatakan "saya guru": ia mengatakan "saya bekerja
 * di sini", dan **admin** yang menentukan ia guru, wali kelas, bendahara, atau
 * kepala sekolah (butir 544, 547).
 *
 * Ia punya tiga keadaan, dan hanya satu yang berupa formulir:
 *
 *  - `form`     — belum ada permintaan;
 *  - `pending`  — sudah ada yang menunggu admin (§13);
 *  - `rejected` — yang terakhir ditolak.
 *
 * Ada satu pengecualian pada syarat "belum punya akun": **orang tua yang sudah
 * masuk dan hendak mendaftarkan anak berikutnya**. Tanpa itu, alur anak kedua
 * mustahil dijalankan — begitu anak pertama disetujui, akun orang tuanya sudah
 * ada, sehingga setiap "Masuk dengan Google" berikutnya langsung mendarat di
 * portal dan halaman ini tidak pernah lagi terbuka. Identitasnya waktu itu
 * tidak dibaca dari sesi melainkan dari akunnya sendiri, dan jenisnya terkunci
 * ORANG_TUA: akun yang sudah ada tidak dapat memakai halaman ini untuk meminta
 * apa pun yang lain (butir 537).
 *
 * Seluruh kegagalan pencocokan memakai satu kalimat yang sama. NIS dan NISN
 * bukan rahasia, tetapi pesan yang berbeda-beda akan menjadikan halaman ini
 * alat menebak keduanya: "NIS tidak ditemukan" dan "NIS benar, NISN salah"
 * adalah dua jawaban yang sangat berbeda nilainya bagi orang yang menebak
 * (butir 533).
 */
class GoogleClaim extends Component
{
    /** Jenis permintaan — nilai `AccountClaimType`, bukan peran. */
    public string $type = '';

    public string $nis = '';

    public string $nisn = '';

    /** Cabang yang dipilih pemohon staf. */
    public ?string $schoolId = null;

    /** `form`, `pending`, atau `rejected`. */
    public string $state = 'form';

    /**
     * Satu kalimat untuk seluruh kegagalan pencocokan.
     */
    public const REFUSED = 'Data tidak dapat dicocokkan.';

    public const PENDING_MESSAGE = 'Permintaan akun Anda sedang menunggu persetujuan admin.';

    public const REJECTED_MESSAGE = 'Permintaan akun belum dapat disetujui.';

    /**
     * Percobaan pencocokan per sepuluh menit, per identitas Google dan IP.
     *
     * Lebih ketat daripada halaman masuk berkata sandi. Yang dilindungi di sini
     * bukan satu akun melainkan seluruh daftar siswa: percobaan berulang atas
     * NIS yang berganti-ganti adalah pemindaian, bukan lupa.
     */
    public const MAX_ATTEMPTS = 5;

    public const DECAY_SECONDS = 600;

    protected ?GoogleIdentity $identity = null;

    public function mount(AccountClaimRegistrar $registrar): void
    {
        if ($this->isAddingAnotherChild()) {
            // Jenisnya tidak ditanyakan: akun yang sudah ada tidak dapat
            // meminta jenis kedua lewat halaman ini.
            $this->type = AccountClaimType::OrangTua->value;
        } else {
            // Sudah punya akun dan sudah masuk: tidak ada yang perlu dicocokkan.
            $destination = LoginDestination::urlFor(Auth::user());

            if ($destination !== null) {
                $this->redirect($destination, navigate: false);

                return;
            }
        }

        $identity = $this->identity();

        if ($identity === null) {
            $this->redirect(route('login'), navigate: false);

            return;
        }

        $this->syncStateFrom($registrar, $identity);
    }

    /**
     * Orang tua yang sudah punya akun Google dan sedang menambah anak.
     *
     * Syaratnya sempit dengan sengaja: aktif, tepat berperan ORANG_TUA, dan
     * akunnya memang lahir dari Google. Akun staf yang kebetulan membuka
     * alamat ini tidak memenuhi satu pun di antaranya.
     */
    public function isAddingAnotherChild(): bool
    {
        $user = Auth::user();

        if (! $user instanceof User || ! $user->is_active) {
            return false;
        }

        if ($user->auth_provider !== AuthProvider::Google || blank($user->provider_subject)) {
            return false;
        }

        return LoginDestination::soleRoleOf($user) === RoleName::OrangTua;
    }

    public function submit(
        AccountClaimRegistrar $registrar,
        StudentClaimMatcher $matcher,
    ): void {
        $identity = $this->identity();

        if ($identity === null) {
            $this->redirect(route('login'), navigate: false);

            return;
        }

        // Keadaan dibaca ulang: permintaan dapat saja sudah masuk lewat tab lain.
        $this->syncStateFrom($registrar, $identity);

        if ($this->state !== 'form') {
            return;
        }

        $this->validate([
            'type' => ['required', 'string', 'in:'.implode(',', $this->selfServiceTypeValues())],
        ]);

        $type = AccountClaimType::from($this->type);

        $claim = $type->matchesStudent()
            ? $this->submitStudentClaim($registrar, $matcher, $identity, $type)
            : $this->submitStaffClaim($registrar, $identity);

        // Tanpa NIS, tanpa NISN, tanpa surel.
        Log::info('account claim submitted', [
            'claim_id' => $claim->getKey(),
            'requested_type' => $type->value,
            'student_id' => $claim->student_id,
        ]);

        $this->reset('nis', 'nisn');
        $this->state = 'pending';
    }

    /**
     * SISWA / ORANG_TUA — dicocokkan dengan data induk.
     */
    protected function submitStudentClaim(
        AccountClaimRegistrar $registrar,
        StudentClaimMatcher $matcher,
        GoogleIdentity $identity,
        AccountClaimType $type,
    ): AccountClaim {
        $this->validate([
            'nis' => ['required', 'string', 'max:32'],
            'nisn' => ['required', 'string', 'max:32'],
        ]);

        $this->ensureIsNotRateLimited($identity);

        /*
         * Peran yang dipakai matcher diturunkan dari **jenisnya**, bukan dari
         * request: ia hanya menentukan kolom tautan mana yang harus masih
         * kosong, dan nilainya tidak pernah dapat dipengaruhi dari luar.
         */
        $match = $matcher->match($this->nis, $this->nisn, $type->impliedRole());

        if (! $match->isMatch()) {
            $this->failMatch($match->outcome->value);
        }

        RateLimiter::clear($this->throttleKey($identity));

        return $registrar->registerForStudent($identity, $type, $match->student);
    }

    /**
     * STAF_SEKOLAH — tidak ada yang dicocokkan, hanya cabangnya yang dipilih.
     */
    protected function submitStaffClaim(
        AccountClaimRegistrar $registrar,
        GoogleIdentity $identity,
    ): AccountClaim {
        // Satu cabang yang layak: diisi sendiri, karena pemilihnya memang tidak
        // ditampilkan. Nilainya tetap melewati validasi yang sama di bawah —
        // yang dilewati hanya pertanyaannya, bukan pemeriksaannya.
        if ($this->schoolChoiceIsImplicit()) {
            $this->schoolId = (string) array_key_first($this->schoolOptions());
        }

        $this->validate(
            ['schoolId' => ['required', 'integer', 'in:'.implode(',', array_keys($this->schoolOptions()))]],
            attributes: ['schoolId' => __('Cabang')],
        );

        $this->ensureIsNotRateLimited($identity);

        $school = School::query()->active()->findOrFail((int) $this->schoolId);

        RateLimiter::clear($this->throttleKey($identity));

        return $registrar->registerForStaff($identity, $school);
    }

    /**
     * Membatalkan permintaan sendiri, supaya dapat mengirim ulang yang benar.
     */
    public function cancel(AccountClaimRegistrar $registrar): void
    {
        $identity = $this->identity();

        if ($identity === null) {
            $this->redirect(route('login'), navigate: false);

            return;
        }

        $pending = $registrar->anyPendingFor($identity);

        if ($pending !== null) {
            $registrar->cancel($identity, $pending);
        }

        $this->reset('type', 'nis', 'nisn', 'schoolId');
        $this->state = 'form';
    }

    /**
     * Keluar dari alur ini dan melepaskan identitas yang dititipkan ke sesi.
     */
    public function abandon(): void
    {
        GoogleIdentity::forgetSession();

        $destination = LoginDestination::urlFor(Auth::user());

        $this->redirect($destination ?? route('login'), navigate: false);
    }

    /**
     * @return array<int, string>
     */
    public function typeOptions(): array
    {
        return $this->selfServiceTypeValues();
    }

    /**
     * Cabang aktif yang dapat dipilih pemohon staf.
     *
     * @return array<int, string>
     */
    public function schoolOptions(): array
    {
        return app(AccountClaimRegistrar::class)->selectableSchools();
    }

    /**
     * Formulir sedang menanyakan cabang, bukan NIS/NISN.
     */
    public function needsSchoolChoice(): bool
    {
        return $this->type === AccountClaimType::StafSekolah->value;
    }

    /**
     * Pemilih cabang tidak perlu ditampilkan.
     *
     * Ketika hanya satu cabang yang menerima pendaftaran — keadaan sejak
     * cabang Bandung disembunyikan (M7.2) — sebuah dropdown berisi satu pilihan
     * bukan pilihan melainkan pekerjaan tambahan. Cabangnya diisi sendiri dan
     * disebutkan sebagai keterangan, sehingga pemohon tetap tahu ke mana
     * permintaannya pergi (butir 557).
     *
     * Aturannya diturunkan dari data, bukan dari nama cabang: begitu cabang
     * kedua diaktifkan lagi, pemilihnya muncul kembali tanpa satu baris pun
     * yang perlu diubah.
     */
    public function schoolChoiceIsImplicit(): bool
    {
        return $this->needsSchoolChoice() && count($this->schoolOptions()) === 1;
    }

    /**
     * Nama cabang tunggal yang diisi sendiri, bila memang hanya satu.
     */
    public function implicitSchoolName(): ?string
    {
        $options = $this->schoolOptions();

        return count($options) === 1 ? (string) reset($options) : null;
    }

    public function identityEmail(): string
    {
        return $this->identity()?->email ?? '';
    }

    protected function syncStateFrom(AccountClaimRegistrar $registrar, GoogleIdentity $identity): void
    {
        if ($registrar->anyPendingFor($identity) !== null) {
            $this->state = 'pending';

            return;
        }

        $latest = $registrar->latestFor($identity);

        /*
         * Layar "belum dapat disetujui" hanya berlaku bagi yang belum punya
         * akun. Orang tua yang sudah masuk dan pernah ditolak permintaan anak
         * keduanya tetap harus dapat mencoba lagi — kalau tidak, satu penolakan
         * mengunci seluruh anaknya yang lain selamanya.
         */
        $this->state = ($latest?->status === AccountClaimStatus::Rejected && ! $this->isAddingAnotherChild())
            ? 'rejected'
            : 'form';
    }

    /**
     * Identitas penyedia yang sedang dipegang pemanggil.
     *
     * Bagi orang tua yang sudah masuk, ia dibaca dari **akunnya sendiri**, bukan
     * dari sesi OAuth: sesi itu sudah dilepas ketika ia masuk, dan membacanya
     * lagi dari sana berarti mempercayai nilai yang tidak lagi dijaga siapa pun.
     */
    protected function identity(): ?GoogleIdentity
    {
        if ($this->identity !== null) {
            return $this->identity;
        }

        if ($this->isAddingAnotherChild()) {
            /** @var User $user */
            $user = Auth::user();

            return $this->identity = new GoogleIdentity(
                (string) $user->provider_subject,
                (string) $user->email,
                $user->name,
            );
        }

        return $this->identity = GoogleIdentity::fromSession();
    }

    /**
     * @return array<int, string>
     */
    protected function selfServiceTypeValues(): array
    {
        if ($this->isAddingAnotherChild()) {
            return [AccountClaimType::OrangTua->value];
        }

        return array_map(
            fn (AccountClaimType $type) => $type->value,
            AccountClaim::SELF_SERVICE_TYPES,
        );
    }

    /**
     * @throws ValidationException
     */
    protected function failMatch(string $diagnosis): never
    {
        RateLimiter::hit($this->throttleKey($this->identity()), self::DECAY_SECONDS);

        // Hanya keputusannya. NIS dan NISN yang dicoba tidak ikut dicatat: log
        // yang memuat keduanya adalah daftar tebakan yang tersimpan rapi
        // (butir 540).
        Log::info('account claim match refused', [
            'outcome' => $diagnosis,
            'ip' => request()->ip(),
        ]);

        throw ValidationException::withMessages(['nis' => __(self::REFUSED)]);
    }

    /**
     * @throws ValidationException
     */
    protected function ensureIsNotRateLimited(GoogleIdentity $identity): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey($identity), self::MAX_ATTEMPTS)) {
            return;
        }

        $message = __('Terlalu banyak percobaan. Coba lagi dalam :seconds detik.', [
            'seconds' => RateLimiter::availableIn($this->throttleKey($identity)),
        ]);

        throw ValidationException::withMessages([
            ($this->needsSchoolChoice() ? 'schoolId' : 'nis') => $message,
        ]);
    }

    protected function throttleKey(?GoogleIdentity $identity): string
    {
        return 'account-claim:'.sha1($identity?->subject ?? 'anon').'|'.request()->ip();
    }

    public function render(): View
    {
        return view('livewire.auth.google-claim')
            ->layout('layouts.landing', [
                'title' => config('app.name').' — '.__('Hubungkan Akun'),
            ]);
    }
}
