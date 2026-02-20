<?php

declare(strict_types=1);

namespace BetterRoute\Storage;

interface WpdbClient
{
    public function prepare(string $query, mixed ...$args): mixed;

    public function get_results(mixed $query, mixed $output = null): mixed;

    public function get_var(mixed $query): mixed;

    public function get_row(mixed $query, mixed $output = null): mixed;
}
