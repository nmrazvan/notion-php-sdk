<?php

namespace Notion\Records\Blocks;

use Illuminate\Support\Collection;
use Notion\Records\Property;
use Notion\Records\RecordInterface;

interface BlockInterface extends RecordInterface
{
    public function getTitle(): string;

    public function getTable(): string;

    public function getProperties(): Collection;

    public function setProperties(Collection $properties): void;

    public function getProperty(string $needle): ?Property;

    public function setProperty(string $key, $value);

    public function getParent(): RecordInterface;

    public function getCollection(): ?CollectionBlock;

    public function getRows(): Collection;
}
