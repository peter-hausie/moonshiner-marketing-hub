<?php

namespace App\Services\Content;

use App\Models\ContentItem;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class AdoptOpportunity
{
    public function adopt(int $id, User $user): ContentItem
    {
        abort_unless($user->isCurator(), 403);

        return DB::transaction(function () use ($id, $user): ContentItem {
            $topic = Opportunity::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($existing = $topic->contentItems()->first()) {
                return $existing;
            }
            $context = "Redaktionell übernommener Themenvorschlag. Noch nicht ausformuliert.\n\n".$topic->rationale;
            foreach ($topic->researchItems as $source) {
                $context .= "\n\nQuelle: ".$source->title."\n".$source->source_url."\n".$source->excerpt;
            }
            $item = ContentItem::create([
                'title' => $topic->title,
                'body' => 'Noch kein fertiger Text. Nutze „Text-Agent briefen“, um die Ausformulierung vorzubereiten.',
                'context' => $context, 'status' => 'draft', 'content_type' => 'posting',
                'channels' => [], 'responsible_user_id' => $user->id, 'submitted_by' => $user->id,
            ]);
            $topic->contentItems()->attach($item);

            return $item;
        });
    }
}
