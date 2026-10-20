<?php

declare(strict_types=1);

namespace App\Filament\Resources\Support;

use App\Domain\Billing\Support\PayloadMasker;
use App\Models\Learner;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/** Shared labels of the read-only billing screens (payments, orders, provider transactions). */
final class BillingDisplay
{
    public static function subscriber(string $type, int $id, ?Model $subscriber): string
    {
        if ($subscriber instanceof Learner) {
            $name = trim(($subscriber->first_name ?? '').' '.($subscriber->last_name ?? ''));
            $username = $subscriber->username !== null ? ' @'.$subscriber->username : '';

            return trim($name.$username).' #'.$subscriber->id;
        }

        if ($subscriber instanceof Organization) {
            return $subscriber->name.' #'.$subscriber->id;
        }

        return ucfirst($type).' #'.$id.' (deleted)';
    }

    /** A masked payload as a preformatted block (HTML-escaped; secrets never reach the page). */
    public static function payload(mixed $payload): HtmlString
    {
        return new HtmlString('<pre style="white-space: pre-wrap; word-break: break-word; font-size: 0.8rem;">'.e(PayloadMasker::json($payload)).'</pre>');
    }

    /** @return array<string, string> */
    public static function options(string ...$values): array
    {
        return array_combine($values, array_map(static fn (string $v): string => ucfirst(str_replace('_', ' ', $v)), $values));
    }
}
