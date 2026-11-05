<?php

declare(strict_types=1);

namespace App\Support;

final class LocalPath
{
    public static function fromProject(string $path): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $path = str_replace('\\', '/', $path);
            if (preg_match('~^[a-z]:/~i', $path) === 1) {
                return $path;
            }
        }

        return str_starts_with($path, '/') ? $path : base_path($path);
    }
}
