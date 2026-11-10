<?php
declare(strict_types=1);

namespace ListenLab\Storage;

use ListenLab\AppError;

final class RawStore
{
    public function __construct(public readonly string $root, public readonly ?string $archiveRoot = null)
    {
        if ($archiveRoot) {
            $local = $this->normalized($root);
            $archive = $this->normalized($archiveRoot);
            if ($local === $archive || str_starts_with($archive . '/', $local . '/')) {
                throw new AppError('O arquivo de backup deve ficar fora da pasta RAW ativa.');
            }
        }
    }

    public function save(string $bytes, int $batchId): RawArtifact
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $hash = hash('sha256', $bytes);
        $this->ensureObject($bytes);
        $path = 'receipts/' . $now->format('Y/m/d') . '/batch-' . $batchId . '-' . bin2hex(random_bytes(12)) . '.json';
        $this->publish($this->root . '/' . $path, $this->reference($hash, strlen($bytes)));
        return new RawArtifact($path, $hash, strlen($bytes), $now->format('Y-m-d\TH:i:s.uP'));
    }

    public function readVerified(string $path, string $sha256): string
    {
        $this->checkHash($sha256);
        $bytes = file_get_contents($this->contained($this->root, $path));
        if ($bytes === false) throw new AppError('Não foi possível ler o RAW.');
        // Literal legacy bytes win, even if the payload resembles a reference.
        if (hash_equals($sha256, hash('sha256', $bytes))) return $bytes;
        $reference = json_decode($bytes, true);
        if (!is_array($reference) || ($reference['listenlab_raw_reference'] ?? null) !== 1
            || ($reference['sha256'] ?? null) !== $sha256 || !is_int($reference['size_bytes'] ?? null)) {
            throw new AppError('SHA-256 do RAW não confere. O arquivo não será processado.');
        }
        $body = $this->readObject($sha256);
        if (strlen($body) !== $reference['size_bytes']) throw new AppError('Tamanho do RAW não confere.');
        return $body;
    }

    public function readObject(string $hash): string
    {
        return $this->locked($hash, fn (): string => $this->readObjectUnlocked($hash));
    }

    public function ensureObject(string $bytes): array
    {
        $hash = hash('sha256', $bytes);
        return $this->locked($hash, function () use ($hash, $bytes): array {
            if ($this->findObject($hash) !== null) {
                $this->readObjectUnlocked($hash);
                return $this->objectInfo($hash);
            }
            $compressed = gzencode($bytes, 6);
            if ($compressed === false) throw new AppError('Não foi possível compactar o RAW.');
            $gzip = strlen($compressed) < strlen($bytes);
            $this->publish($this->objectPath($this->root, $hash, $gzip), $gzip ? $compressed : $bytes);
            $this->readObjectUnlocked($hash);
            return $this->objectInfo($hash);
        });
    }

    public function objectInfo(string $hash): array
    {
        $this->checkHash($hash);
        $found = $this->findObject($hash);
        if ($found === null) throw new AppError('Conteúdo RAW não encontrado no armazenamento ou no arquivo.');
        [$base, $gzip, $tier] = $found;
        return ['sha256' => $hash, 'encoding' => $gzip ? 'gzip' : 'identity', 'tier' => $tier,
            'stored_bytes' => filesize($this->objectPath($base, $hash, $gzip))];
    }

    public function isReference(string $path, string $hash): bool
    {
        $bytes = file_get_contents($this->contained($this->root, $path));
        if (hash_equals($hash, hash('sha256', $bytes))) return false;
        $value = json_decode($bytes, true);
        return is_array($value) && ($value['listenlab_raw_reference'] ?? null) === 1 && ($value['sha256'] ?? null) === $hash;
    }

    /** Call only after verifying a restorable backup of the body and its index. */
    public function replaceLegacy(string $path, string $hash): void
    {
        $body = $this->readVerified($path, $hash);
        $this->ensureObject($body);
        if ($this->isReference($path, $hash)) return;
        $target = $this->contained($this->root, $path);
        $temporary = $target . '.' . bin2hex(random_bytes(8)) . '.partial';
        $this->publish($temporary, $this->reference($hash, strlen($body)));
        if (!rename($temporary, $target)) throw new AppError('Não foi possível substituir o RAW legado; original preservado.');
        chmod($target, 0440);
        $this->readVerified($path, $hash);
    }

    public function referenceBytes(string $path): int
    {
        return (int) filesize($this->contained($this->root, $path));
    }

    public function backupObject(string $bytes): void
    {
        if (!$this->archiveRoot) throw new AppError('Configure RAW_ARCHIVE_PATH antes de arquivar.');
        $hash = hash('sha256', $bytes);
        $target = $this->objectPath($this->archiveRoot, $hash, true);
        if (!is_file($target)) {
            $compressed = gzencode($bytes, 6);
            if ($compressed === false) throw new AppError('Falha na compactação do backup.');
            $this->publish($target, $compressed);
        }
        $this->verifyBackupObject($hash);
    }

    public function verifyBackupObject(string $hash): void
    {
        if (!$this->archiveRoot) throw new AppError('Arquivo de backup não configurado.');
        $this->readBody($this->archiveRoot, $hash, true);
    }

    /** The caller also verifies the database/index snapshot before eviction. */
    public function evictLocal(string $hash): void
    {
        $this->locked($hash, function () use ($hash): void {
            $this->verifyBackupObject($hash);
            foreach ([false, true] as $gzip) {
                $path = $this->objectPath($this->root, $hash, $gzip);
                if (is_file($path)) {
                    $this->contained($this->root, substr($path, strlen(rtrim($this->root, '/\\')) + 1));
                    if (!unlink($path)) throw new AppError('Não foi possível liberar a cópia RAW local.');
                }
            }
        });
    }

    public function publish(string $path, string $bytes): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new AppError('Não foi possível criar o diretório privado RAW.');
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.partial';
        $file = fopen($temporary, 'xb');
        if (!$file) throw new AppError('Não foi possível criar um novo arquivo RAW exclusivo.');
        try {
            chmod($temporary, 0600);
            $offset = 0;
            while ($offset < strlen($bytes)) {
                $written = fwrite($file, substr($bytes, $offset));
                if ($written === false || $written === 0) throw new AppError('Escrita incompleta; .partial preservado.');
                $offset += $written;
            }
            if (!fflush($file) || !fsync($file)) throw new AppError('Não foi possível confirmar a escrita; .partial preservado.');
        } finally { fclose($file); }
        if (!link($temporary, $path)) throw new AppError('Publicação recusada para não sobrescrever um arquivo existente.');
        unlink($temporary);
        chmod($path, 0440);
    }

    private function reference(string $hash, int $size): string
    {
        return json_encode(['listenlab_raw_reference' => 1, 'sha256' => $hash, 'size_bytes' => $size], JSON_THROW_ON_ERROR) . "\n";
    }

    private function readObjectUnlocked(string $hash): string
    {
        $found = $this->findObject($hash);
        if ($found === null) throw new AppError('Conteúdo RAW não encontrado; preserve os recibos e confira o arquivo de backup.');
        return $this->readBody($found[0], $hash, $found[1]);
    }

    private function readBody(string $base, string $hash, bool $gzip): string
    {
        $relative = 'objects/' . substr($hash, 0, 2) . '/' . $hash . ($gzip ? '.json.gz' : '.json');
        $bytes = file_get_contents($this->contained($base, $relative));
        $body = $gzip ? @gzdecode($bytes) : $bytes;
        if ($body === false || !hash_equals($hash, hash('sha256', $body))) throw new AppError('SHA-256 do conteúdo RAW não confere; nenhuma cópia será removida.');
        return $body;
    }

    private function findObject(string $hash): ?array
    {
        foreach ([[$this->root, 'local'], [$this->archiveRoot, 'archive']] as [$base, $tier]) {
            if (!$base) continue;
            foreach ([true, false] as $gzip) if (is_file($this->objectPath($base, $hash, $gzip))) return [$base, $gzip, $tier];
        }
        return null;
    }

    private function objectPath(string $base, string $hash, bool $gzip): string
    {
        $this->checkHash($hash);
        return rtrim($base, '/\\') . '/objects/' . substr($hash, 0, 2) . '/' . $hash . ($gzip ? '.json.gz' : '.json');
    }

    private function checkHash(string $hash): void
    {
        if (!preg_match('/^[0-9a-f]{64}$/D', $hash)) throw new AppError('SHA-256 RAW inválido.');
    }

    private function contained(string $base, string $relative): string
    {
        $root = realpath($base);
        $full = realpath($base . '/' . $relative);
        if (!$root || !$full || !str_starts_with($this->normalized($full), $this->normalized($root) . '/') || !is_file($full)) {
            throw new AppError('Artefato RAW não encontrado dentro do armazenamento configurado.');
        }
        return $full;
    }

    private function normalized(string $path): string
    {
        $path = str_replace('\\', '/', realpath($path) ?: $path);
        return rtrim(PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path, '/');
    }

    private function locked(string $hash, callable $work): mixed
    {
        $this->checkHash($hash);
        $directory = $this->root . '/.locks';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new AppError('Não foi possível criar o lock RAW.');
        $lock = fopen($directory . '/' . $hash . '.lock', 'c');
        if (!$lock) throw new AppError('Não foi possível abrir o lock RAW.');
        chmod($directory . '/' . $hash . '.lock', 0600);
        try { if (!flock($lock, LOCK_EX)) throw new AppError('Não foi possível bloquear o conteúdo RAW.'); return $work(); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
    }
}
