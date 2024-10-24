<?php

namespace Kompo\Auth\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoginAttempt extends Model
{
    use HasFactory;

    public const TYPE_LOCAL = 1;
    public const TYPE_SSO = 2;

    public static function loginTypes()
    {
        return [
            static::TYPE_LOCAL => __('translate.local'),
            static::TYPE_SSO => __('translate.sso'),
        ];
    }

    public function getStatusPill()
    {
        $label = $this->success ? __('translate.success') : __('translate.failed');
        $class = $this->success ? 'bg-positive' : 'bg-danger';

        return _Pill($label)->class($class)
            ->class('text-white');
    }

    public function getTypeLabelAttribute()
    {
        return static::loginTypes()[$this->login_type];
    }
}
