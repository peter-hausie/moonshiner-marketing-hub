<?php

namespace App\Http\Controllers;

use App\Http\Middleware\DraftApiBoundary;
use App\Services\Agents\AcceptDraftSubmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class DraftSubmissionController
{
    public function __invoke(Request $request, AcceptDraftSubmission $accept): JsonResponse
    {
        return app(DraftApiBoundary::class)->handle($request, fn (Request $request) => $this->accept($request, $accept));
    }

    private function accept(Request $request, AcceptDraftSubmission $accept): JsonResponse
    {
        $agent = $request->attributes->get('draft_agent');
        $input = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR);
        $attempt = $input['attempt_number'] ?? null;
        // Require a JSON integer, not a coerced string, boolean or fractional number.
        if (! is_int($attempt) || $attempt < 1) {
            throw ValidationException::withMessages(['attempt_number' => 'A positive attempt number is required.']);
        }
        unset($input['attempt_number']);
        $receipt = $accept->acceptAuthenticatedAttempt($agent, $input, $attempt);

        // Same representation/status on first acceptance and identical replay.
        return response()->json([
            'receipt_id' => $receipt->id,
            'content_version_id' => $receipt->content_version_id,
            'job_id' => $receipt->job_id,
            'attempt_number' => $attempt,
            'accepted_at' => $receipt->accepted_at,
        ]);
    }
}
