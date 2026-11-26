<?php

namespace App\Services\Content;

use App\Models\AgentEditorialSetting;
use App\Models\ContentAgentMessage;
use App\Models\ContentItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Persist an auditable human request and its context. This is not a provider dispatch.
 * A future connector must explicitly claim the record and bind a fresh server context. */
final class RecordAgentMessage
{
    public function record(int $id, User $user, string $kind, string $message, string $key): ContentAgentMessage
    {
        $message = trim($message);
        Validator::make(compact('kind', 'message', 'key'), [
            'kind' => 'required|in:draft,feedback', 'message' => 'required|string|max:10000', 'key' => 'required|uuid',
        ])->validate();

        return DB::transaction(function () use ($id, $user, $kind, $message, $key): ContentAgentMessage {
            $item = ContentItem::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($user->can('update', $item), 403);
            if ($existing = ContentAgentMessage::where('request_key', $key)->first()) {
                abort_unless($existing->content_item_id === $id && $existing->user_id === $user->id, 409);

                return $existing;
            }
            $writer = AgentEditorialSetting::find('writer');
            $entry = new ContentAgentMessage;
            $entry->forceFill([
                'content_item_id' => $id, 'user_id' => $user->id, 'kind' => $kind,
                'request_key' => $key, 'message' => $message, 'status' => 'awaiting_connection',
                'context_snapshot' => [
                    'title' => $item->title, 'body' => $item->body, 'briefing' => $item->context,
                    'channels' => $item->channels, 'content_type' => $item->content_type->value,
                    'writer_name' => $writer?->display_name ?: 'Text-Agent', 'tone_of_voice' => $writer?->briefing,
                    'opportunities' => $item->opportunities()->with('researchItems')->get()->toArray(),
                ],
            ])->save();

            // Deliberately no dispatch: connector and explicit delivery are a later step.
            return $entry;
        });
    }
}
