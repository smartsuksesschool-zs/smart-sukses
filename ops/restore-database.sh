#!/usr/bin/env bash
#
# Restore basis data Smart Sukses School dari dump.
#
# Skrip ini menimpa isi basis data tujuan. Karena itu ia menolak nama basis data
# yang tampak produksi kecuali diminta secara sangat eksplisit — satu salah
# ketik tidak boleh dapat menghapus data sungguhan.
#
# Pemakaian:
#   ops/restore-database.sh <berkas-dump> <database-tujuan>
#
# Contoh (uji pemulihan):
#   ops/restore-database.sh storage/app/private/backups/smartsukses-...sql.gz smartsukses_test
#
# Untuk memulihkan basis data produksi, jalankan dengan:
#   ALLOW_PRODUCTION_RESTORE=yes ops/restore-database.sh <dump> smartsukses
#
# Lakukan itu hanya dengan sadar, sesudah membaca docs/backup-restore.md.
#
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

env_value() {
    local key="$1"
    [ -f "${APP_DIR}/.env" ] || return 0
    sed -n "s/^${key}=//p" "${APP_DIR}/.env" | head -n 1 | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"
}

DUMP_FILE="${1:-}"
TARGET_DB="${2:-}"

if [ -z "${DUMP_FILE}" ] || [ -z "${TARGET_DB}" ]; then
    echo "pemakaian: $(basename "$0") <berkas-dump> <database-tujuan>" >&2
    exit 64
fi

if [ ! -f "${DUMP_FILE}" ]; then
    echo "restore: berkas dump tidak ditemukan: ${DUMP_FILE}" >&2
    exit 66
fi

# Pagar. Basis data pengembangan dan produksi memakai nama yang sama
# (`smartsukses`), dan keduanya bukan sasaran uji pemulihan.
case "${TARGET_DB}" in
    ''|*' '*)
        echo "restore: nama database tidak sah." >&2
        exit 64
        ;;
    *_test|*_testing|*_restore|*_drill)
        ;;
    *)
        if [ "${ALLOW_PRODUCTION_RESTORE:-}" != "yes" ]; then
            echo "restore: menolak menimpa '${TARGET_DB}'." >&2
            echo "         Sasaran uji pemulihan harus berakhiran _test/_testing/_restore/_drill." >&2
            echo "         Untuk memulihkan basis data sungguhan, jalankan ulang dengan" >&2
            echo "         ALLOW_PRODUCTION_RESTORE=yes — dan pastikan Anda memang bermaksud begitu." >&2
            exit 77
        fi
        echo "restore: PERINGATAN — menimpa basis data '${TARGET_DB}' atas permintaan eksplisit."
        ;;
esac

DB_USERNAME="${DB_USERNAME:-$(env_value DB_USERNAME)}"
DB_PASSWORD="${DB_PASSWORD:-$(env_value DB_PASSWORD)}"
DB_HOST="${DB_HOST:-$(env_value DB_HOST)}"
DB_PORT="${DB_PORT:-$(env_value DB_PORT)}"

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"

MYSQL="${MYSQL_BIN:-mysql}"
command -v "${MYSQL}" >/dev/null 2>&1 || {
    echo "restore: ${MYSQL} tidak ditemukan. Setel MYSQL_BIN." >&2
    exit 1
}

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

echo "restore: mengosongkan dan memulihkan '${TARGET_DB}' dari ${DUMP_FILE}"

# Basis datanya dibuat ulang, bukan dihapus tabel per tabel: dump berisi
# CREATE TABLE, dan skema lama yang tersisa akan bertabrakan dengannya.
"${MYSQL}" --defaults-extra-file="${OPTS_FILE}" \
    -e "DROP DATABASE IF EXISTS \`${TARGET_DB}\`; CREATE DATABASE \`${TARGET_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

case "${DUMP_FILE}" in
    *.gz)
        gunzip -c "${DUMP_FILE}" | "${MYSQL}" --defaults-extra-file="${OPTS_FILE}" "${TARGET_DB}"
        ;;
    *)
        "${MYSQL}" --defaults-extra-file="${OPTS_FILE}" "${TARGET_DB}" < "${DUMP_FILE}"
        ;;
esac

echo "restore: selesai. Verifikasi dengan:"
echo "         DB_DATABASE=${TARGET_DB} php artisan migrate:status"
