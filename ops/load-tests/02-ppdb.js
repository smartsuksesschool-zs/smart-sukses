/*
 * Skenario B — penelusuran PPDB publik.
 *
 * Alur yang paling mungkin ramai bersamaan di dunia nyata: pengumuman
 * pendaftaran dibuka, lalu ratusan orang tua membuka daftar cabang dan formulir
 * pendaftaran dalam menit yang sama.
 *
 * Hanya membaca — formulirnya dimuat, tidak pernah dikirim. Mengirim formulir
 * akan membuat baris pendaftar sungguhan.
 *
 *   BASE_URL=... SCHOOL_CODE=madani STAGE=baseline k6 run ops/load-tests/02-ppdb.js
 */

import http from 'k6/http';
import { check, group, sleep } from 'k6';
import { baseUrl, optionsFor } from './lib/config.js';

export const options = optionsFor('web');

const BASE = baseUrl();
const SCHOOL = __ENV.SCHOOL_CODE || '';

export default function () {
  group('daftar cabang', function () {
    const res = http.get(BASE + '/ppdb', { tags: { name: 'GET /ppdb' } });

    check(res, {
      'daftar cabang 200': (r) => r.status === 200,
    });
  });

  sleep(1);

  if (SCHOOL) {
    group('formulir pendaftaran', function () {
      const res = http.get(BASE + '/ppdb/' + SCHOOL, {
        tags: { name: 'GET /ppdb/{school}' },
      });

      check(res, {
        'formulir 200': (r) => r.status === 200,
        'formulir benar-benar terender': (r) => r.body.indexOf('name="_token"') !== -1,
      });
    });

    sleep(1);
  }

  group('cek status', function () {
    const res = http.get(BASE + '/ppdb/cek-status', {
      tags: { name: 'GET /ppdb/cek-status' },
    });

    check(res, {
      'cek status 200': (r) => r.status === 200,
    });
  });

  sleep(1);
}
