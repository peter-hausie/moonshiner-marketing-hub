<?php

namespace App\Http\Controllers;

use App\Http\Middleware\DraftApiBoundary;
use App\Services\Agents\ReadDraftJobContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DraftContextController
{
    public function __invoke(Request $request, ReadDraftJobContext $read): JsonResponse
    {
        return app(DraftApiBoundary::class)->handle($request, fn (Request $request) => response()->json(
            $read->read($request->attributes->get('draft_agent'), json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR))
        ));
    }
}
