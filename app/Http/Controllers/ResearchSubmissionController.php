<?php

namespace App\Http\Controllers;

use App\Http\Middleware\ResearchApiBoundary;
use App\Services\Agents\AcceptResearchSubmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ResearchSubmissionController
{
    public function __invoke(Request $request, AcceptResearchSubmission $accept): JsonResponse
    {
        return app(ResearchApiBoundary::class)->handle($request, fn (Request $request) => $this->accept($request, $accept));
    }

    private function accept(Request $request, AcceptResearchSubmission $accept): JsonResponse
    {
        $agent = $request->attributes->get('research_agent');
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
            'research_item_id' => $receipt->research_item_id,
            'job_id' => $receipt->job_id,
            'attempt_number' => $attempt,
            'accepted_at' => $receipt->accepted_at,
        ]);
    }
}
