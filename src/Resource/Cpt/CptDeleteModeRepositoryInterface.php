<?php

declare(strict_types=1);

namespace BetterRoute\Resource\Cpt;

interface CptDeleteModeRepositoryInterface extends CptRepositoryInterface
{
    public function deleteWithMode(string $postType, int $id, string $mode): bool;
}
