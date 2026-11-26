<?php

namespace App\Services\Agents;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Internal read model, not an authorization boundary or a write-time lock. */
final readonly class DraftContext
{
    private function __construct(public array $data) {}

    public static function forContentItem(int $contentItemId): self
    {
        // One statement observes persisted fields and latest version together.
        $item = DB::table('content_items')
            ->select('content_items.*')
            ->selectSub(DB::table('content_versions')->selectRaw('MAX(version_number)')
                ->whereColumn('content_item_id', 'content_items.id'), 'latest_version')
            ->where('content_items.id', $contentItemId)->firstOrFail();

        return self::fromPersisted($item, $item->latest_version);
    }

    /** Internal writer supplies rows read using current locking reads, not an MVCC snapshot. */
    public static function fromLockedRows(object $item, object $latest): self
    {
        if ((int) $latest->content_item_id !== (int) $item->id) {
            throw new RuntimeException('Draft version belongs to another piece.');
        }

        return self::fromPersisted($item, $latest->version_number);
    }

    private static function fromPersisted(object $item, ?int $latestVersion): self
    {
        if ($latestVersion === null) {
            throw new RuntimeException('Draft context requires an existing version.');
        }

        // Fixed ordering, exact persisted values, explicit protocol domain.
        // Include workflow fields conservatively, without disclosing them all.
        $base = ['contract' => 'draft-context-v1', 'content_item_id' => (int) $item->id];
        foreach (['title', 'body', 'context', 'content_type', 'image_path', 'status',
            'channels', 'scheduled_at', 'submitted_by', 'reviewed_by', 'review_notes'] as $field) {
            $base[$field] = $item->$field;
        }

        return new self([
            'content_item_id' => (int) $item->id,
            'expected_version' => (int) $latestVersion,
            'base_sha256' => hash('sha256', json_encode($base, JSON_THROW_ON_ERROR)),
            'title' => $item->title,
            'body' => $item->body,
            'context' => $item->context,
            'content_type' => $item->content_type,
        ]);
    }

    /** Host registers this digest; result validation uses the same fixed identity tuple. */
    public function jobFingerprint(): string
    {
        return self::jobFingerprintFor($this->data);
    }

    /** Internal input is either server context or a validated DraftSubmission. */
    public static function jobFingerprintFor(array $data): string
    {
        return hash('sha256', json_encode([
            'contract' => 'draft-job-context-v1',
            'content_item_id' => $data['content_item_id'],
            'expected_version' => $data['expected_version'],
            'base_sha256' => $data['base_sha256'],
        ], JSON_THROW_ON_ERROR));
    }

    public function matches(DraftSubmission $submission): bool
    {
        return $this->data['content_item_id'] === $submission->data['content_item_id']
            && $this->data['expected_version'] === $submission->data['expected_version']
            && hash_equals($this->data['base_sha256'], $submission->data['base_sha256']);
    }
}
