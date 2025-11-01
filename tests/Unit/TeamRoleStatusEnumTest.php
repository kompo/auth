<?php

namespace Kompo\Auth\Tests\Unit;

use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Models\Teams\RoleHierarchyEnum;
use Kompo\Auth\Models\Teams\TeamRoleStatusEnum;
use Kompo\Auth\Tests\Helpers\AuthTestHelpers;
use Kompo\Auth\Tests\TestCase;

/**
 * TeamRoleStatusEnum Test
 * 
 * Tests TeamRoleStatusEnum logic.
 * 
 * Scenarios covered:
 * - getFromTeamRole() with different states
 * - IN_PROGRESS status
 * - FINISHED status (terminated)
 * - SUSPENDED status
 * - canBeFinished() logic
 * - label() / color() methods
 */
class TeamRoleStatusEnumTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        AuthTestHelpers::createPermission('TestResource');
    }

    /**
     * INVARIANT: Active team role has IN_PROGRESS status
     * 
     * @test
     */
    public function test_active_team_role_is_in_progress()
    {
        // Arrange: Active team role
        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $teamRole = $data['teamRole'];

        // Act: Get status
        $status = TeamRoleStatusEnum::getFromTeamRole($teamRole);

        // Assert: Should be IN_PROGRESS
        $this->assertEquals(TeamRoleStatusEnum::IN_PROGRESS, $status);
    }

    /**
     * INVARIANT: Suspended team role has SUSPENDED status
     * 
     * @test
     */
    public function test_suspended_team_role_is_suspended()
    {
        // Arrange: Suspended team role
        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $teamRole = $data['teamRole'];
        $teamRole->suspend();

        // Act: Get status
        $status = TeamRoleStatusEnum::getFromTeamRole($teamRole);

        // Assert: Should be SUSPENDED
        $this->assertEquals(TeamRoleStatusEnum::SUSPENDED, $status);
    }

    /**
     * INVARIANT: Terminated team role has FINISHED status
     * 
     * @test
     */
    public function test_terminated_team_role_is_finished()
    {
        // Arrange: Terminated team role
        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $teamRole = $data['teamRole'];
        $teamRole->terminate();

        // Act: Get status
        $status = TeamRoleStatusEnum::getFromTeamRole($teamRole);

        // Assert: Should be FINISHED
        $this->assertEquals(TeamRoleStatusEnum::FINISHED, $status);
    }

    /**
     * INVARIANT: Soft deleted team role has FINISHED status
     * 
     * @test
     */
    public function test_soft_deleted_team_role_is_finished()
    {
        // Arrange: Soft deleted team role
        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $teamRole = $data['teamRole'];
        $teamRole->deleted_at = now();
        $teamRole->save();

        // Act: Get status
        $status = TeamRoleStatusEnum::getFromTeamRole($teamRole);

        // Assert: Should be FINISHED
        $this->assertEquals(TeamRoleStatusEnum::FINISHED, $status);
    }

    /**
     * INVARIANT: canBeFinished() only true for IN_PROGRESS
     * 
     * @test
     */
    public function test_can_be_finished_logic()
    {
        // IN_PROGRESS can be finished
        $this->assertTrue(
            TeamRoleStatusEnum::IN_PROGRESS->canBeFinished(),
            'IN_PROGRESS should be finishable'
        );

        // FINISHED cannot be finished (already done)
        $this->assertFalse(
            TeamRoleStatusEnum::FINISHED->canBeFinished(),
            'FINISHED should not be finishable'
        );

        // SUSPENDED cannot be finished
        $this->assertFalse(
            TeamRoleStatusEnum::SUSPENDED->canBeFinished(),
            'SUSPENDED should not be finishable'
        );
    }

    /**
     * Enum methods: label() and color()
     * 
     * @test
     */
    public function test_enum_label_and_color()
    {
        // Act & Assert: Each status has label and color
        $this->assertNotEmpty(TeamRoleStatusEnum::IN_PROGRESS->label());
        $this->assertNotEmpty(TeamRoleStatusEnum::FINISHED->label());
        $this->assertNotEmpty(TeamRoleStatusEnum::SUSPENDED->label());

        $this->assertNotEmpty(TeamRoleStatusEnum::IN_PROGRESS->color());
        $this->assertNotEmpty(TeamRoleStatusEnum::FINISHED->color());
        $this->assertNotEmpty(TeamRoleStatusEnum::SUSPENDED->color());

        // Specific colors
        $this->assertStringContainsString('green', TeamRoleStatusEnum::IN_PROGRESS->color());
        $this->assertStringContainsString('danger', TeamRoleStatusEnum::FINISHED->color());
    }

    /**
     * INVARIANT: Status precedence (suspended > terminated > deleted)
     * 
     * @test
     */
    public function test_status_precedence()
    {
        // Arrange: Team role with multiple timestamps
        $data = AuthTestHelpers::createUserWithRole(
            ['TestResource' => PermissionTypeEnum::READ],
            null,
            RoleHierarchyEnum::DIRECT
        );

        $teamRole = $data['teamRole'];

        // Set both suspended and terminated
        $teamRole->suspended_at = now();
        $teamRole->terminated_at = now();
        $teamRole->save();

        // Act: Get status
        $status = TeamRoleStatusEnum::getFromTeamRole($teamRole);

        // Assert: Suspended takes precedence (checked first)
        $this->assertEquals(
            TeamRoleStatusEnum::SUSPENDED,
            $status,
            'SUSPENDED should take precedence over TERMINATED'
        );
    }
}


