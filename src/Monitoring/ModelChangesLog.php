<?php
namespace Kompo\Auth\Monitoring;

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
    ];

    protected $casts = [
        'columns_changed' => 'array',
        'action' => ChangeTypeEnum::class,
        'changed_at' => 'datetime',
    ];
}