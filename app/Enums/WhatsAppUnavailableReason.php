<?php

namespace App\Enums;

/**
 * Mengapa seorang penerima tidak dapat dikirimi tautan wa.me (NOTIF-02).
 *
 * Penerima tanpa nomor yang dapat dipakai **tidak** dibuang dari daftar. Daftar
 * yang diam-diam memendekkan dirinya membuat Admin mengira seluruh penerima
 * terjangkau, padahal justru yang hilang itulah yang perlu dihubungi dengan cara
 * lain (butir 226).
 */
enum WhatsAppUnavailableReason: string
{
    /** Tidak ada nomor sama sekali — baik pada akun maupun pada data anaknya. */
    case MissingPhone = 'MISSING_PHONE';

    /** Ada nomornya, tetapi bukan nomor Indonesia yang dapat dinormalkan. */
    case InvalidPhone = 'INVALID_PHONE';

    /**
     * Orang tua punya lebih dari satu anak dengan `parent_phone` yang berbeda,
     * dan tidak ada dasar untuk memilih salah satunya (butir 225).
     */
    case AmbiguousPhone = 'AMBIGUOUS_PHONE';

    public function label(): string
    {
        return match ($this) {
            self::MissingPhone => 'Nomor HP belum diisi',
            self::InvalidPhone => 'Nomor HP tidak valid',
            self::AmbiguousPhone => 'Nomor HP orang tua berbeda antar anak',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
