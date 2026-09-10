<?php

use App\Models\ReportCard;
use App\Services\Finance\PaymentRecorder;
use App\Services\Finance\TransactionRecorder;
use App\Support\PpdbDocument;

/*
|------------------------------------------------------------------------------
| Disk untuk berkas privat yang harus bertahan
|------------------------------------------------------------------------------
|
| Empat kategori berkas di aplikasi ini bersifat privat **dan** harus bertahan:
| PDF rapor, bukti pembayaran, bukti transaksi kas, dan dokumen pendukung PPDB.
| Seluruhnya kini memakai disk `local` (storage/app/private), dan di server
| sungguhan itu tetap jawaban yang benar.
|
| Yang berubah adalah Railway. Berkas sistem sebuah service di sana bersifat
| sementara: setiap redeploy mengembalikannya ke isi image. Lebih tajam lagi,
| web dan worker berjalan sebagai **service terpisah** dengan berkas sistem
| masing-masing — sehingga PDF rapor yang ditulis worker tidak pernah terlihat
| oleh web yang harus menyajikannya. Itu bukan berkas yang hilang nanti,
| melainkan berkas yang tidak pernah ada di tempat yang membutuhkannya.
|
| Karena itu nama disknya dibuat dapat dikonfigurasi, satu per kategori. Yang
| dipilih bukan satu sakelar global: `FILESYSTEM_DISK=s3` akan ikut memindahkan
| berkas sementara impor Excel, dan jalur itu memanggil
| `Storage::disk('local')->path()` — sebuah lintasan berkas sungguhan yang tidak
| dimiliki objek S3. Sakelar global itu akan menukar satu masalah dengan
| masalah lain yang lebih sunyi (butir 585).
|
| Nilainya dibaca dari env **di dalam berkas config ini**, tidak pernah lewat
| `env()` dari kelas aplikasi: `env()` di luar berkas config mengembalikan NULL
| begitu `config:cache` dijalankan — yaitu tepat di staging dan produksi
| (butir 357).
|
| Bawaannya adalah konstanta kelas yang selama ini berlaku, sehingga pemasangan
| tanpa satu pun variabel baru berperilaku persis seperti sebelumnya.
|
*/

return [

    /*
    | PDF rapor. Ditulis worker (GenerateReportCardPdf), dibaca web. Satu-satunya
    | kategori yang benar-benar dibagi dua service.
    */
    'report_card_disk' => env('REPORT_CARD_DISK', ReportCard::PDF_DISK),

    /*
    | Bukti pembayaran dan bukti transaksi kas. Ditulis dan dibaca web, tetapi
    | tetap harus bertahan melewati redeploy — ini dokumen keuangan.
    */
    'payment_proof_disk' => env('PAYMENT_PROOF_DISK', PaymentRecorder::PROOF_DISK),
    'transaction_proof_disk' => env('TRANSACTION_PROOF_DISK', TransactionRecorder::PROOF_DISK),

    /*
    | Dokumen pendukung PPDB: kartu keluarga, akta kelahiran, identitas orang
    | tua. Diunggah pendaftar lewat web, diunduh admin lewat rute berwenang.
    | Persyaratan ketahanannya sama; yang tidak sama hanya kebutuhan berbagi
    | antar service.
    |
    | Disk lama (`public`) sengaja **tidak** dibuat dapat dikonfigurasi. Ia bukan
    | tujuan penyimpanan melainkan sisa sejarah yang sedang dikosongkan
    | `ppdb:privatize-documents`, dan membuatnya dapat diarahkan berarti
    | mengundang berkas baru lahir di sana lagi (M-1).
    */
    'ppdb_document_disk' => env('PPDB_PRIVATE_DISK', PpdbDocument::DISK),

];
