<?php
declare(strict_types=1);

namespace ListenLab\Ingestion;

final class RecordProblem extends \ListenLab\AppError
{
    public function __construct(public readonly string $issueCode, string $message, public readonly bool $review = false)
    {
        parent::__construct($message);
    }
}
