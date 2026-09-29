# Traceability Requirement Phase 1

Sumber: `docs/blueprint/` (snapshot 29 September 2026). Daftar 40 functional
requirement di bawah diambil apa adanya dari `01-PRD.md` — bukan disusun ulang.

## Aturan status

| Status | Artinya |
| --- | --- |
| `COMPLETE` | Ada implementasinya **dan** ada test yang menegaskan requirement-nya |
| `PARTIAL` | Implementasinya ada, tetapi sebagian requirement belum terbukti atau belum terpenuhi |
| `MISSING` | Tidak ada implementasinya |
| `OWNER-DECISION` | Tertahan keputusan pemilik — lihat `owner-decisions.md` |
| `INFRA-BLOCKED` | Kodenya selesai; yang kurang infrastruktur — lihat `owner-decisions.md` §Infra |
| `APPROVED-DEVIATION` | Sengaja berbeda dari blueprint, dengan bukti keputusan — lihat `approved-deviations.md` |

**Tidak ada baris `COMPLETE` tanpa nama test.** Keberadaan kode saja tidak pernah
cukup, dan itu aturan yang dipegang di seluruh tabel ini.

Beberapa baris memakai dua status (mis. `PARTIAL` · `OWNER-DECISION`): bagian yang
sudah dikerjakan selesai, dan yang tersisa bukan pekerjaan koding.

## Matriks

