<?php
declare(strict_types=1);

namespace YouthSync\Http;

final class SessionLock
{
    /**
     * Release the exclusive session file lock after session data has been read
     * and any required writes (login, logout, regenerate) are finished.
     * $_SESSION remains readable for the rest of the request.
     */
    public static function releaseWriteLock(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }
}
