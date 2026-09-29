<?php

namespace NepalCauseList\Support;

/**
 * Tiny file-based cache used by the HTTP layer to avoid hammering the court
 * websites. Not required by the library itself — the scraper services never
 * cache on their own, so you can drop in any cache (Redis, APCu, PSR-16, …).
 */
class FileCache
{
    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = rtrim($dir ?? sys_get_temp_dir() . '/nepal-cause-list-cache', '/');
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
    }

    public function get(string $key)
    {
        $file = $this->path($key);
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload) || !isset($payload['expires'], $payload['value'])) {
            return null;
        }
        if ($payload['expires'] !== 0 && $payload['expires'] < time()) {
            @unlink($file);
            return null;
        }
        return $payload['value'];
    }

    public function put(string $key, $value, int $ttlSeconds = 1800): void
    {
        $payload = [
            'expires' => $ttlSeconds > 0 ? time() + $ttlSeconds : 0,
            'value' => $value,
        ];
        @file_put_contents(
            $this->path($key),
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    public function forget(string $key): void
    {
        @unlink($this->path($key));
    }

    private function path(string $key): string
    {
        return $this->dir . '/' . sha1($key) . '.json';
    }
}
