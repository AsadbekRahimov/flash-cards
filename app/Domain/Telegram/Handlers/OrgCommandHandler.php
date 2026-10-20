<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Organizations\Services\OrgOverviewService;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Handlers\Contracts\UpdateHandler;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;

/**
 * `/org` in a private chat (docs/13 §6.3): for a staff user whose Telegram account is linked
 * (`users.telegram_user_id`) — one block per organization with the role, the seats (owner), the
 * learners active in the last 7 days and the cabinet URL. Anyone else gets a one-line hint. Not
 * registered in TelegramDispatcher yet (its constructor is being changed by 2A.3): add
 * `OrgCommandHandler` to `messageHandlers` before `/start`.
 */
final class OrgCommandHandler implements UpdateHandler
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly OrgOverviewService $overview,
        private readonly LocaleResolver $locales,
    ) {}

    /** @param array<string, mixed> $update */
    public function matches(array $update): bool
    {
        $message = $update['message'] ?? null;
        if (! is_array($message)) {
            return false;
        }

        return ($message['chat']['type'] ?? '') === 'private'
            && preg_match('~^/org(?:@\w+)?(\s|$)~', (string) ($message['text'] ?? '')) === 1;
    }

    public function requiresActiveGroup(): bool
    {
        return false;
    }

    /** @param array<string, mixed> $update */
    public function handle(array $update): void
    {
        /** @var array<string, mixed> $message */
        $message = $update['message'];
        $chatId = (int) $message['chat']['id'];
        $fromId = (int) ($message['from']['id'] ?? 0);
        $languageCode = $message['from']['language_code'] ?? null;
        $locale = $this->locales->fromLanguageCode(is_string($languageCode) ? $languageCode : null);

        /** @var User|null $user */
        $user = $fromId > 0 ? User::query()->where('telegram_user_id', $fromId)->where('is_active', true)->first() : null;
        $organizations = $user?->organizations()->orderBy('organizations.name')->get();

        if ($user === null || $organizations === null || $organizations->isEmpty()) {
            $this->telegram->sendMessage($chatId, __('org.bot.not_staff', [], $locale));

            return;
        }

        $panel = Filament::getPanel('org');
        $lines = [__('org.bot.header', [], $locale)];

        /** @var Organization $organization */
        foreach ($organizations as $organization) {
            $summary = $this->overview->summary($user, $organization);
            $role = (string) $organization->getAttribute('pivot')?->getAttribute('role');
            $params = [
                'name' => $organization->name,
                'role' => __('org.teachers.role.'.$role, [], $locale),
                'active' => $summary->activeLearners7d,
                'url' => (string) $panel->getUrl($organization),
            ];

            if ($role === 'owner') {
                $seats = $summary->seats;
                $params['seats'] = $seats->sponsoring
                    ? $seats->seated.' / '.($seats->limit ?? $this->text('org.bot.seats_unlimited', $locale))
                    : $this->text('org.bot.seats_none', $locale);
                $lines[] = __('org.bot.line', $params, $locale);
            } else {
                $lines[] = __('org.bot.line_teacher', $params, $locale);
            }
        }

        $this->telegram->sendMessage($chatId, implode("\n\n", $lines));
    }

    private function text(string $key, string $locale): string
    {
        $text = __($key, [], $locale);

        return is_string($text) ? $text : $key;
    }
}
