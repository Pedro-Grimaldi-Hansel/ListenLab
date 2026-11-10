<?php
declare(strict_types=1);

namespace ListenLab\Ingestion;

final readonly class ParsedPlay
{
    public function __construct(
        public string $sourceKey,
        public \DateTimeImmutable $playedAt,
        public array $track,
        public ?array $album,
        public ?array $artists,
        public ?array $context,
        public bool $partial,
    ) {
    }
}
