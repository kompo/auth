<?php

namespace Kompo\Auth\Common\Plugins;

use Condoedge\Utils\Kompo\Plugins\Base\ComponentPlugin;
use Kompo\Auth\Models\Teams\Permission;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;

/**
 * HasAuthorizationUtils Plugin
 * 
 * Provides authorization functionality for Kompo components.
 * Controls component rendering and form submission based on user permissions.
 *
 * Security Flow:
 * 1. RENDER: During boot, checks READ permission to allow component rendering
 * 2. SUBMIT: During form submission, checks WRITE permission for authorization
 * 
 * Component permission key is derived from the component's class name by default,
 * but can be overridden with a getPermissionKey() method.
 */
class HasAuthorizationUtils extends ComponentPlugin
{
    /**
     * Controls whether permission checks are performed.
     * Can be overridden at the component level.
     */
    protected $checkIfUserHasPermission = true;
    
    /**
     * Executed when the component boots.
     * Verifies READ permission for rendering the component.
     * 
     * @throws \Illuminate\Auth\Access\AuthorizationException On permission failure
     */
    public function onBoot()
    {
        // Skip check if security is globally bypassed
        if ($this->isSecurityGloballyBypassed()) {
            return;
        }
    
        // Abort if user lacks READ permission
        if(!$this->checkPermissions(PermissionTypeEnum::READ)) {
            abort(403, 'Unauthorized action.');
        }
    }

    /**
     * Verifies permission for form submissions.
     * Called during component authorization flow.
     * 
     * @return bool True if authorized, false otherwise
     */
    public function authorize()
    {
        // Skip check if security is globally bypassed
        if ($this->isSecurityGloballyBypassed()) {
            return true;
        }

        // Check for WRITE permission
        return $this->checkPermissions(PermissionTypeEnum::WRITE);
    }

    /**
     * Checks if security is globally bypassed.
     * 
     * @return bool True if security is bypassed
     */
    protected function isSecurityGloballyBypassed()
    {
        return globalSecurityBypass();
    }

    /**
     * Checks if the user has the required permission type.
     * 
     * @param PermissionTypeEnum $type The permission type to check
     * @return bool True if permitted, false otherwise
     */
    protected function checkPermissions($type)
    {
        // Get configuration for permission checking
        $this->checkIfUserHasPermission = 
            config('kompo-auth.security.default-read-security-restrictions') || 
            $this->getComponentProperty('checkIfUserHasPermission');

        // Perform permission check if enabled and permission exists
        if($this->checkIfUserHasPermission && 
           permissionMustBeAuthorized(static::getPermissionKey()) && 
           !auth()->user()?->hasPermission(
               static::getPermissionKey(), 
               $type, 
               $this->getComponentProperty('specificPermissionTeamId'))
        ) {
            return false;
        }

        return true;
    }

    /**
     * Gets the permission key for the component.
     * Either uses a custom method or defaults to the class name.
     * 
     * @return string The permission key
     */
    protected function getPermissionKey()
    {
        if ($this->componentHasMethod('getPermissionKey')) {
            return $this->callComponentMethod('getPermissionKey');
        }
        
        return class_basename($this->component);
    }
}