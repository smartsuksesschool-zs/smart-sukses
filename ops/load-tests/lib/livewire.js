/*
 * Memanggil metode Livewire 3 dari k6.
 *
 * Snapshot Livewire dilindungi checksum yang ditandatangani server, sehingga
 * payload-nya **tidak dapat dikarang**. Satu-satunya cara yang benar adalah
 * memuat halamannya lebih dulu, mengambil snapshot yang baru saja dikirim
 * server, lalu mengirimkannya kembali — persis yang dilakukan peramban.
 *
 * Itu juga membuat skenarionya jujur: kalau kontrak Livewire berubah, skripnya
 * gagal terang-terangan lewat `check` yang merah, bukan diam-diam mengukur
 * endpoint yang menolak setiap permintaan dan tampak sangat cepat.
 */

import http from 'k6/http';

/** Mengambil `wire:snapshot` dan `wire:id` komponen pertama pada halaman. */
export function componentFrom(body) {
  const snap = /wire:snapshot="([^"]+)"/.exec(body);
  const id = /wire:id="([^"]+)"/.exec(body);

  if (!snap || !id) {
    return null;
  }

  return {
    // Atribut HTML datang dalam bentuk ter-escape.
    snapshot: unescapeHtml(snap[1]),
    id: id[1],
  };
}

/** Token CSRF dari `<meta name="csrf-token">`. */
export function csrfFrom(body) {
  const m = /<meta name="csrf-token" content="([^"]+)"/.exec(body);

  return m ? m[1] : null;
}

function unescapeHtml(value) {
  return value
    .replace(/&quot;/g, '"')
    .replace(/&#039;/g, "'")
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&amp;/g, '&');
}

/**
 * Memanggil satu metode pada komponen Livewire.
 *
 * @param {string}   base      URL dasar
 * @param {object}   component hasil `componentFrom()`
 * @param {string}   csrf      token CSRF halaman
 * @param {string}   method    nama metode, mis. "choose"
 * @param {Array}    params    argumen metode
 * @param {object}   headers   header tambahan (cookie sesi)
 */
export function callMethod(base, component, csrf, method, params, headers) {
  const payload = {
    _token: csrf,
    components: [
      {
        snapshot: component.snapshot,
        updates: {},
        calls: [{ path: '', method: method, params: params }],
      },
    ],
  };

  return http.post(base + '/livewire/update', JSON.stringify(payload), {
    headers: Object.assign(
      {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-Livewire': 'true',
      },
      headers || {}
    ),
    tags: { name: 'POST /livewire/update (' + method + ')' },
  });
}
