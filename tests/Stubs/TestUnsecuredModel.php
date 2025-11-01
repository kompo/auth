<?php

namespace Kompo\Auth\Tests\Stubs;

use Condoedge\Utils\Models\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Test Unsecured Model
 * 
 * A test model with security restrictions DISABLED for testing bypass scenarios.
 */
class TestUnsecuredModel extends Model
{
    use HasFactory;

    protected $table = 'test_unsecured_models';

    protected $fillable = [
        'name',
        'description',
        'user_id',
        'team_id',
    ];

    // Security disabled
    protected $readSecurityRestrictions = false;
    protected $saveSecurityRestrictions = false;
    protected $deleteSecurityRestrictions = false;
    protected $restrictByTeam = false;

    /**
     * Team relationship
     */
    public function team()
    {
        return $this->belongsTo(config('kompo-auth.team-model-namespace'));
    }

    /**
     * User relationship
     */
    public function user()
    {
        return $this->belongsTo(config('kompo-auth.user-model-namespace'));
    }
}


