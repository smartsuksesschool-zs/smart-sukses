> Sumber: SmartSukses_FullBlueprint_v1_0_0.docx (v1.0.0, Agustus 2025) — KONFIDENSIAL, internal only.

# 4.10 Notifikasi

| **Method** | **Endpoint** | **Auth Level** | **Deskripsi** |
| --- | --- | --- | --- |
| **GET** | /notifications | Auth | Daftar notifikasi untuk user yang login. Filter: type, is_read. Limit: 50 terbaru |
| **GET** | /notifications/unread-count | Auth | Jumlah notifikasi belum dibaca (untuk badge) |
| **POST** | /notifications | Admin | Buat dan kirim notifikasi baru (target: ALL/CLASS/INDIVIDUAL) |
| **GET** | /notifications/{id} | Auth | Detail notifikasi |
| **PATCH** | /notifications/{id}/read | Auth | Tandai notifikasi sebagai dibaca |
| **POST** | /notifications/mark-all-read | Auth | Tandai semua notifikasi sebagai dibaca |
| **GET** | /notifications/{id}/wa-links | Admin | Generate daftar wa.me link untuk semua penerima notifikasi ini |
| **GET** | /admin/notifications | Admin | Semua notifikasi yang pernah dibuat di cabang ini (termasuk draft) |
