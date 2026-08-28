/*
 * Skenario C, D, dan H — dasbor siswa, nilai siswa, dan endpoint API baca
 * representatif, seluruhnya lewat token Sanctum.
 *
 * Ketiganya digabung dalam satu skrip karena satu siswa yang membuka
 * aplikasinya memang melakukan ketiganya berurutan; menjalankannya sebagai tiga
 * beban terpisah akan mengukur bentuk lalu lintas yang tidak pernah terjadi.
 *
 * Hanya membaca. Ambang batas yang berlaku: API p95 < 500 ms.
 *
 *   BASE_URL=... STUDENT_EMAIL_PATTERN='loadtest+{vu}@example.test' \
 *   STUDENT_PASSWORD=... STAGE=baseline k6 run ops/load-tests/03-student-api.js
 */

import http from 'k6/http';
import { check, group, sleep } from 'k6';
import { baseUrl, fixtureUser, optionsFor } from './lib/config.js';
import { apiLogin, bearer } from './lib/auth.js';

export const options = optionsFor('api');

const BASE = baseUrl();

/**
 * Login dilakukan sekali di sini, bukan sekali per iterasi.
 *
 * `POST /api/v1/auth/login` dibatasi `throttle:5,1`. Dengan 200 VU yang login
 * setiap iterasi, yang terukur adalah rate limiter — bukan dasbor.
 */
export function setup() {
  if (__ENV.API_TOKEN) {
    return { token: __ENV.API_TOKEN };
  }

  const user = fixtureUser(1);

  return { token: apiLogin(BASE, user.email, user.password) };
}

export default function (data) {
  const auth = bearer(data.token);

  group('identitas', function () {
    const res = http.get(BASE + '/api/v1/auth/me', {
      headers: auth.headers,
      tags: { name: 'GET /api/v1/auth/me' },
    });

    check(res, { 'me 200': (r) => r.status === 200 });
  });

  group('dasbor siswa', function () {
    const res = http.get(BASE + '/api/v1/student/dashboard', {
      headers: auth.headers,
      tags: { name: 'GET /api/v1/student/dashboard' },
    });

    check(res, {
      'dasbor 200': (r) => r.status === 200,
      // Bukan sekadar 200: muatannya benar-benar berisi amplop sukses.
      'dasbor mengirim data': (r) => r.json('success') === true,
    });
  });

  sleep(1);

  group('nilai siswa', function () {
    const res = http.get(BASE + '/api/v1/student/grades', {
      headers: auth.headers,
      tags: { name: 'GET /api/v1/student/grades' },
    });

    check(res, { 'nilai 200': (r) => r.status === 200 });
  });

  group('jadwal siswa', function () {
    const res = http.get(BASE + '/api/v1/student/schedule', {
      headers: auth.headers,
      tags: { name: 'GET /api/v1/student/schedule' },
    });

    check(res, { 'jadwal 200': (r) => r.status === 200 });
  });

  sleep(1);
}
