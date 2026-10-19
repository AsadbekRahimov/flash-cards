<?php

declare(strict_types=1);

namespace App\Domain\Learners\DTO;

/**
 * One inline button of an onboarding step. `value` goes into `callback_data`; the caption is
 * either a translation key (+ placeholders) or, for the language picker, a literal `label`
 * (each language is always shown in its own name, whatever the current locale).
 */
final readonly class OnboardingOption
{
    /** @param array<string, string> $labelParams */
    public function __construct(
        public string $value,
        public ?string $labelKey = null,
        public array $labelParams = [],
        public ?string $label = null,
    ) {}
}
