<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Support;

final class UpdateSanitizer
{
    /**
     * @param  array<string, mixed>  $update
     * @return array<string, mixed>
     */
    public static function forLog(array $update): array
    {
        return self::walk($update);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function walk(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = self::walk($v);

                continue;
            }

            if (in_array($k, ['first_name', 'last_name', 'username', 'text', 'caption'], true) && is_string($v)) {
                $data[$k] = self::mask($v);
            }
        }

        return $data;
    }

    private static function mask(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $trimmed = mb_substr($value, 0, 2);

        return $trimmed.'***';
    }
}
