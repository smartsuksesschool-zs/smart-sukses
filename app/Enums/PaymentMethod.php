<?php

namespace App\Enums;

/**
 * ERD 2.2 — payments.payment_method.
 *
 * PAYMENT_GATEWAY berada di daftar ERD tetapi tidak punya integrasi di Phase 1
 * (`01-prd/03-phase2-overview.md` menempatkan integrasi Midtrans/Xendit di Phase 2); nilainya disediakan supaya
 * skema tidak berubah saat integrasi itu masuk.
 */
enum PaymentMethod: string
{
    case Cash = 'CASH';
    case Transfer = 'TRANSFER';
    case PaymentGateway = 'PAYMENT_GATEWAY';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Tunai',
            self::Transfer => 'Transfer',
            self::PaymentGateway => 'Payment Gateway',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $carry, self $case) => $carry + [$case->value => $case->label()],
            [],
        );
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
