<?php

declare(strict_types=1);

namespace App\Filament\Resources\Support;

/**
 * A number inside a Russian text of the admin panel: «12,34 с», «66,7%», not PHP's «12.34» and «66.7».
 */
final class AdminNumbers
{
    /**
     * The decimal comma and a non-breaking space between the thousands: «1 234,50». A table column keeps its decimals
     * («12,30 с» under «5,25 с»); `$trimZeros` drops the zeros at the end of the fraction for a number in a sentence
     * («95%», not «95,0%»).
     */
    public static function decimal(float|int $value, int $decimals = 1, bool $trimZeros = false): string
    {
        $text = number_format($value, $decimals, ',', "\u{00A0}");

        return $trimZeros && str_contains($text, ',') ? rtrim(rtrim($text, '0'), ',') : $text;
    }
}
