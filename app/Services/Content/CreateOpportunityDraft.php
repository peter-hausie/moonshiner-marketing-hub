<?php

namespace App\Services\Content;

use App\Models\ContentItem;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CreateOpportunityDraft
{
    public function create(Opportunity $opportunity, User $curator, string $body): ContentItem
    {
        abort_unless($curator->isCurator(), 403);

        return DB::transaction(function () use ($opportunity, $curator, $body): ContentItem {
            $opportunity = Opportunity::whereKey($opportunity->id)->lockForUpdate()->firstOrFail();
            // Retrying a handoff must never overwrite subsequent editorial edits.
            if ($existing = $opportunity->contentItems()->first()) {
                return $existing;
            }
            $sources = $opportunity->researchItems()->get();
            abort_if($sources->isEmpty() || trim($body) === '', 422);
            $context = "Assistenzgestützter Entwurf; nicht vom Underwood-Agenten erstellt. Redaktionelle Prüfung ausständig.\n\nThemenvorschlag: ".$opportunity->title."\n".$opportunity->rationale."\n\nOriginalquellen:\n";
            foreach ($sources as $source) {
                $context .= $source->title."\n".$source->source_url."\n";
            }
            $item = ContentItem::create([
                'title' => $opportunity->title, 'body' => $body, 'context' => $context,
                'status' => 'draft', 'content_type' => 'posting', 'channels' => [],
                'responsible_user_id' => $curator->id, 'submitted_by' => $curator->id,
            ]);
            $opportunity->contentItems()->attach($item);

            return $item;
        });
    }
}
