<?php

namespace App\Services\Publishing;

use App\Enums\PublicationStatus;
use App\Models\Publication;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Throwable;

/** Transport adapter for the configured Moonshiner blog destination.
 * Receives an immutable payload; editorial approval belongs to the calling job. */
class BlogPublisher
{
    public function publish(Publication $publication, ?FrozenBlogPayload $payload = null, ?int $attemptId = null): array
    {
        if (app()->environment('staging')) {
            throw new RuntimeException('Publishing is disabled in staging.');
        }

        if ($payload === null) {
            throw new RuntimeException('A frozen execution payload is required.');
        }

        $url = config('services.moonshiner_blog.url');
        $token = config('services.moonshiner_blog.token');

        if (! $url || ! $token) {
            throw new RuntimeException('Die Moonshiner-Blog-API ist nicht konfiguriert.');
        }

        if ($attemptId === null || DB::transactionLevel() !== 0) {
            throw new RuntimeException('A committed execution attempt is required.');
        }

        if (($payload->data['image_url'] ?? null) !== null) {
            throw new RuntimeException('Caller-supplied media URLs are forbidden.');
        }
        if ($payload->mediaDigest !== null && (! config('services.moonshiner_blog.media_delivery_enabled', false)
            || parse_url(route('blog.execution-media', ['attempt' => $attemptId, 'digest' => $payload->mediaDigest]), PHP_URL_SCHEME) !== 'https')) {
            throw new RuntimeException('Secure execution media delivery is unavailable.');
        }

        if ($payload->mediaDigest !== null && ! app(BlogTransportCapabilities::class)->supportsImmutableMediaDestination()) {
            throw new RuntimeException('Immutable Blog media destination is not verified.');
        }

        DB::transaction(function () use ($publication, $payload, $attemptId): void {
            // Same lock order as the worker. Never trust the caller's model state.
            $current = Publication::query()->lockForUpdate()->findOrFail($publication->id);
            $attempt = DB::table('publication_attempts')->where('id', $attemptId)->lockForUpdate()->first();
            if ($current->status !== PublicationStatus::Processing || strtolower($current->channel) !== 'blog'
                || $attempt === null || $attempt->publication_id !== $current->id
                || $attempt->content_item_id !== $current->content_item_id
                || $attempt->content_version_id !== $current->content_version_id
                || $attempt->attempt_number !== $current->attempts
                || $attempt->status !== 'started' || $attempt->finished_at !== null
                || $attempt->payload_sha256 === null
                || ! hash_equals($attempt->payload_sha256, $payload->fingerprint())) {
                throw new RuntimeException('Execution attempt is not eligible for this payload.');
            }
            if ($payload->mediaDigest !== null) {
                $version = DB::table('content_versions')->where('id', $current->content_version_id)->lockForUpdate()->firstOrFail();
                $snapshot = json_decode($version->media_snapshot ?? '', true);
                if (! is_array($snapshot) || ! is_string($snapshot['sha256'] ?? null)
                    || ! hash_equals($snapshot['sha256'], $payload->mediaDigest)) {
                    throw new RuntimeException('Execution media does not match the bound snapshot.');
                }
                FrozenBlogMedia::fromSnapshot($snapshot);
            }
            // Commit BEFORE HTTP. A crash or ambiguous response cannot revive it.
            DB::table('publication_attempts')->where('id', $attemptId)->update([
                'status' => 'sending', 'updated_at' => now(),
            ]);
        }, attempts: 3);

        $transportData = $payload->data;
        if ($payload->mediaDigest !== null) {
            $transportData['image_url'] = URL::temporarySignedRoute('blog.execution-media', now()->addMinutes(2), [
                'attempt' => $attemptId, 'digest' => $payload->mediaDigest,
            ]);
        }
        try {
            $httpResponse = $this->client($token)->post(rtrim($url, '/').'/posts', $transportData)->throw();
            // Redirects must not bypass the configured destination or forward
            // authentication and execution-media access to another endpoint.
            // Laravel throw() alone does not reject 3xx responses.
            if (! $httpResponse->successful()) {
                throw new RuntimeException('Blog transport outcome is unknown.');
            }
            $response = $httpResponse->json();
        } catch (Throwable) {
            // Response bodies and connection diagnostics may echo credentials or
            // request data even for text. Never chain them into queue failures.
            throw new RuntimeException($payload->mediaDigest !== null
                ? 'Blog media transport outcome is unknown.'
                : 'Blog transport outcome is unknown.');
        }

        $id = is_array($response) ? ($response['id'] ?? null) : null;
        $externalUrl = is_array($response) ? ($response['url'] ?? null) : null;
        if ((! is_int($id) && ! is_string($id)) || trim((string) $id) === ''
            || ! is_string($externalUrl)
            || filter_var($externalUrl, FILTER_VALIDATE_URL) === false
            || ! in_array(strtolower(parse_url($externalUrl, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true)) {
            // A malformed success body does not prove rejection: the worker records
            // an unknown outcome and must not send this publication again.
            throw new RuntimeException('Die Moonshiner-Blog-API lieferte keine gültige ID oder HTTP(S)-URL zurück.');
        }

        return $response;
    }

    private function client(string $token): PendingRequest
    {
        $request = Http::acceptJson()->withoutRedirecting()->withHeaders(['X-Content-Hub-Token' => $token])->timeout(20);
        $user = config('services.moonshiner_blog.basic_user');
        $password = config('services.moonshiner_blog.basic_password');

        return $user && $password ? $request->withBasicAuth($user, $password) : $request;
    }
}
