<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Domain\Localization\Services\LocaleResolver;

/**
 * What Telegram shows around the bot (docs/04 B.3): the command list behind "/" for each chat type and interface
 * language, and the permanent "LexiFlow" button of private chats that opens the Mini App. Only builds the data;
 * `telegram:setup-bot` sends it. The descriptions are in lang/{ru,uz}/commands.php.
 *
 * The "/" menu of a group is shown to every member, so the teacher's commands are listed for the administrators of
 * the group only (`all_chat_administrators`); everybody else sees `/help`. Telegram takes the first list it finds for
 * a person, so an administrator gets just the administrators' list and it repeats `/help`. A teacher who is not an
 * administrator of the group types the commands by hand.
 */
final class BotMenuBuilder
{
    /**
     * BotCommandScope type => [group in the `commands` lang file, commands in the order of the menu].
     *
     * @var array<string, array{0: string, 1: list<string>}>
     */
    public const SCOPES = [
        'all_private_chats' => ['private', ['today', 'settings', 'invite', 'pro', 'promo', 'help']],
        'all_group_chats' => ['group', ['help']],
        'all_chat_administrators' => ['group', ['start_training', 'start_exam', 'close_exam', 'help']],
    ];

    /** The caption of the menu button; /help tells people to look for it. */
    public const MENU_BUTTON_TEXT = 'LexiFlow';

    public function __construct(private readonly LocaleResolver $locales) {}

    /**
     * One list per chat type and supported language. The default language goes without `language_code`: Telegram
     * shows that list to everyone whose language has no list of its own; every other language is set by its code.
     *
     * @return list<array{scope: array{type: string}, locale: string, language_code: string|null, commands: list<array{command: string, description: string}>}>
     */
    public function commandSets(): array
    {
        $default = $this->locales->default();
        $sets = [];

        foreach (self::SCOPES as $type => [$group, $commands]) {
            foreach ($this->locales->supported() as $locale) {
                $sets[] = [
                    'scope' => ['type' => $type],
                    'locale' => $locale,
                    'language_code' => $locale === $default ? null : $locale,
                    'commands' => array_map(
                        fn (string $command): array => [
                            'command' => $command,
                            'description' => __("commands.{$group}.{$command}", [], $locale),
                        ],
                        $commands,
                    ),
                ];
            }
        }

        return $sets;
    }

    /** `{TWA_BASE_URL}/twa/`, without a doubled slash. */
    public function miniAppUrl(): string
    {
        return rtrim((string) config('twa.base_url'), '/').'/twa/';
    }

    /**
     * The `web_app` menu button, or null while the Mini App is not on https: Telegram refuses any other URL.
     *
     * @return array{type: string, text: string, web_app: array{url: string}}|null
     */
    public function menuButton(): ?array
    {
        $url = $this->miniAppUrl();
        $parts = parse_url($url);

        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === '') {
            return null;
        }

        return ['type' => 'web_app', 'text' => self::MENU_BUTTON_TEXT, 'web_app' => ['url' => $url]];
    }
}
