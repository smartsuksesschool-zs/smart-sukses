<?php

namespace App\Services\Finance;

use App\Enums\TransactionType;
use App\Exports\TransactionsExport;
use App\Models\School;
use App\Models\Scopes\SchoolScope;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * API 4.9.2 — GET /finance/export: "Export laporan keuangan ke Excel".
 *
 * Satu sumber untuk kewenangan, penyaringan, dan penamaan berkas buku kas,
 * sehingga endpoint itu nanti (butir 110) tinggal memanggilnya dan tidak dapat
 * menghasilkan laporan yang berbeda dari tombol di panel.
 *
 * Laporan ini selalu **satu cabang**, dan hanya `transactions` — `payments`
 * tidak ikut. Keduanya dua jalur terpisah menurut ERD (butir 75), dan tagihan
 * SPP sudah punya ekspornya sendiri (SPP-05).
 */
class CashLedgerExporter
{
    /**
     * Mengunduh buku kas sebagai .xlsx.
     *
     * @param  array<string, mixed>  $filters
     *
     * @throws AuthorizationException|ValidationException
     */
    public function download(User $actor, array $filters): BinaryFileResponse
    {
        $resolved = $this->resolveFilters($actor, $filters);

        return Excel::download(
            new TransactionsExport($this->query($actor, $filters)),
            $this->fileName($resolved),
        );
    }

