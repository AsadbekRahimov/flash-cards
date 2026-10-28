<?php

declare(strict_types=1);

namespace App\Filament\Resources\Support;

use Illuminate\Support\Facades\Lang;

/**
 * Captions of stored values (statuses, plans, providers…) for the badges, selects and filters of the admin panel.
 * They live in `lang/ru/admin.php` under `<path>.<value>`; a value that has no caption is shown as it is, so a status
 * added later never turns into a raw translation key on the screen. Only the display changes — never the stored value.
 */
final class AdminLabels
{
    /** Caption `admin.<path>.<value>`. */
    public static function value(string $path, mixed $value): string
    {
        return self::from('admin.'.$path, $value);
    }

    /** The same for a caption that lives outside `lang/ru/admin.php` (for example the league tiers of `lang/ru/leagues.php`). */
    public static function from(string $path, mixed $value): string
    {
        $value = is_scalar($value) ? (string) $value : '';
        $key = "{$path}.{$value}";

        if ($value !== '' && Lang::has($key)) {
            $label = Lang::get($key);

            if (is_string($label)) {
                return $label;
            }
        }

        return $value;
    }

    /**
     * @param  iterable<string>  $values  the stored values, in the order they should be offered
     * @return array<string, string> stored value => caption
     */
    public static function options(string $path, iterable $values): array
    {
        $options = [];

        foreach ($values as $value) {
            $options[$value] = self::value($path, $value);
        }

        return $options;
    }
}
