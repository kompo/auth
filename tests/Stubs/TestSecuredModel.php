<?php

namespace Kompo\Auth\Tests\Stubs;

use Condoedge\Utils\Models\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Test Secured Model
 * 
 * A test model with security restrictions enabled for testing authorization.
 */
class TestSecuredModel extends Model
{
    use HasFactory;

    protected $table = 'test_secured_models';

    protected $fillable = [
        'name',
        'description',
        'secret_field',
        'confidential_data',
        'user_id',
        'team_id',
    ];

    // Security configuration
    protected $readSecurityRestrictions = true;
    protected $saveSecurityRestrictions = true;
    protected $deleteSecurityRestrictions = true;
    protected $restrictByTeam = true;

    // Sensitive columns (require special permission to view)
    protected $sensibleColumns = [
        'secret_field',
        'confidential_data',
    ];

    /**
     * Scope for team-based security
     */
    public function scopeSecurityForTeams($query, $teamIds)
    {
        return $query->whereIn('team_id', $teamIds);
    }

    /**
     * Scope for user-owned records
     */
    public function scopeUserOwnedRecords($query)
    {
        return $query->where('user_id', auth()->id());
    }

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


