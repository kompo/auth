<?php

namespace Kompo\Auth\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    use \Lab404\Impersonate\Models\Impersonate;
    use \Kompo\Auth\Models\Teams\HasTeamsTrait;
    use \Kompo\Auth\Models\Traits\HasRelationType;
    use \Kompo\Auth\Models\Maps\MorphManyAddresses;
    use \Kompo\Auth\Models\Phone\MorphManyPhones;
    
    use \Kompo\Auth\Models\Traits\HasSearchableNameTrait;
    public const SEARCHABLE_NAME_ATTRIBUTE = 'name';

    use \Kompo\Auth\Models\Traits\HasProfilePhotoTrait;
    public const PHOTO_IMAGE_COLUMN = 'profile_photo';

    //use \Illuminate\Database\Eloquent\SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    /* SCOPES */
    public function scopeHasNameLike($query, $search)
    {
        $query->where('name', 'LIKE', wildcardSpace($search));
    }

    /* CALCULATED FIELDS */
    public function getFirstName()
    {
        return $this->first_name ?: guessFirstName($this->name);
    }

    public function getLastName()
    {
        return $this->last_name ?: guessLastName($this->name);
    }

    /* ACTIONS */
    public function handleRegisterNames()
    {
        if (config('kompo-auth.register_with_first_last_name')) {
            $this->name = $this->first_name.' '.$this->last_name;
        }
    }

    public function logMeOut()
    {
        \Auth::guard()->logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect()->to('/');
    }

    /* IMPERSONATE PACKAGE */
    public function canImpersonate()
    {
        return $this->isSuperAdmin();
    }
}
