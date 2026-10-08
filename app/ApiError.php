<?php
declare(strict_types=1);
namespace School;
final class ApiError extends \RuntimeException {
    public function __construct(public readonly int $status, public readonly string $errorCode, string $message) { parent::__construct($message); }
}
