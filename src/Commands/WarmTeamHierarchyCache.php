<?php

namespace Kompo\Auth\Commands;

use Illuminate\Console\Command;
use Kompo\Auth\Facades\TeamModel;
use Kompo\Auth\Teams\TeamHierarchyService;

class WarmTeamHierarchyCache extends Command
{
    protected $signature = 'teams:warm-hierarchy-cache {--team-id=* : Specific team IDs to warm}';
    protected $description = 'Pre-calculates and caches team hierarchies for better performance';

    public function handle(TeamHierarchyService $service)
    {
        $teamIds = $this->option('team-id');

        if (empty($teamIds)) {
            $teamIds = TeamModel::pluck('id');
            $this->info('Warming cache for all teams...');
        } else {
            $this->info('Warming cache for specific teams: ' . implode(', ', $teamIds));
        }

        $progressBar = $this->output->createProgressBar(count($teamIds));
        foreach ($teamIds as $teamId) {
            // Pre-calculate the most common queries
            $service->getDescendantTeamIds($teamId);
            $service->getAncestorTeamIds($teamId);
            $service->getSiblingTeamIds($teamId);

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();
        $this->info('Team hierarchy cache warmed successfully!');
    }
}
