<?php

namespace App\Models;

use App\Enums\AccountClaimStatus;
use App\Enums\AccountClaimType;
use App\Enums\AuthProvider;
use App\Enums\RoleName;
use App\Models\Concerns\BelongsToSchool;
use App\Models\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Permintaan akun dari identitas Google — keputusan pemilik (M7 / M7.1).
 *
 * Lihat migrasi `create_account_claims_table` untuk alasan tabel ini ada
 * sama sekali, dan docs/auth/google-account-claims.md untuk ketiga alurnya utuh.
 */
class AccountClaim extends Model
{
    use BelongsToSchool;

    protected $fillable = [
        'school_id',
        'provider',
        'provider_subject',
        'email',
        'name',
        'requested_type',
        'approved_role',
        'student_id',
        'status',
        'requested_at',
        'reviewed_at',
        'reviewed_by',
        'review_notes',
        'approved_user_id',
    ];

    /**
     * Jenis permintaan yang boleh diajukan dari jalur publik.
     *
     * Daftar putih, bukan daftar hitam: jenis baru tidak akan diam-diam menjadi
     * dapat diminta sendiri. Ia memuat seluruh `AccountClaimType` hari ini —
     * dan justru karena itulah pemisahannya dari `RoleName` penting: yang
     * dibatasi bukan daftar ini, melainkan apa yang dapat dihasilkannya
     * (butir 544).
     *
     * @var array<int, AccountClaimType>
     */
    public const SELF_SERVICE_TYPES = [
        AccountClaimType::Siswa,
        AccountClaimType::OrangTua,
        AccountClaimType::StafSekolah,
    ];

    /**
     * Peran yang boleh **diberikan admin** kepada permintaan staf.
     *
     * SCHOOL_ADMIN dan SUPER_ADMIN sengaja tidak ada di sini, dan tidak boleh
     * ditambahkan. Keduanya adalah peran yang dapat membuat pengguna lain —
     * SUPER_ADMIN bahkan melewati seluruh policy lewat `Gate::before`. Sebuah
     * jalur publik yang dapat berakhir di salah satunya berarti pendaftaran
     * mandiri yang, dengan satu kekeliruan seorang admin, menyerahkan seluruh
     * platform.
     *
     * SCHOOL_ADMIN tetap dibuat lewat jalur admin yang sudah ada
     * (`UserResource`), tempat pembuatnya sudah terbukti dan tercatat.
     * SUPER_ADMIN tetap milik platform (butir 547).
     *
     * @var array<int, RoleName>
     */
    public const REVIEWER_ASSIGNABLE_ROLES = [
        RoleName::Guru,
        RoleName::WaliKelas,
        RoleName::Bendahara,
        RoleName::KepalaSekolah,
    ];

