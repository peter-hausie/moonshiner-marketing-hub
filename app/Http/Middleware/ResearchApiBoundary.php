<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class ResearchApiBoundary
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('agents.research_api_enabled') !== true) {
            return response()->json(['error' => 'not_found'], 404);
        }
        $requestId = (string) Str::uuid();
        try {
            $response = app(AuthenticateResearchAgent::class)->handle($request, function (Request $request) use ($next): Response {
                $key = 'research-api:'.$request->attributes->get('research_agent')->getKey();
                if (RateLimiter::tooManyAttempts($key, 30)) {
                    abort(429);
                }
                RateLimiter::hit($key, 60);
                abort_unless($request->isJson(), 415);
                abort_if(strlen($request->getContent()) > 65536, 413);
                try {
                    $input = json_decode($request->getContent(), false, 32, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    abort(400);
                }
                abort_unless(is_object($input), 400);

                return $next($request);
            });
        } catch (Throwable $error) {
            $status = match (true) {
                $error instanceof ValidationException => 422,
                $error instanceof AuthorizationException => 403,
                $error instanceof HttpExceptionInterface => $error->getStatusCode(),
                default => 503,
            };
            $code = match ($status) {
                400 => 'invalid_json', 401 => 'unauthenticated', 403 => 'forbidden',
                409 => 'idempotency_conflict', 413 => 'payload_too_large',
                415 => 'json_required', 422 => 'invalid_submission', 429 => 'rate_limited',
                default => 'temporarily_unavailable',
            };
            // Deliberately no exception message/trace, URL, input, token or client ID.
            Log::warning('agent_research_rejected', [
                'request_id' => $requestId, 'status' => $status, 'code' => $code,
                'principal_id' => $request->attributes->get('research_agent')?->getKey(),
            ]);
            $response = response()->json(['error' => $code, 'request_id' => $requestId], $status);
            if ($status === 429 || $status === 503) {
                $response->headers->set('Retry-After', '60');
            }
        }
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }
}