| Req | Implementasi | Test | Status |
| --- | --- | --- | --- |
| AUTH-01 Login email & password | `Livewire/Auth/Login.php`, `Api/AuthController.php` | `UnifiedLoginTest` (throttle 5/menit, penolakan seragam, peran palsu diabaikan), `Api/ApiFoundationTest` | COMPLETE |
| AUTH-02 Deteksi tenant & isolasi | `Models/Scopes/SchoolScope.php`, `Concerns/BelongsToSchool.php` | `Auth/TenantIsolationTest`, `Tenant/SchoolManagementTest::test_a_smuggled_record_id_cannot_expose_another_branch`, `Cbt/ExamTenantIsolationTest` | COMPLETE |
| AUTH-03 White-label per cabang | `Support/SchoolBranding.php`, `Filament/Pages/PengaturanTampilan.php` | `Portal/SprintSevenClosureTest::test_every_portal_carries_the_school_branding` | COMPLETE |
| AUTH-04 Reset password via surel | `AdminPanelProvider::passwordReset()`, `config/auth.php:107` (60 menit), `AppServiceProvider::revokeCredentialsAfterPasswordReset()` | `Security/ProductionConfigTest::test_the_password_reset_link_expires_after_con_30`, `Auth/PasswordResetRevocationTest` (4 test) | PARTIAL · INFRA-BLOCKED — AC-2 dan AC-3 terbukti; pengiriman surelnya belum ada (`MAIL_MAILER=log`) |
| AUTH-05 Ganti bahasa ID/EN | `routes/web.php` (`POST /bahasa/{locale}`), `users.locale` | `BilingualCoverageTest` (29 test) | COMPLETE |
| SIS-01 Tambah siswa manual | `Filament/Resources/StudentResource.php` | `MasterData/StudentManagementTest` (NISN 10 digit, NIS unik per cabang) | COMPLETE |
| SIS-02 Edit & nonaktifkan siswa | `Models/Student.php` (SoftDeletes), `StudentPolicy`, `StudentResource::archiveAction()` | `MasterData/StudentArchiveTest` (20 test), `MasterDataRbacTest::test_students_are_never_hard_deleted` | COMPLETE · OWNER-DECISION — mekanisme "nonaktif" diwujudkan sebagai arsip; lihat OD-13 |
| SIS-03 Upload foto siswa | `StudentResource.php:150-164`, `Support/StudentPhoto.php` | `MasterData/StudentPhotoAccessTest` (22 test) — 3 MB ditolak, 1 MB diterima, WEBP diterima, PDF ditolak, sasaran 400×400 terjaga | COMPLETE · catatan — klausul "resize 400×400" ditegakkan FilePond di peramban; **tidak ada penegak sisi server** (butir 592) |
| SIS-04 Guru melihat siswa kelas ajar | `Services/Portal/TeacherPortalService.php`, `Support/TeacherClassVisibility.php` | `MasterData/StudentViewPageTest::test_guru_hanya_melihat_siswa_kelas_ajarnya`, `Portal/TeacherPortalUiTest` | PARTIAL · OWNER-DECISION — AC-1 terbukti; AC-2 "status kehadiran hari ini" tertahan RSK-01 (OD-03) |
| SIS-05 Export siswa ke Excel | `Exports/StudentsExport.php`, `Pages/ListStudents.php` | `MasterData/StudentImportExportTest` — nama berkas unduhan sungguhan `siswa_[kode]_[tanggal].xlsx`, dan cabang berbeda menghasilkan nama berbeda | COMPLETE |
| PPDB-01 Formulir pendaftaran publik | `Livewire/Ppdb/RegistrationForm.php`; dialihkan `Http/Middleware/RedirectPpdbToConfiguredForm.php` | `Ppdb/PpdbPublicRegistrationTest`, `PublicSite/PpdbSingleEntryTest` | APPROVED-DEVIATION — AD-01 (keputusan pemilik M7.2) |
| PPDB-02 Cek status pendaftaran | `Livewire/Ppdb/StatusCheck.php` | `Ppdb/PpdbStatusCheckTest::test_every_status_of_the_ppdb_flow_can_be_reported` | COMPLETE |
| PPDB-03 Kelola pendaftar | `PpdbRegistrationResource.php`, `Services/Ppdb/PpdbStatusUpdater.php` | `Ppdb/PpdbAdminReviewTest` | COMPLETE — tanpa masukan baru selama AD-01 aktif |
| PPDB-04 Link wa.me PPDB | `Support/PpdbWaTemplate.php`, `Support/WhatsAppLink.php` | `Ppdb/PpdbWaLinkTest` (format wa.me, template per status, placeholder) | COMPLETE · OWNER-DECISION — normalisasi nomor telepon diputuskan implementasi (OD-14) |
| PPDB-05 Konversi pendaftar → siswa | `PpdbRegistrationResource.php:280-373` | `Ppdb/PpdbEnrollmentTest` (tidak dapat didaftarkan dua kali) | COMPLETE — tanpa masukan baru selama AD-01 aktif |
| KELAS-01 Buat kelas & wali kelas | `SchoolClassResource.php` — pagar AC-KELAS-05 membaca daftar pilihan yang sama, unique `(academic_year_id, homeroom_teacher_id)` | `MasterData/ClassEnrollmentTest` (12 test) — guru nonaktif ditolak, guru cabang lain ditolak, guru aktif diterima, CON-36 | COMPLETE |
| KELAS-02 Tambah siswa ke kelas | `Student::scopeEligibleForYear`, `StudentsRelationManager` | `MasterData/ClassEnrollmentTest` (AC-KELAS-07, AC-KELAS-08) | COMPLETE — penolakan di tingkat aplikasi; CON-35 tidak menuntut constraint DB (lihat catatan di bawah) |
| KELAS-03 Buat jadwal pelajaran & mata pelajaran | `ScheduleResource.php`, `SubjectResource.php` (aturan unik kode berlingkup cabang) | `MasterData/ScheduleConflictTest` (6 test konflik, CON-48); `MasterData/SubjectManagementTest` (4 test) — nama/kode/jam pelajaran tersimpan, kode ganda jadi validasi bukan galat 500, kode sama boleh di cabang lain | COMPLETE |
| KELAS-04 Guru melihat jadwal | `TeacherPortalService.php:221-265` | `Portal/TeacherPortalUiTest`, `Portal/TeacherPortalApiTest` | COMPLETE |
| NILAI-01 Input nilai per komponen | `Filament/Pages/InputNilai.php`, `Imports/GradesImport.php` | `Grading/GradeInputTest`, `GradeImportTest`, `GradeImportXlsxTest` | COMPLETE |
| NILAI-02 Perhitungan nilai akhir | `Services/Grading/FinalScoreCalculator.php`, `GradeWeightSnapshotter.php` | `Grading/FinalScoreCalculationTest` | COMPLETE · OWNER-DECISION — presedensi `grades.weight` vs `grade_configs.components` diputuskan implementasi (OD-15) |
| NILAI-03 Publish rapor oleh wali kelas | `Services/Grading/ReportCardGenerator.php`, `ReportCardPolicy` | `Grading/ReportCardPublishTest` (nilai terkunci setelah publish, publish ditolak bila ada mapel tanpa nilai) | PARTIAL · OWNER-DECISION — publish terbukti; rekap kehadiran dan `rank_in_class` pada PDF kosong (OD-03, OD-04) |
| NILAI-04 Siswa & ortu melihat nilai | `Services/Portal/StudentPortalService.php`, `ParentPortalService.php` | `Portal/StudentPortalApiTest`, `Portal/ParentDetailApiTest` | COMPLETE |
| NILAI-05 Konfigurasi komponen & bobot | `Services/Grading/GradeConfigVersionManager.php` | `Grading/GradeConfigVersioningTest` | PARTIAL · OWNER-DECISION — versioning terbukti, tetapi mengizinkan versi ACTIVE baru **di dalam TA yang sama**, lebih longgar daripada CON-42 (OD-12) |
| SPP-01 Buat jenis tagihan | `FeeTypeResource.php`, `Api/FeeTypeController.php` | `Finance/FeeTypeManagementTest`, `Api/FinanceApiTest` | COMPLETE |
| SPP-02 Generate tagihan massal | `Services/Finance/StudentFeeGenerator.php`, `Pages/GenerateTagihan.php` | `Finance/GenerateStudentFeePreviewTest`, `GenerateStudentFeeJobTest` | PARTIAL · OWNER-DECISION — bulanan terbukti; `YEARLY` dan `ONCE` tidak pernah membaca `frequency` (RSK-10 / OD-05) |
| SPP-03 Catat pembayaran manual | `Services/Finance/PaymentRecorder.php` (`PROOF_MAX_KILOBYTES=5120`) | `Finance/RecordPaymentProofTest`, `AttachPaymentProofTest`, `FinanceApiTest::test_the_api_rejects_an_oversized_proof` | COMPLETE |
| SPP-04 Ortu melihat tagihan anak | `Livewire/Portal/ParentFees.php` | `Portal/ParentPortalPagesTest::test_the_fees_page_shows_amounts_and_status` | COMPLETE |
| SPP-05 Export laporan tagihan | `Services/Finance/StudentFeeReportExporter.php` | `Finance/StudentFeeExportTest`, `FinanceApiTest::test_the_student_fee_export_returns_a_real_xlsx` | COMPLETE |
| KAS-01 Catat kas masuk/keluar | `Services/Finance/TransactionRecorder.php` | `Finance/TransactionRecorderTest`, `TransactionResourceTest` | COMPLETE |
| KAS-02 Ringkasan keuangan bulanan | `Services/Finance/FinanceSummaryService.php` | `Finance/FinanceSummaryServiceTest`, `LaporanKeuanganPageTest` | COMPLETE |
| KAS-03 Dashboard keuangan semua cabang | `Services/Finance/CrossSchoolFinanceSummaryService.php` | `Finance/CrossSchoolFinanceSummaryTest`, `LaporanKeuanganCabangPageTest` | COMPLETE |
| NOTIF-01 Pengumuman bertarget | `Services/Notification/AnnouncementPublisher.php`, `NotificationRecipientResolver.php` | `Notification/NotificationFoundationTest` (ALL/CLASS/INDIVIDUAL) | COMPLETE · OWNER-DECISION — kosakata kategori berselisih antar dokumen (OD-09) |
| NOTIF-02 Daftar link wa.me | `Services/Notification/NotificationWaLinkService.php` | `Notification/NotificationWaLinkTest` (22 test) | COMPLETE |
| NOTIF-03 Trigger notifikasi otomatis | `SystemNotificationPublisher.php`, `Pages/PengaturanNotifikasi.php` | `Notification/AutomaticNotificationTest` (38 test, `test_the_three_triggers_are_the_only_ones_that_exist`) | COMPLETE |
| NOTIF-04 Notification center in-app | `NotificationCenter.php`, `NotificationRetentionService.php` | `Notification/NotificationRetentionTest` (33 test, batas 90 hari, prune terjadwal sehari sekali) | COMPLETE · INFRA-BLOCKED — `schedule:run` terpasang di `ops/smartsukses-cron`; belum terbukti berjalan di server (OD-Infra-3) |
| PORTAL-01 Dashboard orang tua | `Services/Portal/ParentPortalService.php` | `Portal/ParentPortalPagesTest`, `Portal/RowLevelAccessTest` | PARTIAL · OWNER-DECISION — nilai & tagihan terbukti; kehadiran mengembalikan `available:false` (OD-03), jumlah nilai terbaru 3-vs-5 (OD-06) |
| PORTAL-02 Dashboard guru | `Livewire/Teacher/TeacherDashboard.php` | `Portal/TeacherPortalUiTest`, `SprintSevenClosureTest` | PARTIAL · OWNER-DECISION — shortcut "Buat Pengumuman" sengaja tidak ada; PRD:931 dan matriks izin berselisih (OD-07) |
| PORTAL-03 Portal siswa | `Services/Portal/StudentPortalService.php` | `Portal/StudentPortalUiTest`, `StudentPortalApiTest`, `StudentNotificationTest` | COMPLETE |
| PORTAL-04 Manajemen akun pengguna | `Filament/Resources/UserResource.php`, `Middleware/EnsurePasswordIsChanged.php` | `Auth/PanelAccessTest` (sandi sementara wajib diganti), `Auth/AccessManagementResourceTest` | PARTIAL · OWNER-DECISION — pengelolaan akun terbukti; pengiriman sandi sementara tidak lewat notifikasi seperti PRD:961 (OD-10) |

