<?php
declare(strict_types=1);

namespace ListenLab;

use Dotenv\Dotenv;

final class Config
{
    public function __construct(public readonly string $root, private readonly array $values = [])
    {
    }

    public static function load(string $root): self
    {
        Dotenv::createImmutable($root)->safeLoad();
        return new self($root);
    }

    public function get(string $key, string $default = ''): string
    {
        return (string) ($this->values[$key] ?? $_ENV[$key] ?? $_SERVER[$key] ?? (getenv($key) !== false ? getenv($key) : $default));
    }

    public function required(string $key): string
    {
        $value = $this->get($key);
        if ($value === '') {
            throw new AppError("Configure {$key} no .env ou nos secrets do ambiente; não envie o valor pelo chat.");
        }
        return $value;
    }

    public function secret(string $key, string $defaultFile = ''): string
    {
        $file = $this->get($key . '_FILE', $defaultFile);
        if ($file === '') return $this->required($key);
        if (!preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $file)) $file = $this->root . '/' . $file;
        $value = is_file($file) && is_readable($file) ? file_get_contents($file) : false;
        if ($value === false || rtrim($value, "\r\n") === '') {
            throw new AppError('Secret ' . $key . ' indisponível. Confira o arquivo privado configurado.');
        }
        return rtrim($value, "\r\n");
    }

    public function integer(string $key, int $default, int $min, int $max): int
    {
        $value = filter_var($this->get($key, (string) $default), FILTER_VALIDATE_INT);
        if ($value === false || $value < $min || $value > $max) {
            throw new AppError("{$key} deve ser um inteiro entre {$min} e {$max}.");
        }
        return $value;
    }

    public function path(string $key, string $default): string
    {
        $path = $this->get($key, $default);
        if ($path === '') {
            throw new AppError("{$key} não pode ser vazio.");
        }
        return str_starts_with($path, '/') ? rtrim($path, '/') : $this->root . '/' . rtrim($path, '/');
    }

    public function requireSpotify(): void
    {
        $this->required('SPOTIFY_CLIENT_ID');
        $this->required('SPOTIFY_CLIENT_SECRET');
        $uri = $this->required('SPOTIFY_REDIRECT_URI');
        $parts = parse_url($uri);
        if (!$parts || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || !isset($parts['host'], $parts['scheme']) || !str_starts_with($parts['path'] ?? '', '/')) {
            throw new AppError('SPOTIFY_REDIRECT_URI inválido. Use HTTPS ou HTTP em um IP de loopback explícito.');
        }
        $loopback = in_array($parts['host'], ['127.0.0.1', '[::1]'], true);
        if ($parts['host'] === 'localhost' || ($parts['scheme'] !== 'https' && !($loopback && $parts['scheme'] === 'http'))) {
            throw new AppError('Spotify exige HTTPS; HTTP só é permitido em 127.0.0.1 ou [::1]. localhost não é aceito.');
        }
    }
}
