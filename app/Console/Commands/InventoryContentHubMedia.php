<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class InventoryContentHubMedia extends Command
{
    protected $signature = 'content-hub:media-inventory';

    protected $description = 'Read-only fingerprint of referenced public media; fails on missing or unsafe files';

    public function handle(): int
    {
        // Never scan private storage or use a network-backed disk for this audit.
        if (config('filesystems.disks.public.driver') !== 'local') {
            $this->error('Media inventory requires a local public disk.');

            return self::FAILURE;
        }

        $root = realpath(Storage::disk('public')->path(''));
        $hash = hash_init('sha256');
        $references = $verified = $missing = $unsafe = $unreadable = 0;

        foreach (DB::table('content_items')->select(['id', 'image_path'])->orderBy('id')->cursor() as $row) {
            if ($row->image_path === null || $row->image_path === '') {
                continue;
            }
            $references++;
            $path = $row->image_path;
            // Reject traversal, absolute paths and URI schemes before touching files.
            if (str_contains($path, "\0") || str_contains($path, '\\') || str_contains($path, ':') || str_starts_with($path, '/') || in_array('..', explode('/', $path), true)) {
                $unsafe++;

                continue;
            }
            $file = $root === false ? false : realpath($root.DIRECTORY_SEPARATOR.$path);
            if ($file === false) {
                $missing++;

                continue;
            }
            // Resolve symlinks and require the resolved target to stay in public storage.
            if (! str_starts_with($file, $root.DIRECTORY_SEPARATOR)) {
                $unsafe++;

                continue;
            }
            if (! is_file($file) || ! is_readable($file)) {
                $unreadable++;

                continue;
            }
            $digest = @hash_file('sha256', $file);
            if ($digest === false) {
                $unreadable++;

                continue;
            }
            hash_update($hash, json_encode([(string) $row->id, $path, $digest], JSON_THROW_ON_ERROR)."\n");
            $verified++;
        }

        $valid = $missing + $unsafe + $unreadable === 0;
        $this->line(json_encode([
            'format' => 'content-hub-referenced-media-v1',
            'references' => $references,
            'verified' => $verified,
            'missing' => $missing,
            'unsafe' => $unsafe,
            'unreadable' => $unreadable,
            // An incomplete audit must never look like a comparable snapshot.
            'sha256' => $valid ? hash_final($hash) : null,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $valid ? self::SUCCESS : self::FAILURE;
    }
}