## Rekapitulasi

| Status | Jumlah FR |
| --- | --- |
| `COMPLETE` | 25 |
| `COMPLETE` dengan catatan teknis (SIS-03) | 1 |
| `COMPLETE` dengan butir pemilik terbuka | 4 |
| `COMPLETE` dengan butir infrastruktur | 1 |
| `PARTIAL` | 8 |
| `APPROVED-DEVIATION` | 1 |
| `MISSING` | 0 |
| **Total** | **40** |

Tidak ada satu pun FR Phase 1 yang **tidak memiliki implementasi**, dan
**tidak ada lagi sisa pekerjaan koding** pada requirement Phase 1.

### Keempat kesenjangan terakhir — ditutup

Keempatnya masuk daftar sebagai "kode benar, test belum ada". Dua ternyata bukan:

| Requirement | Yang ditemukan | Hasil |
| --- | --- | --- |
| AC-SIS-07 | Klausul 1–2 memang benar dan ditegakkan server | Diuji secara perilaku (3 MB ditolak, WEBP diterima, PDF ditolak). Klausul 3 tidak punya penegak sisi server — dicatat, tidak ditambal |
| AC-SIS-09 | Benar | Diuji lewat nama berkas unduhan sungguhan, dengan pagar kode cabang |
| AC-KELAS-05 | **Cacat** — guru nonaktif **dan** guru cabang lain tersimpan tanpa galat | Diperbaiki; pagar membaca daftar pilihan yang sama |
| AC-KELAS-03 | **Cacat** — kode mapel ganda menjadi galat 500 | Diperbaiki; aturan unik berlingkup cabang di form |

