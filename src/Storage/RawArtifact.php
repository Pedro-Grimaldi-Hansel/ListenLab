<?php
declare(strict_types=1);

namespace ListenLab\Storage;

final readonly class RawArtifact
{
    public function __construct(public string $path, public string $sha256, public int $sizeBytes, public string $receivedAt)
    {
    }
}
