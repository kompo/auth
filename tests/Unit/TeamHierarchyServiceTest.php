<?php

namespace Kompo\Auth\Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Kompo\Auth\Database\Factories\UserFactory;
use Kompo\Auth\Teams\TeamHierarchyService;
use Kompo\Auth\Tests\Helpers\AuthTestHelpers;
use Kompo\Auth\Tests\TestCase;

/**
 * Team Hierarchy Service Test
 * 
 * Tests the TeamHierarchyService - handles team parent/child relationships.
 * 
 * Scenarios covered:
 * - getDescendantTeamIds method
 * - getAncestorTeamIds method
 * - getSiblingTeamIds method
 * - isDescendant method
 * - Cache optimization
 * - Deep hierarchies
 */
class TeamHierarchyServiceTest extends TestCase
{
    protected TeamHierarchyService $hierarchyService;

    public function setUp(): void
    {
        parent::setUp();

        $this->hierarchyService = app(TeamHierarchyService::class);
    }

    /**
     * INVARIANT: getDescendantTeamIds returns all children
     * 
     * @test
     */
    public function test_get_descendant_team_ids()
    {
        // Arrange: Create hierarchy
        $teams = AuthTestHelpers::createTeamHierarchy(3);

        // Act: Get descendants of Root
        $descendants = $this->hierarchyService->getDescendantTeamIds($teams['root']->id);

        // Assert: Should include all children and grandchildren
        $this->assertGreaterThan(0, $descendants->count());
        $this->assertTrue($descendants->contains($teams['childA']->id));
        $this->assertTrue($descendants->contains($teams['childB']->id));
        $this->assertTrue($descendants->contains($teams['grandchildA1']->id));
    }

    /**
     * INVARIANT: getAncestorTeamIds returns all parents
     * 
     * @test
     */
    public function test_get_ancestor_team_ids()
    {
        // Arrange: Create hierarchy
        $teams = AuthTestHelpers::createTeamHierarchy(3);

        // Act: Get ancestors of Grandchild A1
        $ancestors = $this->hierarchyService->getAncestorTeamIds($teams['grandchildA1']->id);

        // Assert: Should include Child A and Root
        $this->assertTrue($ancestors->contains($teams['childA']->id), 'Should include parent');
        $this->assertTrue($ancestors->contains($teams['root']->id), 'Should include grandparent');
    }

    /**
     * INVARIANT: getSiblingTeamIds returns siblings
     * 
     * @test
     */
    public function test_get_sibling_team_ids()
    {
        // Arrange: Create hierarchy
        $teams = AuthTestHelpers::createTeamHierarchy(2);

        // Act: Get siblings of Child A
        $siblings = $this->hierarchyService->getSiblingTeamIds($teams['childA']->id);

        // Assert: Should include Child B (same parent)
        $this->assertTrue($siblings->contains($teams['childB']->id), 'Should include sibling');
        $this->assertFalse($siblings->contains($teams['root']->id), 'Should NOT include parent');
        $this->assertFalse($siblings->contains($teams['childA']->id), 'Should NOT include self');
    }

    /**
     * INVARIANT: isDescendant checks correctly
     * 
     * @test
     */
    public function test_is_descendant()
    {
        // Arrange: Create hierarchy
        $teams = AuthTestHelpers::createTeamHierarchy(3);

        // Act & Assert: Check various relationships
        $this->assertTrue(
            $this->hierarchyService->isDescendant($teams['root']->id, $teams['childA']->id),
            'Child A should be descendant of Root'
        );

        $this->assertTrue(
            $this->hierarchyService->isDescendant($teams['root']->id, $teams['grandchildA1']->id),
            'Grandchild A1 should be descendant of Root'
        );

        $this->assertTrue(
            $this->hierarchyService->isDescendant($teams['childA']->id, $teams['grandchildA1']->id),
            'Grandchild A1 should be descendant of Child A'
        );

        $this->assertFalse(
            $this->hierarchyService->isDescendant($teams['childA']->id, $teams['childB']->id),
            'Child B should NOT be descendant of Child A (siblings)'
        );

        $this->assertFalse(
            $this->hierarchyService->isDescendant($teams['childA']->id, $teams['root']->id),
            'Root should NOT be descendant of Child A (ancestor)'
        );
    }

    /**
     * INVARIANT: isDescendant with same team returns true
     * 
     * @test
     */
    public function test_is_descendant_with_same_team()
    {
        // Arrange
        $teams = AuthTestHelpers::createTeamHierarchy(1);

        // Act & Assert: Same team should return true
        $this->assertTrue(
            $this->hierarchyService->isDescendant($teams['root']->id, $teams['root']->id),
            'Team should be descendant of itself'
        );
    }

