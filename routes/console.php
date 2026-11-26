<?php

use App\Enums\PublicationStatus;
use App\Jobs\PublishPublication;
use App\Models\Publication;
use App\Services\Content\CurrentPublicationApproval;
use App\Services\Publishing\BlogTransportCapabilities;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('publications:dispatch', function (): void {
    if (app()->environment('staging')) {
        $this->comment('Publishing is disabled in staging.');

        return;
    }

    $waitingForMedia = 0;

    Publication::query()
        ->where('status', PublicationStatus::Pending)
        ->where(fn ($query) => $query->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now()))
        ->orderBy('scheduled_at')
        ->eachById(function (Publication $publication) use (&$waitingForMedia): void {
            try {
                app(CurrentPublicationApproval::class)->requireCurrent($publication->id);
            } catch (QueryException $exception) {
                // Infrastructure/schema failures must not masquerade as missing approval.
                throw $exception;
            } catch (RuntimeException) {
                // No approval, stale binding or unverifiable media: never enqueue.
                return;
            }

            $version = DB::table('content_versions')
                ->where('id', $publication->content_version_id)
                ->where('content_item_id', $publication->content_item_id)
                ->first();
            if ($version === null) {
                return;
            }
            if (! app(BlogTransportCapabilities::class)->supportsVersion($version)) {
                $waitingForMedia++;

                return;
            }

            // Queue admission only; the worker must independently revalidate before I/O.
            PublishPublication::dispatch($publication->id);
        });

    if ($waitingForMedia > 0) {
        $this->comment("Waiting for immutable media transport: {$waitingForMedia} publication(s).");
    }
})->purpose('Dispatch all due channel publications');

Schedule::command('publications:dispatch')->everyMinute()->withoutOverlapping();

Schedule::command('agents:dispatch')->everyMinute()->withoutOverlapping();
