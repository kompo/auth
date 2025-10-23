<?php

namespace Kompo\Auth\Tests\Unit;

use Kompo\Auth\Models\Teams\RoleHierarchyEnum;
use Kompo\Auth\Tests\TestCase;

/**
 * RoleHierarchyEnum Test
 * 
 * Tests the RoleHierarchyEnum logic for team hierarchy access.
 * 
 * Scenarios covered:
 * - accessGrant() / accessGrantBelow() / accessGrantNeighbours()
 * - DIRECT behavior
 * - DIRECT_AND_BELOW behavior
 * - DIRECT_AND_NEIGHBOURS behavior
 * - DIRECT_AND_BELOW_AND_NEIGHBOURS behavior
 * - DISABLED_BELOW behavior
 * - getFinal() merging logic
 * - label() method
 * - optionsWithLabels()
 */
class RoleHierarchyEnumTest extends TestCase
{
    /**
     * INVARIANT: DIRECT grants only direct access
     * 
     * @test
     */
    public function test_direct_hierarchy()
    {
        // Act & Assert
        $this->assertTrue(RoleHierarchyEnum::DIRECT->accessGrant(), 'DIRECT should grant direct access');
        $this->assertFalse(RoleHierarchyEnum::DIRECT->accessGrantBelow(), 'DIRECT should NOT grant below access');
        $this->assertFalse(RoleHierarchyEnum::DIRECT->accessGrantNeighbours(), 'DIRECT should NOT grant neighbours access');
    }

    /**
     * INVARIANT: DIRECT_AND_BELOW grants direct + descendants
     * 
     * @test
     */
    public function test_direct_and_below_hierarchy()
    {
        // Act & Assert
        $this->assertTrue(RoleHierarchyEnum::DIRECT_AND_BELOW->accessGrant(), 'Should grant direct access');
        $this->assertTrue(RoleHierarchyEnum::DIRECT_AND_BELOW->accessGrantBelow(), 'Should grant below access');
        $this->assertFalse(RoleHierarchyEnum::DIRECT_AND_BELOW->accessGrantNeighbours(), 'Should NOT grant neighbours');
    }

    /**
     * INVARIANT: DIRECT_AND_NEIGHBOURS grants direct + siblings
     * 
     * @test
     */
    public function test_direct_and_neighbours_hierarchy()
    {
        // Act & Assert
        $this->assertTrue(RoleHierarchyEnum::DIRECT_AND_NEIGHBOURS->accessGrant(), 'Should grant direct');
        $this->assertFalse(RoleHierarchyEnum::DIRECT_AND_NEIGHBOURS->accessGrantBelow(), 'Should NOT grant below');
        $this->assertTrue(RoleHierarchyEnum::DIRECT_AND_NEIGHBOURS->accessGrantNeighbours(), 'Should grant neighbours');
    }

    /**
     * INVARIANT: DIRECT_AND_BELOW_AND_NEIGHBOURS grants all
     * 
     * @test
     */
    public function test_direct_and_below_and_neighbours_hierarchy()
    {
        // Act & Assert
        $this->assertTrue(RoleHierarchyEnum::DIRECT_AND_BELOW_AND_NEIGHBOURS->accessGrant());
        $this->assertTrue(RoleHierarchyEnum::DIRECT_AND_BELOW_AND_NEIGHBOURS->accessGrantBelow());
        $this->assertTrue(RoleHierarchyEnum::DIRECT_AND_BELOW_AND_NEIGHBOURS->accessGrantNeighbours());
    }

    /**
     * INVARIANT: DISABLED_BELOW disables all access
     * 
     * @test
     */
    public function test_disabled_below_hierarchy()
    {
        // Act & Assert
        $this->assertFalse(RoleHierarchyEnum::DISABLED_BELOW->accessGrant(), 'DISABLED should not grant direct');
        $this->assertFalse(RoleHierarchyEnum::DISABLED_BELOW->accessGrantBelow(), 'DISABLED should not grant below');
        $this->assertFalse(RoleHierarchyEnum::DISABLED_BELOW->accessGrantNeighbours(), 'DISABLED should not grant neighbours');
    }

