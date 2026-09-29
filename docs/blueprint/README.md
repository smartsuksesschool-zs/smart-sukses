# Snapshot Source of Truth

Direktori ini adalah **salinan verbatim** dokumen requirement yang sebelumnya
hidup **di luar** version control. Sebelum snapshot ini, sumber kebenaran
requirement berada di `C:\magang\suksessmart\docs\` — sebuah direktori yang
bahkan bukan git repo, sehingga satu kecelakaan berkas berarti kehilangan tanpa
jalan pulang.

Tanggal snapshot: **29 September 2026.**

## Hierarki sumber kebenaran

```
SmartSukses_FullBlueprint_v1.0.0.docx      <- sumber akar, satu-satunya
        |
        +-- 01-Analisis-Blueprint.md       <- analisis blueprint
        +-- 01-PRD.md                      <- requirement fungsional (PRD)
                |
                +-- 02-ROADMAP.md
                +-- 03-USER_FLOW.md
                +-- 04-ERD.md
                +-- 05-DATABASE.md
                +-- 06-API.md
```

**Blueprint DOCX adalah sumber kebenaran utama.** Keenam berkas Markdown adalah
turunan; masing-masing menyatakan sendiri di kepalanya bahwa ia "tidak menambah,
mengurangi, atau mengubah requirement". Ketika turunan dan blueprint berselisih,
blueprint yang menang — dan selisihnya dicatat, bukan didiamkan.

## Isi snapshot

Versi diambil dari pernyataan dokumen itu sendiri, bukan dari nama berkasnya.

| Berkas | Versi menurut dokumennya | Ukuran | mtime sumber |
| --- | --- | --- | --- |
| `SmartSukses_FullBlueprint_v1.0.0.docx` | v1.0.0 · Agustus 2025 · **DRAFT** (Untuk Review & Development) | 67.471 | 2026-08-07 |
| `01-PRD.md` | v1.1 | 104.210 | 2026-08-07 |
| `02-ROADMAP.md` | v1.1 | 128.777 | 2026-08-07 |
| `03-USER_FLOW.md` | v1.0 | 129.214 | 2026-08-07 |
| `04-ERD.md` | v1.0 | 86.609 | 2026-08-07 |
| `05-DATABASE.md` | v1.0 | 118.708 | 2026-08-07 |
| `06-API.md` | v1.0 — 101 endpoint dalam 11 kelompok | 121.815 | 2026-08-07 |
| `01-Analisis-Blueprint.md` | analisis/derivatif atas blueprint v1.0.0 | 48.651 | 2026-08-07 |

Blueprint berstatus **DRAFT**, dan itu bukan detail administratif: sebagian
requirement memang belum diputuskan di dalamnya. `01-Analisis-Blueprint.md`
menandai setiap hal semacam itu dengan kalimat **"Belum dijelaskan dalam
blueprint."** Setiap tempat yang ditandai demikian dan sudah dikoding berarti
implementasinya memutuskan sendiri — dan keputusan itu harus muncul di
`docs/requirements/owner-decisions.md`, bukan hanya di dalam kode.

## Integritas

SHA256 di bawah adalah checksum berkas **sumber** pada saat snapshot diambil.
Berkas di direktori ini byte-for-byte sama dengan sumbernya: tidak ada
normalisasi newline (berkas Markdown-nya ber-CRLF dan tetap ber-CRLF), tidak ada
pemformatan ulang, dan DOCX tidak dikonversi.

```
5d9995fd948b1dc5225c6c840628668c0811c139fae96bf8e3f1ab3676775634  01-PRD.md
a12d0803814bc17a26b75fd72b28842597be5f8ac4002af087095752b4c66fb5  02-ROADMAP.md
bc067ebe8ac57f283cfd9f36de43bae07793bea2f0559b083f95c6c1957adac2  03-USER_FLOW.md
b19ffa4aaf73ede235cd7b47cebe3b4a7f8d08a8e73ab8dfa03e7ff28067c3c8  04-ERD.md
6f91b42617857d8a65a077aa4373fab6c0a141afe1d8e83102e502fec8f1414b  05-DATABASE.md
f67f66abafefbc567072ef7c487c50340c72dace51ba16fe5ed070b95f95772d  06-API.md
d46199d89eea940fba06954d0af22671904612b5dbc0277a2f00908ad200ddba  01-Analisis-Blueprint.md
c74981bb9a0a914081db8a4aee9708d8d3521383cf7495ef5df46068cc9196cc  SmartSukses_FullBlueprint_v1.0.0.docx
```

Memeriksa ulang:

```sh
cd docs/blueprint && sha256sum -c <<'EOF'
5d9995fd948b1dc5225c6c840628668c0811c139fae96bf8e3f1ab3676775634  01-PRD.md
a12d0803814bc17a26b75fd72b28842597be5f8ac4002af087095752b4c66fb5  02-ROADMAP.md
bc067ebe8ac57f283cfd9f36de43bae07793bea2f0559b083f95c6c1957adac2  03-USER_FLOW.md
b19ffa4aaf73ede235cd7b47cebe3b4a7f8d08a8e73ab8dfa03e7ff28067c3c8  04-ERD.md
6f91b42617857d8a65a077aa4373fab6c0a141afe1d8e83102e502fec8f1414b  05-DATABASE.md
f67f66abafefbc567072ef7c487c50340c72dace51ba16fe5ed070b95f95772d  06-API.md
d46199d89eea940fba06954d0af22671904612b5dbc0277a2f00908ad200ddba  01-Analisis-Blueprint.md
c74981bb9a0a914081db8a4aee9708d8d3521383cf7495ef5df46068cc9196cc  SmartSukses_FullBlueprint_v1.0.0.docx
EOF
```

`.gitattributes` memuat `docs/blueprint/** -text` supaya pelestarian byte itu
**dijamin** dan bukan kebetulan: tanpa aturan tersebut, satu
`git add --renormalize` di kemudian hari akan mengubah newline dan membuat
seluruh checksum di atas tidak lagi cocok.

## Hubungan dengan `smartsukses-docs/`

Repositori ini sudah memiliki `smartsukses-docs/` — **30 berkas**, dekomposisi
v1.0.0 per topik, dan dokumen itulah yang dirujuk oleh kode serta runbook yang
sudah ada. Direktori ini **tidak menggantikannya** dan tidak satu berkas pun di
sana disentuh.

Keduanya berbeda generasi, dan perbedaannya perlu diketahui sebelum salah satu
dikutip sebagai dasar:

| | `smartsukses-docs/` | `docs/blueprint/` |
| --- | --- | --- |
| Bentuk | 30 berkas, terdekomposisi | 7 berkas monolitik + DOCX |
| Generasi | turunan v1.0.0 | PRD/Roadmap v1.1, sisanya v1.0 |
| Memuat `AC-*` dan `CON-*` | tidak | ya |
| Peran | dokumen kerja yang dirujuk kode | **snapshot sumber kebenaran** |

Ketika keduanya berselisih, `docs/blueprint/` yang lebih baru — tetapi selisih
semacam itu **tidak diselesaikan diam-diam**: ia dicatat sebagai baris di
`docs/requirements/owner-decisions.md`.

## Aturan perubahan requirement

Direktori ini **tidak boleh disunting untuk mengubah requirement.** Snapshot yang
disunting berhenti menjadi snapshot, dan checksum di atas adalah pagarnya.

Requirement berubah hanya melalui salah satu dari dua jalan:

1. **Revisi blueprint.** Blueprint baru dari pemilik masuk sebagai snapshot baru
   — berkas baru dengan nomor versinya sendiri, checksum baru dicatat di sini,
   dan snapshot lama **tetap tinggal** supaya riwayat keputusan tidak terhapus.
2. **Decision record.** Keputusan pemilik yang menyimpang dari blueprint dicatat
   di `docs/requirements/approved-deviations.md` beserta buktinya. Kode boleh
   berbeda dari blueprint **hanya** bila ada barisnya di sana.

Yang belum diputuskan pemilik tinggal di
`docs/requirements/owner-decisions.md` sebagai pertanyaan — bukan sebagai
keputusan yang sudah diambil, dan bukan sebagai requirement yang dihapus.
