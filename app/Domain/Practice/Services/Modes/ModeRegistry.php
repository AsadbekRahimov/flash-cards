<?php

declare(strict_types=1);

namespace App\Domain\Practice\Services\Modes;

use App\Domain\Practice\Enums\PracticeMode;
use Illuminate\Contracts\Container\Container;
use LogicException;

/**
 * The handler of each mode (docs/16 §1.2). A handler is made by the container, so a test (or a later task) puts its own
 * in place with `app()->instance(PairsMode::class, $fake)`. A switched-off mode IS in the registry: the flag
 * `practice.modes.<mode>` is checked by PracticeAccess only when something is created; the sessions and matches that
 * are on keep their handler.
 *
 * The registry holds only the container and makes a handler when asked, so the services that a handler depends on
 * (PracticeMaterialService asks the registry for the availability of every mode) do not form a loop of constructors.
 */
final class ModeRegistry
{
    public function __construct(private readonly Container $container) {}

    public function for(PracticeMode $mode): PracticeModeHandler
    {
        // Whatever the container hands over (a test double, say) must really be the handler of this mode.
        $handler = $this->container->make($this->handlerClass($mode));

        if (! $handler instanceof PracticeModeHandler || $handler->mode() !== $mode) {
            throw new LogicException("The handler registered for the mode {$mode->value} is not a handler of that mode.");
        }

        return $handler;
    }

    /**
     * Every mode's handler, in the order of {@see PracticeMode::cases()}.
     *
     * @return list<PracticeModeHandler>
     */
    public function all(): array
    {
        return array_map($this->for(...), PracticeMode::cases());
    }

    /** The class the container is asked for; bind it to another class or instance to replace the handler. */
    private function handlerClass(PracticeMode $mode): string
    {
        return match ($mode) {
            PracticeMode::Pairs => PairsMode::class,
            PracticeMode::Collocation => CollocationMode::class,
            PracticeMode::Sentence => SentenceMode::class,
            PracticeMode::Dictation => DictationMode::class,
        };
    }
}
