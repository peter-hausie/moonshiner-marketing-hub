<?php

namespace App\Services\Connector;

use App\Models\AgentEditorialSetting;
use App\Models\AgentPrincipal;
use App\Models\ConnectorRun;
use App\Models\ContentAgentMessage;
use App\Models\ContentItem;
use App\Models\Opportunity;
use App\Models\ProviderConnection;
use App\Models\User;
use App\Services\Agents\AcceptDraftSubmission;
use App\Services\Agents\AcceptResearchSubmission;
use App\Services\Agents\DraftContext;
use App\Services\Content\CreateContentVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Trusted host coordinator. No request can supply scopes, hashes or proposal authors. */
final class Connector
{
    public function enqueue(ContentAgentMessage $message, User $user): ?ConnectorRun
    {
        return DB::transaction(function () use ($message, $user) {
            $connection = ProviderConnection::find(1);
            if (! $connection?->enabled || ! $connection->writer_agent_id) {
                return null;
            }
            $item = ContentItem::whereKey($message->content_item_id)->lockForUpdate()->firstOrFail();
            abort_unless($user->can('update', $item), 403);
            if ($existing = ConnectorRun::where('message_id', $message->id)->first()) {
                return $existing;
            }
            if (! DB::table('content_versions')->where('content_item_id', $item->id)->exists()) {
                app(CreateContentVersion::class)->create($item->id, $user->id, 'connector_baseline');
            }
            $run = new ConnectorRun;
            $run->forceFill(['message_id' => $message->id, 'user_id' => $user->id, 'kind' => 'draft', 'status' => 'queued',
                'context' => ['draft' => DraftContext::forContentItem($item->id)->data, 'editorial' => $message->context_snapshot],
                'instruction' => $message->message, 'connection_revision' => $connection->revision])->save();
            $message->forceFill(['status' => 'queued'])->save();

            return $run;
        });
    }

    public function research(User $user, string $instruction): ConnectorRun
    {
        abort_unless($user->canManageAccounts(), 403);
        validator(['instruction' => $instruction], ['instruction' => 'required|string|max:10000'])->validate();
        $connection = ProviderConnection::findOrFail(1);
        abort_unless($connection->enabled && $connection->research_agent_id, 422);
        $run = new ConnectorRun;
        $run->forceFill(['user_id' => $user->id, 'kind' => 'research', 'status' => 'queued', 'instruction' => $instruction,
            'context' => ['editorial' => AgentEditorialSetting::find('research')?->toArray() ?? []],
            'connection_revision' => $connection->revision])->save();

        return $run;
    }