    /**
     * INVARIANT: getFinal() merges hierarchies correctly
     * 
     * @test
     */
    public function test_get_final_merges_hierarchies()
    {
        // Test: BELOW + NEIGHBOURS = BOTH
        $final1 = RoleHierarchyEnum::getFinal([
            RoleHierarchyEnum::DIRECT_AND_BELOW,
            RoleHierarchyEnum::DIRECT_AND_NEIGHBOURS,
        ]);

        $this->assertEquals(RoleHierarchyEnum::DIRECT_AND_BELOW_AND_NEIGHBOURS, $final1);

        // Test: Only BELOW
        $final2 = RoleHierarchyEnum::getFinal([
            RoleHierarchyEnum::DIRECT_AND_BELOW,
            RoleHierarchyEnum::DIRECT,
        ]);

        $this->assertEquals(RoleHierarchyEnum::DIRECT_AND_BELOW, $final2);

        // Test: Only NEIGHBOURS
        $final3 = RoleHierarchyEnum::getFinal([
            RoleHierarchyEnum::DIRECT_AND_NEIGHBOURS,
            RoleHierarchyEnum::DIRECT,
        ]);

        $this->assertEquals(RoleHierarchyEnum::DIRECT_AND_NEIGHBOURS, $final3);

        // Test: Only DIRECT
        $final4 = RoleHierarchyEnum::getFinal([
            RoleHierarchyEnum::DIRECT,
        ]);

        $this->assertEquals(RoleHierarchyEnum::DIRECT, $final4);
    }

    /**
     * Enum methods: label()
     * 
     * @test
     */
    public function test_label_method()
    {
        // Act & Assert: Each enum has a label
        $this->assertNotEmpty(RoleHierarchyEnum::DIRECT->label());
        $this->assertNotEmpty(RoleHierarchyEnum::DIRECT_AND_BELOW->label());
        $this->assertNotEmpty(RoleHierarchyEnum::DIRECT_AND_NEIGHBOURS->label());
        $this->assertNotEmpty(RoleHierarchyEnum::DIRECT_AND_BELOW_AND_NEIGHBOURS->label());
        $this->assertNotEmpty(RoleHierarchyEnum::DISABLED_BELOW->label());

        // Specific labels
        $this->assertEquals('permissions-roll-direct', RoleHierarchyEnum::DIRECT->label());
        $this->assertEquals('permissions-roll-direct-and-down', RoleHierarchyEnum::DIRECT_AND_BELOW->label());
    }

    /**
     * Static method: optionsWithLabels()
     * 
     * @test
     */
    public function test_options_with_labels()
    {
        // Act: Get options
        $options = RoleHierarchyEnum::optionsWithLabels();

        // Assert: Should be an array/collection
        $this->assertIsArray($options);
        $this->assertGreaterThan(0, count($options));

        // Should include all enum values
        $this->assertArrayHasKey(RoleHierarchyEnum::DIRECT->value, $options);
        $this->assertArrayHasKey(RoleHierarchyEnum::DIRECT_AND_BELOW->value, $options);
    }

    /**
     * Edge case: getFinal with empty array
     * 
     * @test
     */
    public function test_get_final_with_empty_array()
    {
        // Act: Get final with empty
        $final = RoleHierarchyEnum::getFinal([]);

        // Assert: Should return null
        $this->assertNull($final);
    }

    /**
     * Edge case: Values as strings
     * 
     * @test
     */
    public function test_enum_values_are_strings()
    {
        // Assert: Values should be strings (A, B, C, etc.)
        $this->assertEquals('B', RoleHierarchyEnum::DIRECT->value);
        $this->assertEquals('A', RoleHierarchyEnum::DIRECT_AND_BELOW->value);
        $this->assertEquals('C', RoleHierarchyEnum::DIRECT_AND_NEIGHBOURS->value);
        $this->assertEquals('E', RoleHierarchyEnum::DIRECT_AND_BELOW_AND_NEIGHBOURS->value);
        $this->assertEquals('D', RoleHierarchyEnum::DISABLED_BELOW->value);
    }
}

