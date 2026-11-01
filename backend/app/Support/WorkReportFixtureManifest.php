<?php

namespace App\Support;

use LogicException;

final class WorkReportFixtureManifest
{
    public function __construct(public readonly string $path)
    {
        self::assertExternalPath($path);
    }

    public static function assertExternalPath(string $path): void
    {
        $parent = realpath(dirname($path));
        if ($parent === false || is_link($path)) {
            throw new LogicException('FIXTURE_MANIFEST_PATH_INVALID');
        }
        $roots = [realpath(base_path()), realpath(dirname(base_path()))];
        $gitFile = dirname(base_path()).DIRECTORY_SEPARATOR.'.git';
        if (is_file($gitFile) && preg_match('/gitdir:\s*(.+)/', file_get_contents($gitFile), $match)) {
            $git = str_replace('\\', '/', trim($match[1]));
            $roots[] = explode('/.git/', $git)[0];
        }
        $canonical = strtolower(str_replace('\\', '/', $parent)).'/';
        foreach (array_filter($roots) as $root) {
            if (str_starts_with($canonical, rtrim(strtolower(str_replace('\\', '/', $root)), '/').'/')) {
                throw new LogicException('FIXTURE_ARTIFACTS_MUST_STAY_OUTSIDE_REPOSITORY');
            }
        }
    }

    public static function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public static function readJson(string $path): array
    {
        $text = file_get_contents($path);
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }
        $value = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($value)) {
            throw new LogicException('FIXTURE_JSON_OBJECT_REQUIRED');
        }

        return $value;
    }

    public function read(): ?array
    {
        if (! is_file($this->path)) {
            return null;
        }
        $envelope = self::readJson($this->path);
        if (! is_array($envelope['data'] ?? null) || ! hash_equals($this->mac($envelope['data']), $envelope['mac'] ?? '')) {
            throw new LogicException('FIXTURE_MANIFEST_SIGNATURE_INVALID');
        }

        return $envelope['data'];
    }

    public function write(array $value): void
    {
        $temporary = $this->path.'.'.bin2hex(random_bytes(8)).'.tmp';
        $stream = fopen($temporary, 'x');
        if ($stream === false) {
            throw new LogicException('FIXTURE_MANIFEST_WRITE_FAILED');
        }
        try {
            chmod($temporary, 0600);
            $json = json_encode(['data' => $value, 'mac' => $this->mac($value)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (fwrite($stream, $json) !== strlen($json) || ! fflush($stream) || ! fsync($stream)) {
                throw new LogicException('FIXTURE_MANIFEST_WRITE_FAILED');
            }
        } finally {
            fclose($stream);
        }
        // Atomic replacement; never delete the previous manifest to make a failed rename appear successful.
        if (! rename($temporary, $this->path)) {
            throw new LogicException('FIXTURE_MANIFEST_REPLACE_FAILED');
        }
    }

    public function exclusive(callable $callback): mixed
    {
        $lock = fopen($this->path.'.lock', 'c');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new LogicException('FIXTURE_MANIFEST_LOCK_FAILED');
        }
        try {
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function mac(array $value): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new LogicException('FIXTURE_SIGNING_KEY_MISSING');
        }

        return hash_hmac('sha256', self::hash($value), $key);
    }
}
