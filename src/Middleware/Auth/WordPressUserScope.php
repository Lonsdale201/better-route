<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Auth;

/** @internal Native WordPress identity is scoped to the downstream pipeline. */
final class WordPressUserScope
{
    /**
     * @param callable(int): void $setCurrentUser
     * @param null|callable(): int $getCurrentUser
     * @param callable(): mixed $operation
     */
    public static function run(int $userId, callable $setCurrentUser, ?callable $getCurrentUser, callable $operation): mixed
    {
        $previous = $getCurrentUser !== null
            ? $getCurrentUser()
            : (function_exists('get_current_user_id') ? (int) get_current_user_id() : 0);

        try {
            $setCurrentUser($userId);
            return $operation();
        } finally {
            $setCurrentUser($previous);
        }
    }
}
