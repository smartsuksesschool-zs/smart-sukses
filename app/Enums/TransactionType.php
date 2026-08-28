<?php

namespace App\Enums;

/**
 * ERD 2.2 — transactions.type, ENUM(INCOME,EXPENSE).
 *
 * Arah kas ditentukan oleh nilai ini, bukan oleh tanda `amount`: kolomnya
 * `DECIMAL(12,2)` tanpa keterangan negatif, dan menyimpan pengeluaran sebagai
 * angka negatif akan membuat dua sumber kebenaran untuk hal yang sama.
 */
enum TransactionType: string
{
    case Income = 'INCOME';

    case Expense = 'EXPENSE';

    public function label(): string
    {
        return match ($this) {
            self::Income => __('Pemasukan'),
            self::Expense => __('Pengeluaran'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Income => 'success',
            self::Expense => 'danger',
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
