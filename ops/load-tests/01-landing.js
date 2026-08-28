/*
 * Skenario A — halaman muka publik.
 *
 * Halaman paling terbuka di sistem: tanpa sesi, tanpa tenant, dan satu-satunya
 * yang punya angka eksplisit di roadmap ("halaman utama < 3 detik").
 *
 * Hanya membaca. Aman dijalankan terhadap lingkungan mana pun.
 *
 *   BASE_URL=https://staging.example STAGE=smoke k6 run ops/load-tests/01-landing.js
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { baseUrl, optionsFor } from './lib/config.js';

export const options = optionsFor('web');

const BASE = baseUrl();

export default function () {
  const res = http.get(BASE + '/', { tags: { name: 'GET /' } });

  check(res, {
    'status 200': (r) => r.status === 200,
    // Halaman muka tidak boleh mengalihkan ke halaman masuk mana pun.
    'bukan redirect': (r) => r.status !== 302,
    // Isinya benar-benar terkirim, bukan halaman galat yang kebetulan 200.
    'memuat penanda halaman muka': (r) => r.body.indexOf('id="akses"') !== -1,
  });

  // Jeda memikirkan halaman: tanpa ini yang diukur adalah kecepatan k6
  // membanjiri soket, bukan perilaku pengunjung.
  sleep(1);
}
