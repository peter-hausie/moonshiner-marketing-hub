<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InventoryContentHub extends Command
{
    protected $signature = 'content-hub:inventory';

    protected $description = 'Read-only counts and fingerprints of legacy records (no content or credentials output)';

    public function handle(): int
    {
        // Fixed columns keep this baseline comparable after additive migrations.
        $columns = [
            'users' => ['id', 'name', 'email', 'role', 'email_verified_at', 'created_at', 'updated_at'],
            'content_items' => ['id', 'title', 'body', 'context', 'status', 'channels', 'scheduled_at', 'submitted_by', 'reviewed_by', 'review_notes', 'created_at', 'updated_at', 'content_type', 'image_path'],
            'publications' => ['id', 'content_item_id', 'channel', 'status', 'scheduled_at', 'published_at', 'external_id', 'external_url', 'attempts', 'last_error', 'created_at', 'updated_at'],
        ];

        $tables = DB::transaction(function () use ($columns): array {
            $result = [];
            foreach ($columns as $table => $fields) {
                $hash = hash_init('sha256');
                $count = 0;
                foreach (DB::table($table)->select($fields)->orderBy('id')->cursor() as $row) {
                    // Normalize PDO integer/string differences without conflating null and empty.
                    $values = array_map(
                        fn ($value) => $value === null ? null : (string) $value,
                        array_values((array) $row),
                    );
                    hash_update($hash, json_encode($values, JSON_THROW_ON_ERROR)."\n");
                    $count++;
                }
                $result[$table] = ['count' => $count, 'sha256' => hash_final($hash)];
            }

            return $result;
        });

        $this->line(json_encode([
            'format' => 'content-hub-legacy-inventory-v1',
            'tables' => $tables,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
