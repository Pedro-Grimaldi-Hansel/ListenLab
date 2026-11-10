<?php
declare(strict_types=1);

namespace ListenLab\Storage;

use ListenLab\AppError;

final class PrivateJsonStore
{
    public function __construct(private readonly string $directory, private readonly string $name)
    {
    }

    public function locked(callable $operation): mixed
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new AppError('Não foi possível criar o diretório privado de autenticação.');
        }
        $lock = fopen($this->directory . '/' . $this->name . '.lock', 'c');
        if (!$lock) {
            throw new AppError('Não foi possível abrir o lock de autenticação.');
        }
        chmod($this->directory . '/' . $this->name . '.lock', 0600);
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new AppError('Não foi possível obter o lock de autenticação.');
            }
            return $operation($this);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function read(): ?array
    {
        $path = $this->path();
        if (!is_file($path)) {
            return null;
        }
        try {
            $value = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new AppError('Arquivo privado de autenticação inválido; autorize novamente.');
        }
        if (!is_array($value)) {
            throw new AppError('Arquivo privado de autenticação inválido; autorize novamente.');
        }
        return $value;
    }

    public function write(array $value): void
    {
        $temporary = $this->path() . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $bytes = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $file = fopen($temporary, 'xb');
        if (!$file) {
            throw new AppError('Não foi possível persistir a autorização.');
        }
        try {
            chmod($temporary, 0600);
            if (fwrite($file, $bytes) !== strlen($bytes) || !fflush($file) || !fsync($file)) {
                throw new AppError('Não foi possível persistir a autorização.');
            }
        } finally {
            fclose($file);
        }
        if (!rename($temporary, $this->path())) {
            throw new AppError('Não foi possível finalizar a persistência da autorização.');
        }
    }

    public function delete(): void
    {
        if (is_file($this->path()) && !unlink($this->path())) {
            throw new AppError('Não foi possível remover a autorização inválida.');
        }
    }

    private function path(): string
    {
        return $this->directory . '/' . $this->name . '.json';
    }
}