    /**
     * Transaksi yang akan diekspor — terotorisasi dan terkunci pada satu cabang.
     *
     * @param  array<string, mixed>  $filters
     *
     * @throws AuthorizationException|ValidationException
     */
    public function query(User $actor, array $filters): Builder
    {
        $resolved = $this->resolveFilters($actor, $filters);

        return Transaction::query()
            // Global scope dilepas dan `school_id` disaring eksplisit: scope
            // bergantung pada sesi dan tidak membatasi apa pun bagi Super
            // Admin, sedangkan laporan ini harus terkunci pada satu cabang.
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $resolved['school_id'])
            // Scope yang sama dengan filter tabel Buku Kas (KAS-01).
            ->betweenDates($resolved['date_from'], $resolved['date_until'])
            ->when(
                $resolved['type'] !== null,
                fn (Builder $query) => $query->where('type', $resolved['type']),
            )
            ->when(
                $resolved['category'] !== null,
                // `category` VARCHAR bebas menurut ERD (butir 77), jadi yang
                // dipakai pencocokan persis — bukan LIKE yang akan membuat
                // "Gaji" ikut menarik "Gaji Honorer" tanpa operator memintanya.
                fn (Builder $query) => $query->where('category', $resolved['category']),
            )
            // Buku kas dibaca berurutan waktu; `id` sebagai pemutus supaya
            // transaksi bertanggal sama selalu berurutan tetap.
            ->orderBy('transaction_date')
            ->orderBy('id');
    }

    /**
     * Kategori yang benar-benar dipakai cabang ini, untuk saran filter.
     *
     * @return array<string, string>
     */
    public function categoryOptions(?int $schoolId): array
    {
        if ($schoolId === null) {
            return [];
        }

        return Transaction::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $schoolId)
            ->select('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category', 'category')
            ->all();
    }

    /**
     * Mengikuti pola penamaan SIS-05 (`siswa_[kode]_[tanggal].xlsx`) dan
     * SPP-05 (`tagihan_[kode]_[periode].xlsx`).
     *
     * Kode cabang di-slug lebih dulu: nama berkas tidak boleh ikut membawa
     * spasi maupun karakter yang tidak sah dari data cabang.
     *
     * @param  array<string, mixed>  $resolved
     */
    public function fileName(array $resolved): string
    {
        $code = School::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->whereKey($resolved['school_id'])
            ->value('code');

        $code = Str::slug((string) $code) ?: 'cabang';

        return 'buku-kas_'.$code.'_'.$resolved['date_from'].'_'.$resolved['date_until'].'.xlsx';
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{school_id: int, date_from: string, date_until: string, type: string|null, category: string|null}
     *
     * @throws AuthorizationException|ValidationException
     */
    public function resolveFilters(User $actor, array $filters): array
    {
        $this->authorize($actor);

        [$from, $until] = $this->resolveRange(
            $filters['date_from'] ?? null,
            $filters['date_until'] ?? null,
        );

        return [
            'school_id' => $this->resolveSchoolId($filters['school_id'] ?? null, $actor),
            'date_from' => $from,
            'date_until' => $until,
            'type' => $this->resolveType($filters['type'] ?? null),
            'category' => $this->resolveCategory($filters['category'] ?? null),
        ];
    }

    /**
     * Tombol di panel dapat disembunyikan, dan penyembunyian bukan proteksi:
     * request Livewire tetap dapat dikirim langsung. Izinnya karena itu
     * diperiksa lagi di sini, pada jalur yang benar-benar menghasilkan berkas.
     *
     * @throws AuthorizationException
     */
    protected function authorize(User $actor): void
    {
        if (Gate::forUser($actor)->denies('export', Transaction::class)) {
            throw new AuthorizationException('Anda tidak berwenang mengekspor laporan keuangan.');
        }
    }

    /**
     * Rentang tanggal wajib, dan wajib masuk akal.
     *
     * API 4.9.2 menyebut `date_from` dan `date_until` sebagai filter
     * `GET /transactions`; keduanya dijadikan **wajib** di sini karena tanpa
     * batas waktu satu klik dapat menarik seluruh riwayat buku kas cabang ke
     * dalam satu berkas tanpa operator menyadarinya (butir 109).
     *
     * @return array{0: string, 1: string}
     *
     * @throws ValidationException
     */
    protected function resolveRange(mixed $from, mixed $until): array
    {
        $from = $this->resolveDate($from, 'date_from');
        $until = $this->resolveDate($until, 'date_until');

        if ($from > $until) {
            throw ValidationException::withMessages([
                'date_until' => 'Tanggal akhir tidak boleh mendahului tanggal mulai.',
            ]);
        }

        return [$from, $until];
    }

    /**
     * @throws ValidationException
     */
    protected function resolveDate(mixed $value, string $key): string
    {
        if (blank($value)) {
            throw ValidationException::withMessages([
                $key => 'Rentang tanggal wajib diisi.',
            ]);
        }

        try {
            return Carbon::parse($value instanceof \DateTimeInterface ? $value : (string) $value)->toDateString();
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                $key => 'Tanggal tidak valid.',
            ]);
        }
    }

    /**
     * Cabang yang diekspor.
     *
     * Nilai dari form hanya dipercaya bila pelakunya Super Admin — merekalah
     * satu-satunya peran yang `school_id`-nya NULL (Arsitektur 3.2.2) dan
     * karena itu wajib memilih cabang. Bagi peran School Level, apa pun yang
     * muncul di payload adalah selundupan dan diabaikan sepenuhnya.
     *
     * @throws ValidationException
     */
    protected function resolveSchoolId(mixed $formValue, User $actor): int
    {
        if (! $actor->isSuperAdmin()) {
            if ($actor->school_id === null) {
                throw ValidationException::withMessages([
                    'school_id' => 'Akun Anda belum terhubung ke cabang mana pun.',
                ]);
            }

            return (int) $actor->school_id;
        }

        if (blank($formValue) || ! is_numeric($formValue)) {
            throw ValidationException::withMessages([
                'school_id' => 'Cabang sekolah wajib dipilih.',
            ]);
        }

        $exists = School::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->whereKey((int) $formValue)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'school_id' => 'Cabang sekolah tidak ditemukan.',
            ]);
        }

        return (int) $formValue;
    }

    /**
     * @throws ValidationException
     */
    protected function resolveType(mixed $type): ?string
    {
        if (blank($type)) {
            return null;
        }

        $resolved = $type instanceof TransactionType
            ? $type
            : TransactionType::tryFrom(is_string($type) ? $type : '');

        if ($resolved === null) {
            throw ValidationException::withMessages([
                'type' => 'Jenis transaksi harus pemasukan atau pengeluaran.',
            ]);
        }

        return $resolved->value;
    }

    protected function resolveCategory(mixed $category): ?string
    {
        $category = is_string($category) ? trim($category) : '';

        return $category === '' ? null : $category;
    }
}
