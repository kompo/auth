<?php

namespace Kompo\Auth\Tests\Stubs;

use Kompo\Form;

/**
 * Test Secured Component
 * 
 * A test component with security enabled for testing authorization.
 */
class TestSecuredComponent extends Form
{
    public $model = \Kompo\Auth\Tests\Stubs\TestSecuredModel::class;

    // Security is enabled by default (HasAuthorizationUtils plugin)
    protected $checkIfUserHasPermission = true;

    public function render()
    {
        return [
            _Html('Test Secured Component')->id('secured-component-title'),
            _Input('Name')->name('name')->id('name-input'),
            _Rows(
                _Button('Save')->id('save-button'),
                _Button('Delete')->id('delete-button')->checkAuth('TestSecuredComponent', \Kompo\Auth\Models\Teams\PermissionTypeEnum::ALL),
            )->id('action-buttons'),
        ];
    }

    public function rules()
    {
        return [
            'name' => 'required|string|max:255',
        ];
    }
}

