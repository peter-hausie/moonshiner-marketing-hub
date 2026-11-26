<?php

namespace App\Services\Agents;

use RuntimeException;
use Throwable;

/** Reads the local OpenBao Agent sink; never shared with remote application agents. */
final class OpenBaoAgentFileToken
{
    public function __construct(private string $path) {}

    public function __debugInfo(): array
    {
        return ['authentication' => 'masked OpenBao Agent sink'];
    }

    public function __serialize(): array
    {
        throw new RuntimeException('Host authentication resolver cannot be serialized.');
    }

    public function __invoke(): string
    {
        $handle = null;
        // Do not expose filesystem paths through application/PHP warning handlers.
        set_error_handler(static function (): never {
            throw new RuntimeException('Host authentication unavailable.');
        });
        try {
            clearstatcache();
            $directory = dirname($this->path);
            $parent = lstat($directory);
            $file = lstat($this->path);
            if (! function_exists('posix_geteuid') || ! str_starts_with($this->path, '/')
                || realpath($directory) !== $directory || ! $parent || ! $file
                || ($parent['mode'] & 0170000) !== 0040000 || ($parent['mode'] & 0077) !== 0
                || $parent['uid'] !== posix_geteuid()
                || ($file['mode'] & 0170000) !== 0100000 || ($file['mode'] & 0077) !== 0
                || $file['uid'] !== posix_geteuid() || $file['nlink'] !== 1) {
                throw new RuntimeException;
            }
            $handle = fopen($this->path, 'rb');
            $opened = $handle ? fstat($handle) : false;
            if (! $opened || $opened['dev'] !== $file['dev'] || $opened['ino'] !== $file['ino']
                || $opened['mode'] !== $file['mode'] || $opened['uid'] !== $file['uid']
                || $opened['nlink'] !== 1) {
                throw new RuntimeException;
            }
            $token = stream_get_contents($handle, 8193);
            if (! is_string($token) || $token === '' || strlen($token) > 8192
                || preg_match('/[\x00-\x20\x7f]/', $token)) {
                throw new RuntimeException;
            }

            return $token;
        } catch (Throwable) {
            throw new RuntimeException('Host authentication unavailable.');
        } finally {
            restore_error_handler();
            unset($token);
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }
}