    protected function casts(): array
    {
        return [
            'status' => AccountClaimStatus::class,
            'requested_type' => AccountClaimType::class,
            'approved_role' => RoleName::class,
            'provider' => AuthProvider::class,
            'requested_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * Kunci keunikan diturunkan dari status, tidak pernah diisi pemanggil.
     *
     * Ini satu-satunya tempat keduanya ditulis. Kalau pemanggil yang mengisinya,
     * cepat atau lambat ada satu jalur yang lupa — dan yang hilang bukan sebuah
     * kolom, melainkan jaminan basis data bahwa satu siswa hanya punya satu
     * pemilik akun (butir 531).
     */
    protected static function booted(): void
    {
        static::saving(function (self $claim): void {
            $status = $claim->status instanceof AccountClaimStatus
                ? $claim->status
                : AccountClaimStatus::tryFrom((string) $claim->status);

            $claim->pending_key = $status === AccountClaimStatus::Pending
                ? $claim->uniquenessKey()
                : null;

            /*
             * Aturan "satu permintaan disetujui per siswa per jenis" hanya
             * berlaku bagi permintaan yang memang menunjuk seorang siswa.
             *
             * Untuk staf ia tidak punya arti — tidak ada siswa yang dapat
             * direbut — dan yang menahannya justru indeks unik
             * `(auth_provider, provider_subject)` pada `users`: satu identitas
             * Google hanya dapat memiliki satu akun, jadi persetujuan staf
             * kedua atas identitas yang sama tidak punya tempat untuk mendarat.
             */
            $claim->approved_key = ($status === AccountClaimStatus::Approved && $claim->student_id !== null)
                ? $claim->requestedTypeValue().':'.$claim->student_id
                : null;
        });
    }

    /**
     * Kunci sebuah permintaan yang sedang menunggu.
     *
     * Ketiadaan siswa disusun menjadi nilai yang tetap dibandingkan (`-`),
     * bukan dibiarkan NULL — lihat butir 548. Tanpa itu, seorang pemohon staf
     * dapat menumpuk permintaan menunggu sebanyak yang ia mau.
     */
    protected function uniquenessKey(): string
    {
        return implode(':', [
            $this->providerValue(),
            (string) $this->provider_subject,
            $this->requestedTypeValue(),
            $this->student_id === null ? '-' : (string) $this->student_id,
        ]);
    }

    protected function providerValue(): string
    {
        return $this->provider instanceof AuthProvider
            ? $this->provider->value
            : (string) $this->provider;
    }

    protected function requestedTypeValue(): string
    {
        return $this->requested_type instanceof AccountClaimType
            ? $this->requested_type->value
            : (string) $this->requested_type;
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Akun yang lahir dari persetujuan ini.
     */
    public function approvedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_user_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', AccountClaimStatus::Pending->value);
    }

    /**
     * Permintaan dari satu identitas penyedia, lintas cabang.
     *
     * Tanpa scope tenant: pemohonnya tamu, dan tamu tidak punya cabang. Yang
     * membatasi hasilnya adalah identitas penyedia itu sendiri, yang jauh lebih
     * sempit daripada satu cabang.
     */
    public function scopeForIdentity(Builder $query, AuthProvider $provider, string $subject): Builder
    {
        return $query->withoutGlobalScope(SchoolScope::class)
            ->where('provider', $provider->value)
            ->where('provider_subject', $subject);
    }

    public function isReviewable(): bool
    {
        return $this->status->isReviewable();
    }

    /**
     * Admin harus memilih peran hasilnya sebelum permintaan ini dapat disetujui.
     */
    public function requiresRoleChoice(): bool
    {
        return $this->requested_type->requiresRoleChoice();
    }

    /**
     * Peran hasil sebuah persetujuan.
     *
     * Untuk siswa dan orang tua ia mengikuti jenisnya dan pilihan admin
     * diabaikan; untuk staf, hanya pilihan admin yang berlaku.
     */
    public function resolvedRole(?RoleName $chosen): ?RoleName
    {
        return $this->requested_type->impliedRole() ?? $chosen;
    }

    /**
     * Pilihan peran yang sah bagi seorang peninjau.
     *
     * @return array<string, string>
     */
    public static function assignableRoleOptions(): array
    {
        return array_reduce(
            self::REVIEWER_ASSIGNABLE_ROLES,
            fn (array $carry, RoleName $role) => $carry + [$role->value => $role->label()],
            [],
        );
    }

    /**
     * Surel yang disamarkan — dipakai daftar admin.
     *
     * Daftar permintaan dapat dibuka setiap Admin Sekolah dan tampil di layar
     * yang sering dilihat bersama-sama. Yang diperlukan untuk mengenali sebuah
     * baris hanya bentuk surelnya; alamat utuhnya ada di halaman rincian, satu
     * klik lebih jauh, tempat keputusan sesungguhnya diambil (butir 536).
     */
    public function maskedEmail(): string
    {
        $email = (string) $this->email;
        $at = mb_strrpos($email, '@');

        if ($at === false || $at === 0) {
            return str_repeat('•', max(mb_strlen($email), 1));
        }

        $local = mb_substr($email, 0, $at);
        $domain = mb_substr($email, $at);

        $visible = mb_substr($local, 0, min(2, mb_strlen($local)));

        return $visible.str_repeat('•', max(mb_strlen($local) - mb_strlen($visible), 1)).$domain;
    }
}
