<?php

namespace App\Services\Content;

use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

final class SnapshotContentMedia
{
    private const MAX_BYTES = 25 * 1024 * 1024;

    /** Capture bytes, not a mutable public URL. No database or publishing side effects. */
    public function create(string $path): array
    {
        if ($path === '' || preg_match('~[\\\\:\x00]~', $path) || str_starts_with($path, '/') || array_intersect(['.', '..', ''], explode('/', $path))) {
            throw new InvalidArgumentException('A safe relative public media path is required.');
        }
        foreach (['public', 'content_snapshots'] as $disk) {
            if (config("filesystems.disks.$disk.driver") !== 'local') {
                throw new RuntimeException('Media snapshots require local disks.');
            }
        }
        $sourceRoot = realpath(Storage::disk('public')->path(''));
        if ($sourceRoot === false) {
            throw new RuntimeException('Public media root is missing.');
        }
        $source = $sourceRoot;
        foreach (explode('/', $path) as $part) {
            $source .= DIRECTORY_SEPARATOR.$part;
            if (is_link($source)) {
                throw new RuntimeException('Media symlinks are not supported.');
            }
        }
        if (! is_file($source) || ! is_readable($source)) {
            throw new RuntimeException('Media is missing or unreadable.');
        }
        $root = Storage::disk('content_snapshots')->path('');
        if (! is_dir($root) && ! @mkdir($root, 0700, true) && ! is_dir($root)) {
            throw new RuntimeException('Cannot create snapshot storage.');
        }
        $root = realpath($root);
        if ($root === false || $root === $sourceRoot || str_starts_with($root, $sourceRoot.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Snapshot storage must be outside public media.');
        }
        $temp = tempnam($root, '.snapshot-');
        if ($temp === false) {
            throw new RuntimeException('Cannot create snapshot temporary file.');
        }
        $input = $output = false;
        try {
            $input = @fopen($source, 'rb');
            $output = @fopen($temp, 'wb');
            if ($input === false || $output === false) {
                throw new RuntimeException('Cannot open media snapshot streams.');
            }
            $before = fstat($input);
            $bytes = stream_copy_to_stream($input, $output, self::MAX_BYTES + 1);
            if ($bytes === false || $bytes === 0 || $bytes > self::MAX_BYTES || ! fflush($output)) {
                throw new RuntimeException('Media must contain between 1 byte and 25 MiB.');
            }
            $after = fstat($input);
            if ($before['size'] !== $bytes || $before['size'] !== $after['size'] || $before['mtime'] !== $after['mtime'] || $before['ctime'] !== $after['ctime']) {
                throw new RuntimeException('Source media changed while capturing.');
            }
            fclose($output);
            $output = false;
            $digest = hash_file('sha256', $temp);
            if ($digest === false || ! chmod($temp, 0400)) {
                throw new RuntimeException('Cannot finalize media snapshot.');
            }
            $key = $digest;
            $target = $root.DIRECTORY_SEPARATOR.$key;
            // Atomic no-replace installation on the same filesystem. A concurrent
            // identical writer may win; never truncate an existing object.
            if (! @link($temp, $target)) {
                clearstatcache(true, $target);
                if (is_link($target) || ! is_file($target) || filesize($target) !== $bytes || hash_file('sha256', $target) !== $digest) {
                    throw new RuntimeException('Existing media snapshot is corrupt or cannot be installed.');
                }
                // Check mode bits, not is_writable(): owner/root privileges can
                // differ from the append-only contract of the stored object.
                $permissions = fileperms($target);
                if ($permissions === false || ($permissions & 0222) !== 0 || ! is_readable($target)) {
                    throw new RuntimeException('Existing media snapshot is writable or unreadable.');
                }
            }

            return ['disk' => 'content_snapshots', 'path' => $key, 'sha256' => $digest, 'bytes' => $bytes];
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            @unlink($temp);
        }
    }
}
