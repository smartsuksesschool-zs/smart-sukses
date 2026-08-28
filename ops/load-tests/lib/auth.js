/*
 * Dua jalur autentikasi, karena aplikasinya memang punya dua.
 *
 * 1. **Token Sanctum** — dipakai seluruh endpoint `/api/v1/*`. Diperoleh dari
 *    `POST /api/v1/auth/login`, jadi skenario API dapat berdiri sendiri tanpa
 *    fiksur di luar akun ujinya.
 *
 * 2. **Sesi web** — dipakai portal siswa dan seluruh alur CBT, yang tidak punya
 *    endpoint API sama sekali. Sesi tidak dapat dibuat dari k6 tanpa menirukan
 *    protokol internal Livewire, jadi cookie-nya disuplai dari environment dan
 *    dicatat sebagai kebutuhan fiksur, bukan dikarang.
 *
 * Tidak ada kredensial di berkas ini.
 */

import http from 'k6/http';
import { check } from 'k6';

/**
 * Menukar kredensial fiksur dengan token Sanctum.
 *
 * `POST /api/v1/auth/login` dibatasi `throttle:5,1` — lima percobaan per menit
 * per IP. Karena itu login dilakukan **sekali di `setup()`**, bukan sekali per
 * iterasi: 200 VU yang login berulang kali hanya akan mengukur rate limiter.
 */
export function apiLogin(base, email, password) {
  const res = http.post(
    base + '/api/v1/auth/login',
    JSON.stringify({ email: email, password: password, device_name: 'k6-load-test' }),
    {
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      tags: { name: 'POST /api/v1/auth/login' },
    }
  );

  const ok = check(res, {
    'login API berhasil': (r) => r.status === 200,
  });

  if (!ok) {
    throw new Error(
      'Login API gagal (' +
        res.status +
        '). Periksa akun fiksur, dan ingat rate limit 5 percobaan/menit.'
    );
  }

  return res.json('data.token');
}

export function bearer(token) {
  return {
    headers: {
      Authorization: 'Bearer ' + token,
      Accept: 'application/json',
    },
  };
}

/**
 * Cookie sesi web untuk satu VU.
 *
 * Alur CBT tidak punya endpoint API, dan halaman masuknya komponen Livewire —
 * artinya mengautentikasi dari k6 berarti menirukan protokol internal Livewire
 * berikut snapshot ber-checksum-nya. Itu akan mengukur tiruan, bukan aplikasi,
 * dan akan rusak setiap kali Livewire naik versi.
 *
 * Cookie disuplai dari luar sebagai gantinya. Cara memperolehnya ada di
 * docs/load-testing.md. Polanya memuat `{vu}` supaya setiap VU membawa sesi
 * siswa yang berbeda — lihat alasannya di skenario autosave.
 */
export function sessionCookie(vu) {
  const pattern = __ENV.STUDENT_SESSION_COOKIE_PATTERN;

  if (!pattern) {
    throw new Error(
      'STUDENT_SESSION_COOKIE_PATTERN wajib diisi untuk skenario berbasis sesi. ' +
        'Isinya header Cookie lengkap dengan penanda {vu}, mis. ' +
        '"smartsukses_session={vu-cookie}". Lihat docs/load-testing.md bagian Fiksur.'
    );
  }

  return pattern.replace('{vu}', String(vu));
}

export function sessionHeaders(vu) {
  return {
    headers: {
      Cookie: sessionCookie(vu),
      Accept: 'text/html,application/xhtml+xml',
    },
  };
}
