<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Kompo\Auth\Models\User as ModelsUser;
use Kompo\Tests\Models\User;

class CheckRolesIntegrityCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kompo:check-roles-integrity';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $this->checkCurrentTeamAndRoleAreOk();

        return Command::SUCCESS;
    }

    protected function checkCurrentTeamAndRoleAreOk()
    {
        ModelsUser::with('teams', 'teamRoles')->get()->each(function ($user) {
            if (!$user->teams->count()) {
                \Log::critical('User id ' . $user->id . ' has no associated teams!');
            }

            if (!$user->current_team_role_id) {
                \Log::critical('User id ' . $user->id . ' has no current_team_role_id!');
            }

            if (!$user->teamRoles->pluck('id')->contains($user->current_team_role_id)) {
                \Log::critical('User id ' . $user->id . ' has a current_team_role_id from another team!');
            }
        });
    }
}
