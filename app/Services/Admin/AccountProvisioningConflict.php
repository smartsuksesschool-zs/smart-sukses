<?php

namespace App\Services\Admin;

use RuntimeException;

/**
 * Data berubah antara saat berkas diperiksa dan saat akun ditulis.
 *
 * Dilempar di dalam transaksi, sehingga melemparnya **membatalkan seluruh impor**
 * — bukan hanya baris yang bentrok. Itu memang yang dikehendaki: admin yang
 * menerima "sebagian akun dibuat" tidak punya cara mengetahui sandi mana yang
 * sudah terbit (butir 594).
 */
class AccountProvisioningConflict extends RuntimeException {}
