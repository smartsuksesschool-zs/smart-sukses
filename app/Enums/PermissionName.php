<?php

namespace App\Enums;

/**
 * PRD 1.1.2 — Matriks Izin per Modul (Phase 1).
 *
 * Setiap modul memiliki dua izin yang memetakan matriks secara langsung:
 *  - `*.view`   → ⭕ akses baca/lihat saja
 *  - `*.manage` → ✅ akses penuh (create, update, delete)
 *
 * Peran dengan akses penuh selalu diberi kedua izin sehingga pengecekan
 * `can('student.view')` cukup untuk gate baca di seluruh aplikasi.
 */
enum PermissionName: string
{
    // Manajemen Tenant/Cabang
    case TenantView = 'tenant.view';
    case TenantManage = 'tenant.manage';

    // Data Siswa (SIS)
    case StudentView = 'student.view';
    case StudentManage = 'student.manage';

    // PPDB Online
    case PpdbView = 'ppdb.view';
    case PpdbManage = 'ppdb.manage';

    // Kelas & Jadwal
    case ClassScheduleView = 'class_schedule.view';
    case ClassScheduleManage = 'class_schedule.manage';

    // Input Nilai
    case GradeView = 'grade.view';
    case GradeManage = 'grade.manage';

    // Generate Rapor
    case ReportCardView = 'report_card.view';
    case ReportCardManage = 'report_card.manage';

    // Tagihan SPP
    case FeeView = 'fee.view';
    case FeeManage = 'fee.manage';

    // Catat Pembayaran
    case PaymentView = 'payment.view';
    case PaymentManage = 'payment.manage';

    // Akuntansi & Kas
    case AccountingView = 'accounting.view';
    case AccountingManage = 'accounting.manage';

    // Laporan Keuangan
    case FinancialReportView = 'financial_report.view';
    case FinancialReportManage = 'financial_report.manage';

    // Notifikasi
    case NotificationView = 'notification.view';
    case NotificationManage = 'notification.manage';

    // Portal Siswa
    case StudentPortalView = 'student_portal.view';
    case StudentPortalManage = 'student_portal.manage';

    // Parent Portal
    case ParentPortalView = 'parent_portal.view';
    case ParentPortalManage = 'parent_portal.manage';

    // White-label Settings
    case WhiteLabelView = 'white_label.view';
    case WhiteLabelManage = 'white_label.manage';

    // User Management
    case UserView = 'user.view';
    case UserManage = 'user.manage';

    /*
     * Isi Situs Publik — halaman muka `/`.
     *
     * Modul tersendiri, bukan menumpang `white_label`. Keduanya sama-sama soal
     * tampilan, tetapi cakupannya berbeda secara mendasar: white-label adalah
     * tampilan **satu cabang** setelah login, sedangkan ini adalah situs payung
     * Smart Sukses School yang dilihat seluruh publik. Menumpangkannya berarti
     * setiap Admin Sekolah yang berhak mengganti logo cabangnya sendiri
     * seketika juga berhak mengubah halaman muka sekolah (butir 469).
     */
    case PublicContentView = 'public_content.view';
    case PublicContentManage = 'public_content.manage';

    /**
     * Nama modul (segmen pertama) — dipakai untuk mengelompokkan izin di UI.
     */
    public function module(): string
    {
        return str($this->value)->before('.')->toString();
    }

    public function moduleLabel(): string
    {
        return match ($this->module()) {
            'tenant' => 'Manajemen Tenant/Cabang',
            'student' => 'Data Siswa (SIS)',
            'ppdb' => 'PPDB Online',
            'class_schedule' => 'Kelas & Jadwal',
            'grade' => 'Input Nilai',
            'report_card' => 'Generate Rapor',
            'fee' => 'Tagihan SPP',
            'payment' => 'Catat Pembayaran',
            'accounting' => 'Akuntansi & Kas',
            'financial_report' => 'Laporan Keuangan',
            'notification' => 'Notifikasi',
            'student_portal' => 'Portal Siswa',
            'parent_portal' => 'Parent Portal',
            'white_label' => 'White-label Settings',
            'user' => 'User Management',
            'public_content' => 'Isi Situs Publik',
            default => str($this->module())->headline()->toString(),
        };
    }

    public function label(): string
    {
        $action = str($this->value)->after('.')->toString();

        return $this->moduleLabel().' — '.($action === 'manage' ? 'Kelola' : 'Lihat');
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
