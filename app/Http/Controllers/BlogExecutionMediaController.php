<?php

namespace App\Http\Controllers;

use App\Services\Publishing\FrozenBlogMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class BlogExecutionMediaController
{
    public function __invoke(Request $request, int $attempt, string $digest): Response
    {
        // Remains disabled until importer integration and hosting log redaction
        // are verified. Signed URLs are bearer access; never persist them in logs.
        abort_unless(config('services.moonshiner_blog.media_delivery_enabled', false), 404);
        abort_unless($request->hasValidSignature()
            && ctype_digit((string) $request->query('expires'))
            && (int) $request->query('expires') <= now()->addMinutes(5)->timestamp, 404);

        $execution = DB::table('publication_attempts as a')
            ->join('publications as p', 'p.id', '=', 'a.publication_id')
            ->join('content_versions as v', 'v.id', '=', 'a.content_version_id')
            ->where('a.id', $attempt)->where('a.status', 'sending')
            ->whereNull('a.finished_at')->whereNotNull('a.payload_sha256')
            ->where('a.started_at', '>=', now()->subMinutes(5))
            ->where('a.started_at', '<=', now())
            ->where('p.status', 'processing')->where('p.channel', 'blog')
            ->whereColumn('a.attempt_number', 'p.attempts')
            ->whereColumn('a.content_item_id', 'p.content_item_id')
            ->whereColumn('a.content_version_id', 'p.content_version_id')
            ->whereColumn('v.content_item_id', 'a.content_item_id')
            ->select('v.media_snapshot')->first();
        abort_if($execution === null, 404);

        $snapshot = json_decode($execution->media_snapshot ?? '', true);
        abort_unless(is_array($snapshot) && is_string($snapshot['sha256'] ?? null)
            && hash_equals($snapshot['sha256'], $digest), 404);
        try {
            $media = FrozenBlogMedia::fromSnapshot($snapshot);
        } catch (RuntimeException) {
            abort(404);
        }

        // Serve the verified in-memory bytes, never reopen a path for streaming.
        return response($media->bytes, 200, [
            'Content-Type' => $media->contentType,
            'Content-Length' => (string) strlen($media->bytes),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
