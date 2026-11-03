<?php

declare(strict_types=1);

namespace App\Domain\Site\Services;

/**
 * Links and contact details shown on the public site (landing / offer /
 * privacy). Everything comes from config; nothing is rendered when unset.
 */
final class SiteLinks
{
    /** Telegram bot deep link, or null when the bot username is not configured / malformed. */
    public function botUrl(): ?string
    {
        $username = config('telegram.bot_username');
        $username = is_string($username) ? ltrim(trim($username), '@') : '';

        return preg_match('/^[A-Za-z][A-Za-z0-9_]{3,31}$/', $username) === 1
            ? 'https://t.me/'.$username
            : null;
    }

    /**
     * Support contact for display: `href` is null for plain text.
     *
     * @return array{label: string, href: ?string}|null
     */
    public function supportContact(): ?array
    {
        $raw = config('billing.paywall.contact');
        $raw = is_string($raw) ? trim($raw) : '';

        if ($raw === '') {
            return null;
        }

        if (filter_var($raw, FILTER_VALIDATE_EMAIL) !== false) {
            return ['label' => $raw, 'href' => 'mailto:'.$raw];
        }

        if (preg_match('/^@([A-Za-z][A-Za-z0-9_]{3,31})$/', $raw, $m) === 1) {
            return ['label' => $raw, 'href' => 'https://t.me/'.$m[1]];
        }

        if (preg_match('#^https?://\S+$#i', $raw) === 1 && filter_var($raw, FILTER_VALIDATE_URL) !== false) {
            return ['label' => $raw, 'href' => $raw];
        }

        return ['label' => $raw, 'href' => null];
    }

    /** Whether the offer / privacy pages still carry the "draft" notice (`SITE_LEGAL_DRAFT`, on until the owner switches it off). */
    public function legalDraft(): bool
    {
        return (bool) config('site.legal.draft', true);
    }

    /**
     * The contact the legal texts print: the support contact, else the legal e-mail, else a pointer to `/paysupport`.
     * Plain text (the view escapes it).
     */
    public function legalContact(string $locale): string
    {
        $contact = $this->supportContact();
        if ($contact !== null) {
            return $contact['label'];
        }

        $email = config('site.legal.email');
        $email = is_string($email) ? trim($email) : '';

        return $email !== '' ? $email : __('legal.contact_fallback', [], $locale);
    }

    /**
     * The operator's requisites for the last section of the offer: one "label: value" line per filled field of
     * `config('site.legal')`; with none filled, a visible marker for the owner. Plain text, lines joined by "\n".
     */
    public function legalRequisites(string $locale): string
    {
        $lines = [];
        foreach (['name', 'inn', 'address', 'bank', 'bank_account', 'mfo', 'phone', 'email'] as $field) {
            $value = config("site.legal.{$field}");
            $value = is_string($value) ? trim($value) : '';
            if ($value !== '') {
                $lines[] = __("legal.requisite.{$field}", [], $locale).': '.$value;
            }
        }

        return $lines === [] ? __('legal.requisites_missing', [], $locale) : implode("\n", $lines);
    }

    /** Absolute URL of a public page (`home|offer|privacy`) in an explicit locale. */
    public function pageUrl(string $page, string $locale): string
    {
        return $this->absolute(route("site.{$page}.localized", ['locale' => $locale], false));
    }

    /** URL of the unprefixed variant (language negotiated from `Accept-Language`). */
    public function defaultUrl(string $page): string
    {
        return $this->absolute(route("site.{$page}", [], false));
    }

    /**
     * Canonical / hreflang / internal links are built from `APP_URL`, never from the Host of the request:
     * the pages are cacheable and indexable, and a forged `Host` must not poison them.
     */
    private function absolute(string $path): string
    {
        return rtrim((string) config('app.url'), '/').$path;
    }
}
