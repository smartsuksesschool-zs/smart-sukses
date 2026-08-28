#!/usr/bin/env bash
#
# Backup basis data Smart Sukses School.
#
# Arsitektur 3.4: "Backup harian pukul 02:00 WIB; retensi 30 hari; Phase 1:
# lokal." Skrip ini mengerjakan bagian basis datanya saja — berkas unggahan
# dicadangkan terpisah, lihat docs/backup-restore.md.
#
# Dijalankan cron di server produksi. Lihat ops/smartsukses-cron untuk contoh
# entri cron-nya.
#
# Pemakaian:
#   ops/backup-database.sh [direktori-tujuan]
#
# Lingkungan (dibaca dari .env aplikasi bila ada):
#   DB_DATABASE, DB_USERNAME, DB_PASSWORD, DB_HOST, DB_PORT
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

DB_DATABASE="${DB_DATABASE:-$(env_value DB_DATABASE)}"
DB_USERNAME="${DB_USERNAME:-$(env_value DB_USERNAME)}"
DB_PASSWORD="${DB_PASSWORD:-$(env_value DB_PASSWORD)}"
DB_HOST="${DB_HOST:-$(env_value DB_HOST)}"
DB_PORT="${DB_PORT:-$(env_value DB_PORT)}"

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"

BACKUP_DIR="${1:-${BACKUP_DIR:-${APP_DIR}/storage/app/private/backups}}"
KEEP_DAYS="${BACKUP_KEEP_DAYS:-30}"

if [ -z "${DB_DATABASE}" ]; then
    echo "backup: DB_DATABASE tidak diketahui. Setel di .env atau lewat lingkungan." >&2
    exit 1
fi

MYSQLDUMP="${MYSQLDUMP_BIN:-mysqldump}"
command -v "${MYSQLDUMP}" >/dev/null 2>&1 || {
    echo "backup: ${MYSQLDUMP} tidak ditemukan. Setel MYSQLDUMP_BIN." >&2
    exit 1
}

mkdir -p "${BACKUP_DIR}"
chmod 700 "${BACKUP_DIR}" 2>/dev/null || true

STAMP="$(date +%Y%m%d-%H%M%S)"
TARGET="${BACKUP_DIR}/smartsukses-${DB_DATABASE}-${STAMP}.sql"

# Kata sandi tidak pernah menjadi argumen baris perintah: argumen terlihat
# seluruh pengguna server lewat `ps`. Yang dipakai berkas opsi sementara
# ber-mode 600 yang dihapus saat skrip berakhir, termasuk saat gagal.
OPTS_FILE="$(mktemp)"
chmod 600 "${OPTS_FILE}"
cleanup() { rm -f "${OPTS_FILE}"; }
trap cleanup EXIT

{
    printf '[client]\n'
    printf 'user=%s\n' "${DB_USERNAME}"
    printf 'password=%s\n' "${DB_PASSWORD}"
    printf 'host=%s\n' "${DB_HOST}"
    printf 'port=%s\n' "${DB_PORT}"
} > "${OPTS_FILE}"

echo "backup: membuat dump ${DB_DATABASE} -> ${TARGET}.gz"

# --single-transaction: konsisten tanpa mengunci tabel InnoDB, sehingga situs
# tetap melayani selama backup berjalan.
"${MYSQLDUMP}" \
    --defaults-extra-file="${OPTS_FILE}" \
    --single-transaction \
    --quick \
    --routines \
    --triggers \
    --events \
    --default-character-set=utf8mb4 \
    "${DB_DATABASE}" > "${TARGET}"

if command -v gzip >/dev/null 2>&1; then
    gzip -f "${TARGET}"
    TARGET="${TARGET}.gz"
fi

chmod 600 "${TARGET}" 2>/dev/null || true

echo "backup: selesai ($(du -h "${TARGET}" | cut -f1))"

# Retensi. Pola namanya disebut eksplisit dan pencariannya tidak turun ke
# subdirektori: penghapusan otomatis tidak boleh dapat menyentuh apa pun selain
# berkas yang dibuat skrip ini sendiri.
if [ "${KEEP_DAYS}" -gt 0 ] 2>/dev/null; then
    DELETED="$(find "${BACKUP_DIR}" -maxdepth 1 -type f \
        -name "smartsukses-*.sql*" \
        -mtime "+${KEEP_DAYS}" -print -delete | wc -l | tr -d ' ')"
    echo "backup: retensi ${KEEP_DAYS} hari — ${DELETED} berkas lama dihapus"
fi
