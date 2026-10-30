<?php

declare(strict_types=1);

namespace App\Filament\Resources\Support;

use App\Domain\Administration\Exceptions\OrganizationGroupLinkException;
use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Content\DTO\ImportIssue;
use App\Domain\Content\Exceptions\CoursePublishException;
use App\Domain\Organizations\Exceptions\OrganizationMembershipException;
use App\Domain\Practice\Exceptions\PracticeContentException;
use App\Domain\Practice\Exceptions\PracticeItemStateException;
use Illuminate\Support\Facades\Lang;
use Throwable;

/**
 * Russian text of a domain error for the notifications of the admin panel.
 *
 * The domain exceptions carry an English message (for logs and for API / bot clients, whose error payloads stay as they
 * are) plus a stable code and the values behind the text. The panel never shows that message: it looks the code up in
 * `lang/ru/admin.php` as `admin.<scope>.errors.<code>` (the scope is the resource or screen: `courses`, `payments`,
 * `organizations.members`...), then as `admin.common.errors.<code>` for the errors several screens share, and fills
 * `:placeholders` from the exception's context. A `cause` in the context picks a more exact sentence of the same error
 * (`<code>_<cause>`, e.g. `not_refundable_no_charge_id`). A code with no text — a new one nobody has worded yet — is shown
 * as the generic `admin.common.errors.unknown`, never as the raw key and never in English; the full detail stays in the
 * log and the audit trail.
 */
final class AdminErrors
{
    /** Text of a thrown domain exception; `$scope` names the screen the action lives on. */
    public static function for(string $scope, Throwable $e): string
    {
        [$code, $context] = match (true) {
            $e instanceof BillingException => [$e->errorCode, self::billingContext($e->context)],
            $e instanceof CoursePublishException => [$e->reason, self::courseContext($e->context)],
            $e instanceof OrganizationMembershipException, $e instanceof OrganizationGroupLinkException => [$e->errorCode, []],
            // The text of a practice task is refused with the field it is in; the editor names the field by its caption.
            $e instanceof PracticeContentException => [$e->errorCode, self::practiceContext([...$e->context, 'field' => AdminLabels::value('practice_items.field_names', $e->field)])],
            $e instanceof PracticeItemStateException => [$e->errorCode, []],
            default => ['', []],
        };

        return self::text($scope, $code, $context);
    }

    /** Text of one reason a lesson import was refused. */
    public static function importIssue(ImportIssue $issue): string
    {
        return self::text('pages.import_lesson', $issue->code, $issue->context);
    }

    /**
     * Text of one reason a practice import was refused. The names of the fields stay as they are in the JSON of the file
     * (`fragments`, `target_words`): that is what the person has in front of them.
     */
    public static function practiceImportIssue(ImportIssue $issue): string
    {
        return self::text('pages.import_practice', $issue->code, self::practiceContext($issue->context));
    }

    /**
     * @param  array<string, mixed>  $context  values for the `:placeholders` of the text
     */
    public static function text(string $scope, string $code, array $context = []): string
    {
        if ($code === '') {
            return self::unknown();
        }

        $cause = $context['cause'] ?? null;
        $codes = is_string($cause) && $cause !== '' ? ["{$code}_{$cause}", $code] : [$code];

        foreach ($codes as $candidate) {
            foreach (["admin.{$scope}.errors.{$candidate}", "admin.common.errors.{$candidate}"] as $key) {
                $text = Lang::has($key) ? Lang::get($key, self::replacements($context)) : null;

                if (is_string($text)) {
                    return $text;
                }
            }
        }

        return self::unknown();
    }

    /** The sentence for a code nobody has worded yet. */
    private static function unknown(): string
    {
        return (string) __('admin.common.errors.unknown');
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, string>
     */
    private static function replacements(array $context): array
    {
        $replacements = [];

        foreach ($context as $name => $value) {
            $replacements[$name] = match (true) {
                is_array($value) => implode(', ', array_map(static fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $value)),
                // 79,5 and 95 (not 95,0): the decimal comma of Russian text.
                is_float($value) => AdminNumbers::decimal($value, 1, trimZeros: true),
                is_scalar($value) => (string) $value,
                default => '',
            };
        }

        return $replacements;
    }

    /**
     * The languages of a practice text in capitals as in the captions of the form («RU», «UZ»), the type of a task by its caption.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private static function practiceContext(array $context): array
    {
        if (isset($context['locale']) && is_string($context['locale'])) {
            $context['locale'] = mb_strtoupper($context['locale']);
        }

        return self::captioned($context, ['existing_type' => 'practice_items.type']);
    }

    /**
     * Provider, plan, tier and subscriber type by their captions («Telegram Stars», «Ученик»), not the stored keys.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private static function billingContext(array $context): array
    {
        return self::captioned($context, ['provider' => 'common.provider', 'plan' => 'common.plan', 'tier' => 'common.tier', 'type' => 'common.subscriber_type']);
    }

    /**
     * Statuses by their captions («Черновик»), languages in capitals as in the title fields («Название (UZ)»).
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private static function courseContext(array $context): array
    {
        $context = self::captioned($context, ['from' => 'courses.status', 'to' => 'courses.status', 'status' => 'courses.status']);

        if (isset($context['locales']) && is_array($context['locales'])) {
            $context['locales'] = array_map(static fn (mixed $locale): string => mb_strtoupper(is_scalar($locale) ? (string) $locale : ''), $context['locales']);
        }

        // «из 1 слова», «из 22 слов»: the noun agrees with the number behind «из». The text is Russian whatever locale the request has.
        if (isset($context['total']) && is_int($context['total'])) {
            $context['words'] = trans_choice('admin.common.words_of', $context['total'], [], 'ru');
        }

        return $context;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, string>  $paths  context key => path of its captions (`admin.<path>.<value>`)
     * @return array<string, mixed>
     */
    private static function captioned(array $context, array $paths): array
    {
        foreach ($paths as $key => $path) {
            if (isset($context[$key])) {
                $context[$key] = AdminLabels::value($path, $context[$key]);
            }
        }

        return $context;
    }
}
