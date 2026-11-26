<?php

namespace App\Console\Commands;

use App\Models\Opportunity;
use App\Models\User;
use App\Services\Content\CreateOpportunityDraft;
use Illuminate\Console\Command;

class ImportOpportunityDraft extends Command
{
    protected $signature = 'content-hub:import-draft {opportunity} {file} {--curator=}';

    protected $description = 'Attach an assistant-assisted draft to researched opportunity; never publish';

    public function handle(CreateOpportunityDraft $service): int
    {
        $user = User::where('email', $this->option('curator'))->first();
        $opportunity = Opportunity::find($this->argument('opportunity'));
        $file = $this->argument('file');
        if (! $user?->isCurator() || ! $opportunity || ! is_file($file) || filesize($file) > 1048576 || trim(file_get_contents($file)) === '') {
            $this->error('Existing curator, opportunity and nonempty draft file up to 1 MiB required.');

            return self::FAILURE;
        }
        $item = $service->create($opportunity, $user, file_get_contents($file));
        $this->info('Draft '.$item->id.' available at /admin/content-items/'.$item->id.'/edit. No publication created.');

        return self::SUCCESS;
    }
}
