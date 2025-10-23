<?php

namespace Kompo\Auth\Tests\Unit;

use Kompo\Auth\Models\Teams\PermissionTypeEnum;
use Kompo\Auth\Tests\TestCase;

/**
 * PermissionTypeEnum Test
 * 
 * Tests the PermissionTypeEnum bitmask logic and methods.
 * 
 * Scenarios covered:
 * - hasPermission() bitmask logic
 * - READ includes READ
 * - WRITE includes READ
 * - ALL includes READ and WRITE
 * - DENY is special (100)
 * - label() / code() / color() methods
 * - values() / colors()
 * - visibleInSelects()
 */
class PermissionTypeEnumTest extends TestCase
{
    /**
     * INVARIANT: hasPermission() with READ checks correctly
     * 
     * @test
     */
    public function test_read_permission_bitmask()
    {
        // Act & Assert: READ has READ
        $this->assertTrue(
            PermissionTypeEnum::hasPermission(PermissionTypeEnum::READ, PermissionTypeEnum::READ),
            'READ should have READ permission'
        );

        // READ does not have WRITE
        $this->assertFalse(
            PermissionTypeEnum::hasPermission(PermissionTypeEnum::READ, PermissionTypeEnum::WRITE),
            'READ should not have WRITE permission'
        );

        // READ does not have ALL
        $this->assertFalse(
            PermissionTypeEnum::hasPermission(PermissionTypeEnum::READ, PermissionTypeEnum::ALL),
            'READ should not have ALL permission'
        );
    }

    /**
     * INVARIANT: WRITE includes READ (bitmask 3 includes 1)
     * 
     * @test
     */
    public function test_write_includes_read()
    {
        // Act & Assert: WRITE has READ
        $this->assertTrue(
            PermissionTypeEnum::hasPermission(PermissionTypeEnum::WRITE, PermissionTypeEnum::READ),
            'WRITE (3) should include READ (1) via bitmask'
        );

        // WRITE has WRITE
        $this->assertTrue(
            PermissionTypeEnum::hasPermission(PermissionTypeEnum::WRITE, PermissionTypeEnum::WRITE),
            'WRITE should have WRITE permission'
        );

        // WRITE does not have ALL
        $this->assertFalse(
            PermissionTypeEnum::hasPermission(PermissionTypeEnum::WRITE, PermissionTypeEnum::ALL),
            'WRITE (3) should not have ALL (7)'
        );
    }

    /**
     * INVARIANT: ALL includes READ and WRITE (bitmask 7 includes 1 and 3)
     * 
     * @test
     */
    public function test_all_includes_read_and_write()
    {
        // Act & Assert: ALL has READ
        $this->assertTrue(
            PermissionTypeEnum::hasPermission(PermissionTypeEnum::ALL, PermissionTypeEnum::READ),
            'ALL (7) should include READ (1) via bitmask'
        );

        // ALL has WRITE
        $this->assertTrue(
            PermissionTypeEnum::hasPermission(PermissionTypeEnum::ALL, PermissionTypeEnum::WRITE),
            'ALL (7) should include WRITE (3) via bitmask'
        );

        // ALL has ALL
        $this->assertTrue(
            PermissionTypeEnum::hasPermission(PermissionTypeEnum::ALL, PermissionTypeEnum::ALL),
            'ALL should have ALL permission'
        );
    }

    /**
     * INVARIANT: DENY is special (value 100, not bitmask)
     * 
     * @test
     */
    public function test_deny_is_special()
    {
        // Act & Assert: DENY value is 100
        $this->assertEquals(100, PermissionTypeEnum::DENY->value, 'DENY should have value 100');

        // DENY should NOT match other permissions via bitmask
        $this->assertFalse(
            PermissionTypeEnum::hasPermission(PermissionTypeEnum::DENY, PermissionTypeEnum::READ),
            'DENY should not have READ via bitmask (special value)'
        );

        $this->assertFalse(
            PermissionTypeEnum::hasPermission(PermissionTypeEnum::DENY, PermissionTypeEnum::WRITE),
            'DENY should not have WRITE via bitmask'
        );

        // DENY only matches DENY
        $this->assertTrue(
            PermissionTypeEnum::hasPermission(PermissionTypeEnum::DENY, PermissionTypeEnum::DENY),
            'DENY should match DENY'
        );
    }

