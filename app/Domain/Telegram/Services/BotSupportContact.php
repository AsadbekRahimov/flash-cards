<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Domain\Site\Services\SiteLinks;

/**
 * The support contact (`BILLING_SUPPORT_CONTACT`, see {@see SiteLinks::supportContact()}) as a line of a bot message.
 * Empty when no contact is configured: a message never points to a contact that does not exist.
 */
final class BotSupportContact
{
    public function __construct(private readonly SiteLinks $links) {}

    /** The contact as the operator wrote it (`@name`, e-mail, link); null when none is configured. */
    public function label(): ?string
    {
        return $this->links->supportContact()['label'] ?? null;
    }

    /**
     * "\nSupport: <contact>" to append to a message, or '' without a contact. `$html` escapes the contact for `parse_mode=HTML`.
     */
    public function line(string $locale, bool $html = false): string
    {
        $contact = $this->links->supportContact();
        if ($contact === null) {
            return '';
        }

        return "\n".__('bot.support.line', ['contact' => $html ? e($contact['label']) : $contact['label']], $locale);
    }

    /** "Group is not active yet. Write to support" — the answer to a teacher command of a group that is not active. */
    public function groupInactive(string $locale): string
    {
        return __('errors.group_inactive', ['support' => $this->line($locale)], $locale);
    }
}
