<?php

declare(strict_types=1);

namespace Fpjd\Sso;

/**
 * Almacén en disco con expiración y bloqueos atómicos vía flock().
 * Diseñado para entornos monolíticos o servidores únicos.
 */
class FileStore implements StoreInterface
{
    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir
            ?? ($_ENV['SSO_CACHE_DIR'] ?? null)
            ?? (is_dir(getcwd() . '/cache') ? getcwd() . '/cache/sso' : sys_get_temp_dir() . '/fpjd_sso_cache');
    }

    public function get(string $key): ?string
    {
        $file = $this->path($key);

        if (!is_file($file)) {
            return null;
        }

        $raw = @file_get_contents($file);

        if ($raw === false || !str_contains($raw, "\n")) {
            return null;
        }

        [$expires, $value] = explode("\n", $raw, 2);

        if ((int) $expires < time()) {
            @unlink($file);

            return null;
        }

        return $value;
    }

    public function put(string $key, string $value, int $ttl): void
    {
        $this->ensureDir();

        $file = $this->path($key);
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (@file_put_contents($tmp, (time() + $ttl) . "\n" . $value, LOCK_EX) === false) {
            return;
        }

        @chmod($tmp, 0600);
        @rename($tmp, $file);

        // Limpieza oportunista de entradas vencidas (1 de cada 50 escrituras).
        if (random_int(1, 50) === 1) {
            $this->gc();
        }
    }

    /**
     * Toma un lock exclusivo (espera hasta $waitSeconds). Devuelve el handle o null.
     *
     * @return resource|null
     */
    public function lock(string $name, float $waitSeconds = 5.0)
    {
        $this->ensureDir();

        $handle = @fopen($this->path('lock:' . $name), 'c');

        if (!$handle) {
            return null;
        }

        $deadline = microtime(true) + $waitSeconds;

        do {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                return $handle;
            }

            usleep(50_000);
        } while (microtime(true) < $deadline);

        fclose($handle);

        return null;
    }

    /**
     * @param resource|null $handle
     */
    public function unlock($handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function path(string $key): string
    {
        return $this->dir . '/' . hash('sha256', $key) . '.cache';
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0700, true);
        }
    }

    private function gc(): void
    {
        $files = glob($this->dir . '/*.cache');

        if (!$files) {
            return;
        }

        $now = time();

        foreach ($files as $file) {
            if (str_contains(basename($file), 'lock:')) {
                continue;
            }

            $h = @fopen($file, 'r');

            if (!$h) {
                continue;
            }

            $line = fgets($h, 32);
            fclose($h);

            if ($line !== false && (int) trim($line) < $now) {
                @unlink($file);
            }
        }
    }
}
