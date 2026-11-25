<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

use App\Domain\Engagement\Enums\ShareKind;
use App\Domain\Engagement\Services\ShareMessageBuilder;
use App\Domain\Engagement\Services\SharePreparer;
use Closure;

/**
 * A shared message before its card is drawn (docs/36 §1в): what {@see ShareMessageBuilder::draft()} decided after every check of the
 * subject - the texts, the link, the keyboard - and the card as a closure that is called only by {@see ShareMessageBuilder::compose()}.
 * `version` stands for everything the message will show, the card included, without drawing it or reading a file: the template version
 * and the settings of the card, the texts, the link. {@see SharePreparer} keys its cache by it, so a tap served from the cache costs the
 * checks and no drawing.
 */
final readonly class ShareDraft
{
    /**
     * @param  array<string, mixed>  $markup  the keyboard of the message (one URL button)
     * @param  Closure(): string  $card  draws (or finds) the card, returns its absolute path
     */
    public function __construct(
        public ShareKind $kind,
        public ?string $subject,
        public string $locale,
        public string $title,
        public string $caption,
        public string $link,
        public array $markup,
        public Closure $card,
        public string $version,
    ) {}
}
