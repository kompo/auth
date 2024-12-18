<?php

namespace Kompo\Auth\Monitoring;

use Kompo\Auth\Models\User;
use Kompo\Models\ModelBase;

class ModelChangesLog extends ModelBase
{
    // TODO Consider putting this to true, but we already have changed_at and this record in only for creating, not updating
    public $timestamps = false;

    protected $fillable = [
        'changeable_type',
        'changeable_id',
        'action',
        'columns_changed',
        'changed_by',
        'old_data',
        'changed_at'
    ];

    protected $casts = [
        'columns_changed' => 'array',
        'action' => ChangeTypeEnum::class,
        'changed_at' => 'datetime',
        'old_data' => 'array',
    ];
    const CREATED_AT = 'changed_at';

    // RELATIONSHIPS
    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function changeable()
    {
        return $this->morphTo();
    }

    // CALCULATED FIELDS
    public function label()
    {
        return $this->changedBy->name . ' - ' . $this->changed_at->format('d/m/Y H:i');
    }
}
