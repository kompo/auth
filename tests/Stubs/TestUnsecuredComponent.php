<?php

namespace Kompo\Auth\Tests\Stubs;

use Kompo\Form;

/**
 * Test Unsecured Component
 * 
 * A test component with security DISABLED.
 */
class TestUnsecuredComponent extends Form
{
    // Security explicitly disabled
    protected $checkIfUserHasPermission = false;

    public function render()
    {
        return [
            _Html('Test Unsecured Component')->id('unsecured-component-title'),
            _Input('Name')->name('name'),
        ];
    }
}


