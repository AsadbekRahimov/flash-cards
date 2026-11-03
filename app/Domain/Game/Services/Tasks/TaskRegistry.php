<?php

declare(strict_types=1);

namespace App\Domain\Game\Services\Tasks;

use App\Domain\Game\Enums\TaskKind;
use App\Domain\Practice\DTO\SnapshotElement;
use Illuminate\Contracts\Container\Container;
use LogicException;

/**
 * The handler of each kind of task (docs/18 §12.1): the five kinds made of words go to {@see WordTaskHandler}, the four kinds of the
 * practice to {@see PracticeTaskAdapter}. A handler is made by the container, so a test puts its own in place with
 * `app()->instance(WordTaskHandler::class, $fake)`. The registry holds only the container (no loop of constructors).
 */
final class TaskRegistry
{
    public function __construct(private readonly Container $container) {}

    public function for(TaskKind $kind): TaskHandler
    {
        $handler = $this->container->make($kind->isWordTask() ? WordTaskHandler::class : PracticeTaskAdapter::class);

        if (! in_array($kind, $handler->kinds(), true)) {
            throw new LogicException("The handler registered for the kind {$kind->value} does not play it.");
        }

        return $handler;
    }

    /** The handler of the element by its `kind`. @throws LogicException an element of a kind no handler plays */
    public function forElement(SnapshotElement $element): TaskHandler
    {
        $kind = TaskKind::tryFrom($element->kind) ?? throw new LogicException("An element of the unknown kind {$element->kind}.");

        return $this->for($kind);
    }
}
