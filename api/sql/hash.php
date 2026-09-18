<?php
declare(strict_types=1);

/**
 * CLI helper: print one bcrypt hash. Used when generating seed.sql.
 * Not a web endpoint.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$plain = $argv[1] ?? 'YouthSync1!';
echo password_hash($plain, PASSWORD_DEFAULT), PHP_EOL;
