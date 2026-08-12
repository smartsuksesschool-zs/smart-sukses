<?php

namespace App\Models;

use App\Enums\AttitudePredicate;
use App\Enums\ReportCardPdfStatus;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * ERD 2.2 — report_cards. Rapor final per siswa per semester.
 *
 * Keputusan Sprint 4: rapor adalah *hasil kalkulasi* dari bobot yang tersimpan
 * pada `grades.weight` — bukan dari konfigurasi yang berlaku saat rapor dibuka.
 */
class ReportCard extends Model
{
    use BelongsToSchool, HasFactory;

    /**
     * Rapor memuat data pribadi siswa, jadi berkasnya disimpan pada disk privat
     * (`storage/app`) dan hanya dilayani lewat aksi yang sudah melewati policy —
     * bukan pada disk `public` yang dapat diakses siapa pun yang menebak URL.
     */
    public const PDF_DISK = 'local';

    protected $fillable = [
        'school_id',
        'student_id',
        'class_id',
        'academic_year_id',
        'final_scores',
        'attitude_score',
        'attend_present',
        'attend_sick',
        'attend_permission',
        'attend_absent',
        'rank_in_class',
        'homeroom_notes',
        'is_published',
        'published_at',
        'published_by',
        'pdf_path',
        'pdf_status',
        'pdf_generated_at',
    ];

    protected function casts(): array
    {
        return [
            'final_scores' => 'array',
            'attitude_score' => AttitudePredicate::class,
            'pdf_status' => ReportCardPdfStatus::class,
            'pdf_generated_at' => 'datetime',
            'attend_present' => 'integer',
            'attend_sick' => 'integer',
            'attend_permission' => 'integer',
            'attend_absent' => 'integer',
            'rank_in_class' => 'integer',
            'is_published' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('is_published', false);
    }

    /**
     * Berkas PDF hasil antrean siap diunduh.
     *
     * Statusnya saja tidak cukup dijadikan patokan oleh UI: berkas bisa hilang
     * dari disk tanpa sepengetahuan basis data, dan menawarkan tombol unduh
     * yang berujung galat lebih buruk daripada tidak menawarkannya.
     */
    public function hasDownloadablePdf(): bool
    {
        return $this->pdf_status?->isDownloadable() === true
            && filled($this->pdf_path)
            && Storage::disk(self::PDF_DISK)->exists($this->pdf_path);
    }

    /**
     * Lokasi berkas PDF rapor ini — deterministik, sehingga menjalankan ulang
     * job hanya menimpa berkas yang sama alih-alih menumpuk salinan baru.
     */
    public function pdfStoragePath(): string
    {
        return sprintf('rapor/%d/rapor_%d.pdf', $this->school_id, $this->getKey());
    }

    /**
     * Menandai PDF masuk antrean.
     *
     * `pdf_path` dan `pdf_generated_at` sengaja tidak dikosongkan: selama
     * berkas versi sebelumnya masih ada, rapor itu tetap dapat diunduh sambil
     * versi barunya dibuat.
     */
    public function markPdfQueued(): void
    {
        $this->forceFill(['pdf_status' => ReportCardPdfStatus::Queued])->save();
    }

    /**
     * Dipanggil hanya setelah berkasnya benar-benar tersimpan — inilah yang
     * menjaga janji "READY berarti berkasnya ada".
     */
    public function markPdfReady(string $path): void
    {
        $this->forceFill([
            'pdf_path' => $path,
            'pdf_status' => ReportCardPdfStatus::Ready,
            'pdf_generated_at' => now(),
        ])->save();
    }

    /**
     * Kegagalan tidak menyentuh `pdf_path` maupun `pdf_generated_at`: keduanya
     * masih menggambarkan berkas terakhir yang berhasil dibuat. Menghapusnya
     * akan membuang PDF yang sebenarnya masih sah, dan menimpanya dengan nilai
     * kosong justru menyesatkan.
     */
    public function markPdfFailed(): void
    {
        $this->forceFill(['pdf_status' => ReportCardPdfStatus::Failed])->save();
    }

    /**
     * Nilai akhir satu mata pelajaran berdasarkan kode mapel (subjects.code).
     */
    public function scoreFor(string $subjectCode): ?float
    {
        $score = ($this->final_scores ?? [])[$subjectCode] ?? null;

        return $score === null ? null : (float) $score;
    }

    /**
     * Rata-rata seluruh nilai akhir — dipakai pada tampilan & cetak rapor.
     */
    public function averageScore(): ?float
    {
        $scores = array_map('floatval', array_values($this->final_scores ?? []));

        return $scores === [] ? null : round(array_sum($scores) / count($scores), 2);
    }
}
