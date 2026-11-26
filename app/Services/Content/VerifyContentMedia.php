<?php

namespace App\Services\Content;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class VerifyContentMedia
{
    /** Read-only check of one opened object; never repairs or recreates it. */
    public function verify(array $media): void
    {
        $this->inspect($media, false);
    }

    /** Return exactly the verified bytes, never reopen the path after verification. */
    public function read(array $media): string
    {
        return $this->inspect($media, true);
    }

    private function inspect(array $media, bool $capture): string
    {
        if (($media['disk'] ?? null) !== 'content_snapshots'
            || ! is_string($media['sha256'] ?? null)
            || ! preg_match('/\A[a-f0-9]{64}\z/', $media['sha256'])
            || ($media['path'] ?? null) !== $media['sha256']
            || ! is_int($media['bytes'] ?? null)
            || $media['bytes'] < 1 || $media['bytes'] > 25 * 1024 * 1024
            || count($media) !== 4) {
            throw new RuntimeException('Media verification requires canonical snapshot metadata.');
        }
        if (config('filesystems.disks.content_snapshots.driver') !== 'local') {
            throw new RuntimeException('Media verification requires local snapshot storage.');
        }
        $configuredRoot = Storage::disk('content_snapshots')->path('');
        $root = realpath($configuredRoot);
        $public = realpath(Storage::disk('public')->path(''));
        if ($root === false || is_link(rtrim($configuredRoot, DIRECTORY_SEPARATOR))
            || ($public !== false && ($root === $public || str_starts_with($root, $public.DIRECTORY_SEPARATOR)))) {
            throw new RuntimeException('Media verification requires private snapshot storage.');
        }
        $path = $root.DIRECTORY_SEPARATOR.$media['path'];
        clearstatcache(true, $path);
        $entry = @lstat($path);
        if ($entry === false || ($entry['mode'] & 0170000) !== 0100000 || ($entry['mode'] & 0222) !== 0) {
            throw new RuntimeException('Snapshot is missing, unsafe or writable.');
        }
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Snapshot is unreadable.');
        }
        try {
            $before = fstat($stream);
            if ($before === false || $before['ino'] !== $entry['ino'] || $before['dev'] !== $entry['dev']
                || ($before['mode'] & 0170000) !== 0100000 || ($before['mode'] & 0222) !== 0
                || $before['size'] !== $media['bytes']) {
                throw new RuntimeException('Snapshot identity or size changed.');
            }
            $hash = hash_init('sha256');
            $content = '';
            if ($capture) {
                $content = stream_get_contents($stream, $media['bytes'] + 1);
                if ($content === false) {
                    throw new RuntimeException('Snapshot is unreadable.');
                }
                $bytes = strlen($content);
                hash_update($hash, $content);
            } else {
                $bytes = hash_update_stream($hash, $stream, $media['bytes'] + 1);
            }
            $digest = hash_final($hash);
            $after = fstat($stream);
            clearstatcache(true, $path);
            $current = @lstat($path);
            $stable = ['dev', 'ino', 'mode', 'size', 'mtime', 'ctime'];
            $unchanged = $after !== false;
            foreach ($stable as $field) {
                $unchanged = $unchanged && $before[$field] === ($after[$field] ?? null);
            }
            if ($bytes !== $media['bytes'] || ! hash_equals($media['sha256'], $digest)
                || ! $unchanged || $current === false
                || $current['ino'] !== $before['ino'] || $current['dev'] !== $before['dev']
                || $current['mode'] !== $before['mode'] || $current['size'] !== $before['size']) {
                throw new RuntimeException('Snapshot bytes or identity failed verification.');
            }

            return $content;
        } finally {
            fclose($stream);
        }
    }
}
