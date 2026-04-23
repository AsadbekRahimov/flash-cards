<?php

declare(strict_types=1);

namespace App\Domain\Content\DTO;

final class ImportReport
{
    public int $added = 0;

    public int $updated = 0;

    public int $skipped = 0;

    /** @var list<string> */
    public array $errors = [];

    public bool $aborted = false;

    public function ok(): bool
    {
        return ! $this->aborted && $this->errors === [];
    }

    /** @return array{added:int,updated:int,skipped:int,errors:list<string>,aborted:bool} */
    public function toArray(): array
    {
        return [
            'added' => $this->added,
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'errors' => $this->errors,
            'aborted' => $this->aborted,
        ];
    }
}
