#!/usr/bin/env bash
#
# Backup berkas unggahan Smart Sukses School.
#
# Pasangan dari ops/backup-database.sh. Basis data yang dipulihkan tanpa
# berkasnya menghasilkan sistem yang tampak utuh tetapi setiap tautan berkasnya
# mati: `payments.proof_path`, `report_cards.pdf_path`, `students.photo_url`,
# dokumen PPDB, dan logo cabang. docs/backup-restore.md menandai bagian ini
# "belum" dan hanya memberi satu baris tar manual — skrip ini menggantikannya
# supaya backup berkas berjalan dari cron yang sama (butir 590).
#
# CON-25 dipakai apa adanya: harian, retensi 30 hari, lokal.
#
# Pemakaian:
#   ops/backup-storage.sh [direktori-tujuan]
#
# Lingkungan (dibaca dari .env aplikasi bila ada):
#   BACKUP_DIR       - tujuan; bawaan storage/app/private/backups
#   BACKUP_KEEP_DAYS - retensi hari; bawaan 30
#
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# .env dibaca baris demi baris, bukan lewat `source`: berkas .env dapat memuat
# nilai berkutip dan karakter yang akan dieksekusi shell bila di-source.
env_value() {
    local key="$1"
    [ -f "${APP_DIR}/.env" ] || return 0
    sed -n "s/^${key}=//p" "${APP_DIR}/.env" | head -n 1 | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"
}

BACKUP_DIR="${1:-${BACKUP_DIR:-$(env_value BACKUP_DIR)}}"
BACKUP_DIR="${BACKUP_DIR:-${APP_DIR}/storage/app/private/backups}"
KEEP_DAYS="${BACKUP_KEEP_DAYS:-$(env_value BACKUP_KEEP_DAYS)}"
KEEP_DAYS="${KEEP_DAYS:-30}"

SOURCE_ROOT="${APP_DIR}/storage/app"

if [ ! -d "${SOURCE_ROOT}" ]; then
    echo "backup-storage: ${SOURCE_ROOT} tidak ada — dihentikan." >&2
    exit 1
fi

mkdir -p "${BACKUP_DIR}"

TARGET="${BACKUP_DIR}/smartsukses-storage-$(date +%Y%m%d-%H%M%S).tar.gz"

# Hanya direktori yang benar-benar ada yang diarsipkan: tar berhenti dengan galat
# bila salah satu argumennya tidak ada, dan pemasangan baru belum tentu sudah
# memiliki keduanya.
SOURCES=()
for dir in public private; do
    [ -d "${SOURCE_ROOT}/${dir}" ] && SOURCES+=("${dir}")
done

if [ "${#SOURCES[@]}" -eq 0 ]; then
    echo "backup-storage: tidak ada storage/app/public maupun storage/app/private — dihentikan." >&2
    exit 1
fi

echo "backup-storage: mengarsipkan ${SOURCES[*]} -> ${TARGET}"

# --exclude untuk direktori backup itu sendiri, dan ini bukan kerapian.
# Tujuan bawaannya berada DI DALAM storage/app/private, sehingga arsip yang tidak
# mengecualikannya akan memuat seluruh dump basis data lama — tumbuh berlipat
# setiap hari sampai disk 40 GB (CON-21) penuh.
#
# Tanpa awalan "./": anggota arsip dinamai relatif terhadap argumen yang
# diberikan (`public`, `private`), sehingga pola "./private/backups" tidak akan
# cocok dengan apa pun dan pengecualiannya diam-diam gagal.
#
# `private/backups` dikecualikan **tanpa syarat**, bukan hanya ketika tujuan
# skrip ini berada di sana: itu juga tujuan bawaan ops/backup-database.sh, jadi
# arsip yang memuatnya akan menggandakan seluruh dump basis data di setiap
# arsip berkas.
EXCLUDES=(--exclude=private/backups)

BACKUP_DIR_ABS="$(cd "${BACKUP_DIR}" && pwd)"
case "${BACKUP_DIR_ABS}" in
    "${SOURCE_ROOT}"/*)
        RELATIVE="${BACKUP_DIR_ABS#"${SOURCE_ROOT}/"}"
        [ "${RELATIVE}" = 'private/backups' ] || EXCLUDES+=("--exclude=${RELATIVE}")
        ;;
esac

tar czf "${TARGET}" \
    -C "${SOURCE_ROOT}" \
    "${EXCLUDES[@]+"${EXCLUDES[@]}"}" \
    "${SOURCES[@]}"

# Verifikasi dibaca ulang, bukan diandaikan. docs/backup-restore.md mencatat
# pemulihan berkas sebagai "belum diuji sama sekali"; arsip yang terpotong
# karena disk penuh hanya terlihat pada saat dibutuhkan bila tidak diperiksa di
# sini.
if ! tar tzf "${TARGET}" >/dev/null 2>&1; then
    echo "backup-storage: arsip GAGAL diverifikasi — dihapus." >&2
    rm -f "${TARGET}"
    exit 1
fi

chmod 600 "${TARGET}" 2>/dev/null || true

echo "backup-storage: selesai ($(du -h "${TARGET}" | cut -f1))"

# Retensi. Pola namanya disebut eksplisit dan pencariannya tidak turun ke
# subdirektori: penghapusan otomatis tidak boleh dapat menyentuh apa pun selain
# berkas yang dibuat skrip ini sendiri — termasuk tidak menyentuh dump basis
# data yang tinggal di direktori yang sama.
if [ "${KEEP_DAYS}" -gt 0 ] 2>/dev/null; then
    DELETED="$(find "${BACKUP_DIR}" -maxdepth 1 -type f \
        -name "smartsukses-storage-*.tar.gz" \
        -mtime "+${KEEP_DAYS}" -print -delete | wc -l | tr -d ' ')"
    echo "backup-storage: retensi ${KEEP_DAYS} hari — ${DELETED} arsip lama dihapus"
fi
