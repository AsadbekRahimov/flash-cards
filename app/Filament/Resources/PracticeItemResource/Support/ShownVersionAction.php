<?php

declare(strict_types=1);

namespace App\Filament\Resources\PracticeItemResource\Support;

use App\Models\PracticeItem;
use Closure;
use Filament\Actions\Action;

/**
 * A button that acts on the CURRENT version of a task ("Проверено", "Опубликовать") and carries the id of the version the page
 * showed when the button was drawn: the click mounts the action with `{version: id}` as its arguments, and PracticeItemService
 * refuses (`version_changed`) when the current version is another one by then - an import or another editor wrote a newer one, and
 * the admin would mark as checked, or publish, a text nobody has read.
 *
 * The version is the one of the record of the row (the list draws it again with every request), or, where the page keeps what its
 * form shows apart from the record (the edit page), what `shownVersion()` says.
 */
final class ShownVersionAction extends Action
{
    /** The name of the argument the service reads back ({@see PracticeItemActions::expectedVersion()}). */
    public const ARGUMENT = 'version';

    /** @var (Closure(): ?int)|null */
    private ?Closure $shownVersion = null;

    /** The version the page shows, when that is not the current one of the record. @param  (Closure(): ?int)|null  $callback */
    public function shownVersion(?Closure $callback): static
    {
        $this->shownVersion = $callback;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getInvokedArguments(): array
    {
        $record = $this->getRecord();
        $version = $this->shownVersion !== null
            ? ($this->shownVersion)()
            : ($record instanceof PracticeItem ? $record->current_version_id : null);

        return [...(parent::getInvokedArguments() ?? []), self::ARGUMENT => $version];
    }
}
