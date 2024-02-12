<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
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
        User::with('teams', 'teamRoles')->get()->each(function($user) {
            if (!$user->teams->count()) {
                \Log::critical('User id '.$user->id.' has no associated teams!');
            }

            if (!$user->current_team_id) {
                \Log::critical('User id '.$user->id.' has no current_team_id!');
            }

            if (!$user->teams->pluck('id')->contains($user->current_team_id)) {
                \Log::critical('User id '.$user->id.' has a current_team_id from another team!');                
            }

            if (!$user->current_role) {
                \Log::critical('User id '.$user->id.' has no current_role!');
            }

            if (!$user->teamRoles->pluck('role')->contains($user->current_role)) {
                \Log::critical('User id '.$user->id.' has a not allowed current_role! '.$user->current_role);                
            }

            if (!$user->collectAvailableRoles()->count()) {
                \Log::critical('User id '.$user->id.' has no available_roles!');
            }

            $user->collectAvailableRoles()->each(function($role) use ($user) {

                if (!$user->teamRoles->pluck('role')->contains($role)) {
                    \Log::critical('User id '.$user->id.' has an available role not allowed! '.$role);                
                }

            });
        });
    }
}
