<?php
declare(strict_types=1);

namespace YouthSync\Payments;

use RuntimeException;

/** A PayMongo call failed. Messages are safe to store/show (no keys, no raw bodies). */
final class PayMongoException extends RuntimeException
{
    public function __construct(
        public readonly string $providerCode,
        string $message,
        public readonly int $httpStatus,
    ) {
        parent::__construct($message);
    }
}