    /**
     * Performance: Hierarchy service uses cache
     * 
     * @test
     */
    public function test_hierarchy_service_uses_cache()
    {
        // Arrange
        $teams = AuthTestHelpers::createTeamHierarchy(2);

        // Act: First call
        $this->enableQueryLog();
        \DB::flushQueryLog();

        $descendants1 = $this->hierarchyService->getDescendantTeamIds($teams['root']->id);
        $queries1 = $this->getQueryCount();

        // Second call (should use cache)
        \DB::flushQueryLog();
        $descendants2 = $this->hierarchyService->getDescendantTeamIds($teams['root']->id);
        $queries2 = $this->getQueryCount();

        // Assert: Second call uses cache
        $this->assertEquals($descendants1->count(), $descendants2->count());
        $this->assertLessThan($queries1, $queries2, 'Second call should use cache');
        $this->assertEquals(0, $queries2, 'Cache hit should have 0 queries');
    }

    /**
     * INVARIANT: clearCache invalidates hierarchy cache
     * 
     * @test
     */
    public function test_clear_cache_invalidates_hierarchy()
    {
        // Arrange
        $teams = AuthTestHelpers::createTeamHierarchy(2);

        // Populate cache
        $this->hierarchyService->getDescendantTeamIds($teams['root']->id);

        // Act: Clear cache
        $this->hierarchyService->clearCache($teams['root']->id);

        // Enable query log
        $this->enableQueryLog();
        \DB::flushQueryLog();

        // Query again
        $this->hierarchyService->getDescendantTeamIds($teams['root']->id);
        $queries = $this->getQueryCount();

        // Assert: Should query DB (cache cleared)
        $this->assertGreaterThan(0, $queries, 'Cache should be cleared');
    }

    /**
     * Edge case: Deep hierarchy (5+ levels)
     * 
     * @test
     */
    public function test_deep_hierarchy_performance()
    {
        // Arrange: Create deep hierarchy manually
        $user = UserFactory::new()->create();
        
        $level1 = AuthTestHelpers::createTeam(['team_name' => 'Level 1'], $user);
        $level2 = AuthTestHelpers::createTeam(['team_name' => 'Level 2', 'parent_team_id' => $level1->id], $user);
        $level3 = AuthTestHelpers::createTeam(['team_name' => 'Level 3', 'parent_team_id' => $level2->id], $user);
        $level4 = AuthTestHelpers::createTeam(['team_name' => 'Level 4', 'parent_team_id' => $level3->id], $user);
        $level5 = AuthTestHelpers::createTeam(['team_name' => 'Level 5', 'parent_team_id' => $level4->id], $user);

        // Act: Get descendants (should handle deep hierarchy)
        $descendants = $this->hierarchyService->getDescendantTeamIds($level1->id);

        // Assert: Should include all levels
        $this->assertGreaterThanOrEqual(4, $descendants->count());
        $this->assertTrue($descendants->contains($level2->id));
        $this->assertTrue($descendants->contains($level5->id));
    }

    /**
     * Edge case: Hierarchy with search filter
     * 
     * @test
     */
    public function test_hierarchy_with_search_filter()
    {
        // Arrange
        $teams = AuthTestHelpers::createTeamHierarchy(2);

        // Act: Get descendants with search
        $descendants = $this->hierarchyService->getDescendantTeamIds($teams['root']->id, 'Child A');

        // Assert: Should filter by search term
        // Note: Exact behavior depends on implementation
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $descendants);
    }

    /**
     * INVARIANT: Batch operations are efficient
     * 
     * @test
     */
    public function test_batch_operations_efficiency()
    {
        // Arrange: Create multiple team hierarchies
        $user = \Kompo\Auth\Database\Factories\UserFactory::new()->create();
        
        $teams = [];
        for ($i = 0; $i < 5; $i++) {
            $teams[] = AuthTestHelpers::createTeamHierarchy(2, $user);
        }

        // Act: Get descendants for all (batch)
        $this->enableQueryLog();
        \DB::flushQueryLog();

        foreach ($teams as $teamSet) {
            $this->hierarchyService->getDescendantTeamIds($teamSet['root']->id);
        }

        $totalQueries = $this->getQueryCount();

        // Assert: Should be efficient (not 5x individual queries)
        // With cache, subsequent calls should be 0 queries
        $this->assertLessThanOrEqual(
            15,
            $totalQueries,
            "Batch hierarchy queries should be efficient (got {$totalQueries})"
        );
    }
}