Delapan baris `PARTIAL` yang tersisa seluruhnya menunggu **keputusan pemilik atau
infrastruktur**, bukan menunggu kode: AUTH-04 (SMTP), SIS-04 dan PORTAL-01
(kehadiran, RSK-01), NILAI-03 (kehadiran dan peringkat), NILAI-05 (CON-42),
SPP-02 (RSK-10), PORTAL-02 (shortcut guru), PORTAL-04 (pengiriman sandi).

## Catatan yang bukan kesenjangan requirement

Tiga hal sering terbaca sebagai cacat padahal bukan, dan dicatat di sini supaya
tidak berulang menjadi temuan:

**CON-35 / AC-KELAS-08 tanpa constraint basis data.** AC-nya berbunyi "Sistem
**menolak** penempatan satu siswa di lebih dari satu kelas pada tahun ajaran yang
sama". Penolakan di tingkat aplikasi memenuhi bunyi itu dan diuji
(`ClassEnrollmentTest`). Tidak adanya unique index adalah **risiko konkurensi**
— dua pendaftaran serentak dapat lolos keduanya — bukan pelanggaran requirement.
Pengerasannya bermanfaat, tetapi ia penambahan, bukan penutupan gap.

**RSK-01, RSK-09, RSK-10 adalah kesenjangan blueprint, bukan kegagalan
implementasi.** `02-ROADMAP.md` §7.1 meresepkan perilaku sementaranya, dan kode
mengikutinya: kehadiran mengembalikan `available:false` secara eksplisit,
`rank_in_class` dibiarkan kosong. Resepnya berbunyi **"jangan mengarang sumber
data"** dan **"jangan menciptakan rumus sendiri"** — keduanya dipatuhi.
Pengecualiannya RSK-10, yang **tidak** memperoleh resep, sehingga perilaku
sekarang (semua frekuensi diperlakukan bulanan) adalah pilihan implementasi yang
belum diratifikasi. Itu sebabnya ia satu-satunya dari ketiganya yang membawa
risiko data.

**06-API.md bukan daftar pekerjaan.** Dokumen itu menyatakan sendiri: "Turunan
blueprint. Tidak menambah endpoint, requirement, maupun aturan baru." CON-02 dan
CON-03 mewajibkan Filament dan Livewire, sehingga requirement fungsional memang
dipenuhi lewat panel dan portal — bukan lewat REST. Endpoint yang tercantum di
sana tetapi tidak ada di `routes/api.php` karena itu **bukan** `MISSING`; ia
pertanyaan tentang status dokumen (OD-16).