    /**
     * Enum methods: label(), code(), color()
     * 
     * @test
     */
    public function test_enum_methods()
    {
        // READ
        $this->assertEquals('permissions-permission-read', PermissionTypeEnum::READ->label());
        $this->assertEquals('read', PermissionTypeEnum::READ->code());
        $this->assertEquals('bg-blue-500', PermissionTypeEnum::READ->color());

        // WRITE
        $this->assertEquals('permissions-permission-write', PermissionTypeEnum::WRITE->label());
        $this->assertEquals('write', PermissionTypeEnum::WRITE->code());
        $this->assertEquals('bg-yellow-500', PermissionTypeEnum::WRITE->color());

        // ALL
        $this->assertEquals('permissions-permission-all', PermissionTypeEnum::ALL->label());
        $this->assertEquals('all', PermissionTypeEnum::ALL->code());
        $this->assertEquals('bg-green-500', PermissionTypeEnum::ALL->color());

        // DENY
        $this->assertEquals('permissions-permission-deny', PermissionTypeEnum::DENY->label());
        $this->assertEquals('deny', PermissionTypeEnum::DENY->code());
        $this->assertEquals('bg-red-500', PermissionTypeEnum::DENY->color());
    }

    /**
     * INVARIANT: visibleInSelects() hides WRITE from UI
     * 
     * @test
     */
    public function test_visible_in_selects()
    {
        // READ, ALL, DENY should be visible
        $this->assertTrue(PermissionTypeEnum::READ->visibleInSelects());
        $this->assertTrue(PermissionTypeEnum::ALL->visibleInSelects());
        $this->assertTrue(PermissionTypeEnum::DENY->visibleInSelects());

        // WRITE should NOT be visible (we show ALL instead)
        $this->assertFalse(PermissionTypeEnum::WRITE->visibleInSelects());
    }

    /**
     * Static methods: values(), colors()
     * 
     * @test
     */
    public function test_static_values_and_colors()
    {
        // Act: Get values
        $values = PermissionTypeEnum::values();

        // Assert: Should not include WRITE (not visible)
        $this->assertNotContains(PermissionTypeEnum::WRITE->value, $values->toArray());

        // Should include READ, ALL, DENY
        $this->assertContains(PermissionTypeEnum::READ->value, $values->toArray());
        $this->assertContains(PermissionTypeEnum::ALL->value, $values->toArray());
        $this->assertContains(PermissionTypeEnum::DENY->value, $values->toArray());

        // Act: Get colors
        $colors = PermissionTypeEnum::colors();

        // Assert: Should have colors
        $this->assertGreaterThan(0, $colors->count());
    }

    /**
     * Bitmask edge cases
     * 
     * @test
     */
    public function test_bitmask_edge_cases()
    {
        // Edge: READ (1) vs READ (1) = true
        $this->assertTrue(PermissionTypeEnum::hasPermission(
            PermissionTypeEnum::READ,
            PermissionTypeEnum::READ
        ));

        // Edge: WRITE (3) includes READ (1) because 3 & 1 = 1
        $this->assertTrue(PermissionTypeEnum::hasPermission(
            PermissionTypeEnum::WRITE,
            PermissionTypeEnum::READ
        ));

        // Edge: READ (1) does not include WRITE (3) because 1 & 3 = 1 (not 3)
        $this->assertFalse(PermissionTypeEnum::hasPermission(
            PermissionTypeEnum::READ,
            PermissionTypeEnum::WRITE
        ));

        // Edge: ALL (7) includes everything except DENY
        $this->assertTrue(PermissionTypeEnum::hasPermission(
            PermissionTypeEnum::ALL,
            PermissionTypeEnum::READ
        ));

        $this->assertTrue(PermissionTypeEnum::hasPermission(
            PermissionTypeEnum::ALL,
            PermissionTypeEnum::WRITE
        ));
    }
}

