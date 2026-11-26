<?php

namespace App\Http\Middleware;

use App\Models\AgentPrincipal;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateResearchAgent
{
    public function handle(Request $request, Closure $next): Response
    {
        $agent = Auth::guard('agent')->user();
        abort_unless($agent instanceof AgentPrincipal, 401);
        $token = $agent->currentAccessToken();
        abort_unless($agent->enabled && $agent->name === 'journalist'
            && $token instanceof PersonalAccessToken
            && $token->expires_at !== null && $token->expires_at->isFuture()
            && is_array($token->abilities)
            && array_diff($token->abilities, $agent->allowedAbilities()) === []
            && in_array('research:write', $token->abilities, true), 403);

        // Only this server-derived principal may supply submission identity/scopes.
        $request->attributes->set('research_agent', $agent);

        return $next($request);
    }
}
