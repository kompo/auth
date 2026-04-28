<?php

namespace Kompo\Auth\Tests\Unit;

use Kompo\Auth\Database\Factories\UserFactory;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Tests\Helpers\AuthTestHelpers;
use Kompo\Auth\Tests\TestCase;

class EnsureCurrentTeamRoleIsValidCommandTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        AuthTestHelpers::createPermission('TestResource');
    }

    /** @test */
    public function it_batch_fixes_invalid_current_team_roles_using_the_first_active_team_role()
    {
        $user = UserFactory::new()->create();

        $deletedTeam = AuthTestHelpers::createTeam(['team_name' => 'Deleted Team'], $user);
        $activeTeam = AuthTestHelpers::createTeam(['team_name' => 'Active Team'], $user);

        $deletedRole = AuthTestHelpers::createRole('Deleted Role', [
            'TestResource' => PermissionTypeEnum::READ,
        ]);
        $activeRole = AuthTestHelpers::createRole('Active Role', [
            'TestResource' => PermissionTypeEnum::READ,
        ]);

        $deletedTeamRole = AuthTestHelpers::assignRoleToUser($user, $deletedRole, $deletedTeam);
        $activeTeamRole = AuthTestHelpers::assignRoleToUser($user, $activeRole, $activeTeam);

        $user->forceFill([
            'current_team_role_id' => $deletedTeamRole->id,
        ])->save();

        $deletedTeam->delete();

        $this->artisan('auth:ensure-current-team-role-is-valid')
            ->assertExitCode(0);

        $user->refresh();

        $this->assertEquals($activeTeamRole->id, $user->current_team_role_id);
    }
}