    /** Claim before network I/O. Duplicate queue deliveries see running/terminal and do nothing. */
    public function process(int $id): void
    {
        $claimed = DB::transaction(function () use ($id) {
            $run = ConnectorRun::whereKey($id)->lockForUpdate()->first();
            if (! $run || $run->status !== 'queued') {
                return null;
            }
            $run->forceFill(['status' => 'running', 'lease' => (string) Str::uuid(), 'attempt' => $run->attempt + 1,
                'started_at' => now(), 'error_code' => null])->save();
            $this->messageStatus($run, 'running');

            return $run;
        });
        if (! $claimed) {
            return;
        }
        try {
            $connection = $this->authorize($claimed);
            $output = app(ContentProvider::class)->generate($connection, $claimed->kind, $claimed->context, $claimed->instruction);
            DB::transaction(function () use ($claimed, $output) {
                // Configuration changes and revoked users are checked again AFTER the provider call.
                $run = ConnectorRun::whereKey($claimed->id)->lockForUpdate()->firstOrFail();
                if ($run->status !== 'running' || $run->lease !== $claimed->lease) {
                    return;
                }
                $this->authorize($run, true);
                $name = $run->kind === 'research' ? 'journalist' : 'underwood';
                $principal = AgentPrincipal::where('name', $name)->lockForUpdate()->firstOrFail();
                $key = 'connector:'.$run->id;
                if ($run->kind === 'draft') {
                    $d = $run->context['draft'];
                    $receipt = app(AcceptDraftSubmission::class)->accept($principal, ['draft:write'], [
                        'job_id' => $key, 'idempotency_key' => $key,
                        'content_item_id' => $d['content_item_id'], 'expected_version' => $d['expected_version'],
                        'base_sha256' => $d['base_sha256'], 'title' => $output['title'], 'body' => $output['body'],
                    ]);
                    $run->result_version_id = $receipt->content_version_id;
                    // Acceptance appended a proposal. Capture the new version boundary for explicit adoption.
                    $run->adoption_context = $this->lockedContext($d['content_item_id'])->data;
                } else {
                    $ids = [];
                    foreach ($output['sources'] as $index => $source) {
                        $receipt = app(AcceptResearchSubmission::class)->accept($principal, ['research:write'], [
                            'job_id' => $key, 'idempotency_key' => $key.':'.$index,
                            'source_url' => $source['url'], 'title' => $source['title'], 'excerpt' => $source['summary'],
                            'observed_at' => now()->utc()->format('Y-m-d\TH:i:sP'), 'published_at' => null, 'event_at' => null,
                            'coverage' => ['state' => 'partial', 'reason' => 'Agentenrecherche; menschliche Quellenprüfung offen.'],
                        ]);
                        $topic = Opportunity::create(['title' => $source['title'], 'rationale' => $source['summary'],
                            'provenance' => 'agent:journalist', 'created_by' => null]);
                        $topic->researchItems()->attach($receipt->research_item_id);
                        $ids[] = $topic->id;
                    }
                    $run->result_ids = $ids;
                }
                $run->status = 'succeeded';
                $run->save();
                $this->messageStatus($run, 'succeeded');
            }, 3);
        } catch (\Throwable $error) {
            // Never serialize the exception: HTTP request exceptions can contain credentials/body.
            $code = $error instanceof ProviderFailure ? $error->getMessage() : ($error instanceof ConflictHttpException ? 'context_changed' : 'processing_failed');
            $this->fail($claimed, $code);
        }
    }

    private function authorize(ConnectorRun $run, bool $lock = false): ProviderConnection
    {
        $query = ProviderConnection::whereKey(1);
        $connection = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $connection?->enabled || $connection->revision !== $run->connection_revision) {
            throw new ProviderFailure('configuration_changed');
        }
        $userQuery = User::whereKey($run->user_id);
        $user = ($lock ? $userQuery->lockForUpdate() : $userQuery)->first();
        if ($run->kind === 'draft') {
            $itemQuery = ContentItem::whereKey($run->context['draft']['content_item_id']);
            $item = ($lock ? $itemQuery->lockForUpdate() : $itemQuery)->first();
            if (! $user || ! $item || ! $user->can('update', $item)) {
                throw new ProviderFailure('access_revoked');
            }
            $now = ($lock ? $this->lockedContext($item->id) : DraftContext::forContentItem($item->id))->data;
            $base = $run->context['draft'];
            if ($now['expected_version'] !== $base['expected_version'] || ! hash_equals($now['base_sha256'], $base['base_sha256'])) {
                throw new ConflictHttpException;
            }
        } elseif (! $user?->canManageAccounts()) {
            throw new ProviderFailure('access_revoked');
        }
        $principal = AgentPrincipal::where('name', $run->kind === 'research' ? 'journalist' : 'underwood')->first();
        if (! $principal?->enabled) {
            throw new ProviderFailure('access_revoked');
        }

