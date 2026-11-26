<?php

namespace App\Jobs;

use App\Enums\PublicationStatus;
use App\Models\Publication;
use App\Models\PublicationAttempt;
use App\Services\Content\CurrentPublicationApproval;
use App\Services\Publishing\BlogPublisher;
use App\Services\Publishing\BlogTransportCapabilities;
use App\Services\Publishing\BuildFrozenBlogPayload;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class PublishPublication implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $publicationId) {}

    public function handle(BlogPublisher $blogPublisher): void
    {
        // Acknowledge already queued staging jobs without changing editorial state.
        if (app()->environment('staging')) {
            return;
        }

        $claim = DB::transaction(function (): ?array {
            $publication = Publication::query()->lockForUpdate()->findOrFail($this->publicationId);

            if ($publication->status !== PublicationStatus::Pending || $publication->scheduled_at?->isFuture()) {
                return null;
            }

            try {
                $reviewId = app(CurrentPublicationApproval::class)->requireCurrent($publication->id);
            } catch (QueryException $exception) {
                throw $exception;
            } catch (RuntimeException) {
                // A queued job is not approval. Reject without altering editorial state.
                return null;
            }

            // Freeze reviewed text while approval/version locks are still held.
            $version = DB::table('content_versions')->where('id', $publication->content_version_id)->firstOrFail();
            if (! app(BlogTransportCapabilities::class)->supportsVersion($version)) {
                // The provider currently accepts mutable URLs only. Never substitute
                // a legacy URL or silently drop approved media before transport exists.
                return null;
            }
            $payload = app(BuildFrozenBlogPayload::class)->build(
                $version, $publication->scheduled_at?->toIso8601String(),
            );

            // Retain publication/review/version locks through the execution claim.
            $publication->update([
                'status' => PublicationStatus::Processing,
                'attempts' => $publication->attempts + 1,
                'last_error' => null,
            ]);

            $attempt = PublicationAttempt::create([
                'publication_id' => $publication->id,
                'content_item_id' => $publication->content_item_id,
                'content_version_id' => $publication->content_version_id,
                'attempt_number' => $publication->attempts,
                'provenance' => 'publication_review:'.$reviewId,
                'payload_sha256' => $payload->fingerprint(),
                'started_at' => now(),
            ]);

            return [$publication, $attempt, $payload];
        }, attempts: 3);

        if ($claim === null) {
            return;
        }

        [$publication, $attempt, $payload] = $claim;

        try {
            $result = match (strtolower($publication->channel)) {
                'blog' => $blogPublisher->publish($publication, $payload, $attempt->id),
                default => throw new RuntimeException("Kein Publisher für Kanal {$publication->channel} konfiguriert."),
            };

        } catch (Throwable $exception) {
            // An exception does not prove the provider rejected the request.
            // Keep raw provider responses/credentials out of persisted diagnostics.
            DB::transaction(function () use ($publication, $attempt): void {
                $publication->update([
                    'status' => PublicationStatus::Failed,
                    'last_error' => 'publication_outcome_unknown',
                ]);
                $attempt->forceFill([
                    'status' => 'unknown',
                    'error_code' => 'publication_outcome_unknown',
                    'finished_at' => now(),
                ])->save();
            });

            throw $exception;
        }

        // If persistence fails after provider success, leave Processing/started
        // for reconciliation; never classify it as safe to resend.
        DB::transaction(function () use ($publication, $attempt, $result): void {
            $publication->update([
                'status' => PublicationStatus::Published,
                'published_at' => now(),
                'external_id' => (string) $result['id'],
                'external_url' => $result['url'],
            ]);
            $attempt->forceFill([
                'status' => 'published',
                'external_id' => (string) $result['id'],
                'finished_at' => now(),
            ])->save();
        });
    }
}
