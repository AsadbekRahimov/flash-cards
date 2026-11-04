<?php

declare(strict_types=1);

namespace App\Filament\Resources\Support;

use App\Domain\Billing\Support\PayerSnapshot;
use App\Domain\Billing\Support\PayloadMasker;
use App\Models\Learner;
use App\Models\Organization;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\HtmlString;

/** Shared labels of the read-only billing screens (payments, orders, provider transactions). */
final class BillingDisplay
{
    /**
     * @param  PayerSnapshot|null  $payer  the record's payer snapshot (docs/03 §2.27, §2.29): shown when the subscriber row is gone
     */
    public static function subscriber(string $type, int $id, ?Model $subscriber, ?PayerSnapshot $payer = null): string
    {
        if ($subscriber instanceof Learner) {
            $name = trim(($subscriber->first_name ?? '').' '.($subscriber->last_name ?? ''));
            $username = $subscriber->username !== null ? ' @'.$subscriber->username : '';

            return trim($name.$username).' #'.$subscriber->id;
        }

        if ($subscriber instanceof Organization) {
            return $subscriber->name.' #'.$subscriber->id;
        }

        $snapshot = $payer?->label();

        return ($snapshot ?? AdminLabels::value('common.subscriber_type', $type)).' #'.$id.' '.__('admin.common.deleted');
    }

    /**
     * How a list loads the `subscriber` of its rows - `->with('subscriber', BillingDisplay::subscriberColumns())` - with only
     * the columns {@see subscriber()} prints: a learner is read whole, with its `meta` and a dozen more columns, for the
     * sake of a name otherwise.
     *
     * @return Closure(Relation<*, *, *>): void
     */
    public static function subscriberColumns(): Closure
    {
        return static function (Relation $subscriber): void {
            if ($subscriber instanceof MorphTo) {
                $subscriber->constrain([
                    Learner::class => fn (Builder $learners) => $learners->select(['id', 'first_name', 'last_name', 'username']),
                    Organization::class => fn (Builder $organizations) => $organizations->select(['id', 'name']),
                ]);
            }
        };
    }

    /** A masked payload as a preformatted block (HTML-escaped; secrets never reach the page). */
    public static function payload(mixed $payload): HtmlString
    {
        return new HtmlString('<pre style="white-space: pre-wrap; word-break: break-word; font-size: 0.8rem;">'.e(PayloadMasker::json($payload)).'</pre>');
    }
}
