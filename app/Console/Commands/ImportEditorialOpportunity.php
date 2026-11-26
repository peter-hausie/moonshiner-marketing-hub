<?php

namespace App\Console\Commands;

use App\Models\Opportunity;
use App\Models\ResearchItem;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ImportEditorialOpportunity extends Command
{
    protected $signature = 'content-hub:import-opportunity {file : Reviewed JSON research and proposal} {--curator= : Existing curator email} {--source-once : Preserve the first opportunity for this source URL across briefings}';

    protected $description = 'Import a reviewed research finding and proposed topic without publishing or dispatching agents';

    public function handle(): int
    {
        $user = User::where('email', $this->option('curator'))->first();
        if (! $user?->isCurator()) {
            $this->error('An existing curator is required.');

            return self::FAILURE;
        }
        $file = $this->argument('file');
        if (! is_file($file) || filesize($file) > 1048576) {
            $this->error('A JSON file up to 1 MiB is required.');

            return self::FAILURE;
        }
        try {
            $data = json_decode(file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->error('Invalid JSON.');

            return self::FAILURE;
        }
        $validator = Validator::make(is_array($data) ? $data : [], [
            'source_url' => ['required', 'url:http,https', 'max:4096'],
            'source_title' => ['required', 'string', 'max:255'],
            'excerpt' => ['required', 'string', 'max:20000'],
            'observed_at' => ['required', 'date'],
            'provenance' => ['required', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'rationale' => ['required', 'string', 'max:20000'],
        ]);
        if ($validator->fails()) {
            $this->error('Invalid proposal fields: '.implode(', ', $validator->errors()->keys()));

            return self::FAILURE;
        }
        $data = $validator->validated();
        $id = DB::transaction(function () use ($data, $user): int {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            // A source URL is the durable identity for recurring Journalist ingestion.
            // Do not key retries on editable titles or the daily briefing date.
            if ($this->option('source-once')) {
                $existing = Opportunity::whereHas('researchItems', fn ($query) => $query->where('source_url', $data['source_url']))->orderBy('id')->first();
                if ($existing) {
                    return $existing->id;
                }
            }
            $research = ResearchItem::firstOrCreate(
                ['source_url' => $data['source_url'], 'provenance' => $data['provenance']],
                ['title' => $data['source_title'], 'excerpt' => $data['excerpt'], 'observed_at' => $data['observed_at'], 'created_by' => $user->id],
            );
            $existing = $research->opportunities()->where('title', $data['title'])->first();
            if ($existing) {
                return $existing->id;
            }
            $opportunity = Opportunity::create([
                'title' => $data['title'], 'rationale' => $data['rationale'],
                'provenance' => $data['provenance'], 'created_by' => $user->id,
            ]);
            $opportunity->researchItems()->attach($research);

            return $opportunity->id;
        });
        $this->info('Opportunity '.$id.' available at /admin/opportunities/'.$id.'. No publication created.');

        return self::SUCCESS;
    }
}