        return $connection;
    }

    private function fail(ConnectorRun $claimed, string $code): void
    {
        DB::transaction(function () use ($claimed, $code) {
            $run = ConnectorRun::whereKey($claimed->id)->lockForUpdate()->first();
            if (! $run || $run->status !== 'running' || $run->lease !== $claimed->lease) {
                return;
            }
            $run->status = match ($code) {
                'context_changed' => 'conflict','configuration_changed','access_revoked' => 'cancelled',default => 'failed'
            };
            $run->error_code = $code;
            $run->save();
            $this->messageStatus($run, $run->status);
        });
    }

    /** Expired leases are uncertain, not silently replayed (the provider may already have billed). */
    public function expire(): void
    {
        ConnectorRun::where('status', 'running')->where('started_at', '<', now()->subMinutes(3))->get()
            ->each(fn ($run) => $this->fail($run, 'worker_interrupted'));
    }

    public function retry(int $id, User $user): void
    {
        DB::transaction(function () use ($id, $user) {
            $run = ConnectorRun::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($run->kind === 'draft') {
                $item = ContentItem::findOrFail($run->context['draft']['content_item_id']);
                abort_unless($user->can('update', $item), 403);
            } else {
                abort_unless($user->canManageAccounts(), 403);
            }
            abort_unless($run->status === 'failed' && $run->attempt < 3, 409);
            // Retain original context; retry must not silently rebase onto a human edit.
            $this->authorize($run);
            $run->forceFill(['status' => 'queued', 'lease' => null, 'error_code' => null])->save();
            $this->messageStatus($run, 'queued');
        });
    }

    public function adopt(int $id, User $user): void
    {
        DB::transaction(function () use ($id, $user) {
            $run = ConnectorRun::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($run->kind === 'draft', 422);
            $item = ContentItem::whereKey($run->context['draft']['content_item_id'])->lockForUpdate()->firstOrFail();
            abort_unless($user->can('update', $item), 403);
            if ($run->status === 'adopted') {
                return;
            }
            abort_unless($run->status === 'succeeded', 409);
            $current = $this->lockedContext($item->id)->data;
            if ($current !== $run->adoption_context) {
                throw new ConflictHttpException('Beitrag geändert. Vorschlag bitte im Editor vergleichen.');
            }
            $version = DB::table('content_versions')->where('id', $run->result_version_id)->where('content_item_id', $item->id)->firstOrFail();
            // An already executing publication cannot safely be recalled from here.
            $publications = DB::table('publications')->where('content_item_id', $item->id)->lockForUpdate()->get();
            abort_if($publications->contains('status', 'processing'), 409, 'Veröffentlichung läuft bereits.');
            DB::table('publications')->where('content_item_id', $item->id)->where('status', 'pending')
                ->update(['status' => 'failed', 'last_error' => 'Neuer Entwurf übernommen; erneute redaktionelle Bindung erforderlich.', 'updated_at' => now()]);
            // Query-builder update deliberately avoids the model's publication observer.
            DB::table('content_items')->where('id', $item->id)->update([
                'title' => $version->title, 'body' => $version->body, 'status' => 'draft', 'reviewed_by' => null,
                'review_notes' => null, 'updated_at' => now(),
            ]);
            app(CreateContentVersion::class)->create($item->id, $user->id, 'agent_proposal_adopted');
            $run->forceFill(['status' => 'adopted', 'adopted_at' => now()])->save();
            $this->messageStatus($run, 'adopted');
        });
    }

    /** Locking reads avoid an older repeatable-read snapshot during adoption checks. */
    private function lockedContext(int $id): DraftContext
    {
        $item = DB::table('content_items')->where('id', $id)->lockForUpdate()->firstOrFail();
        $latest = DB::table('content_versions')->where('content_item_id', $id)
            ->orderByDesc('version_number')->lockForUpdate()->firstOrFail();

        return DraftContext::fromLockedRows($item, $latest);
    }

    private function messageStatus(ConnectorRun $run, string $status): void
    {
        if ($run->message_id) {
            ContentAgentMessage::whereKey($run->message_id)->update(['status' => $status]);
        }
    }
}
