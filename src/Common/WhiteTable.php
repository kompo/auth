<?php

namespace Kompo\Auth\Common;

use Kompo\Table as KompoTable;

class WhiteTable extends KompoTable
{
    use HasAuthorizationUtils;
    
    public function createdDisplay()
    {
        $this->itemsWrapperClass = 'bg-white rounded-2xl border border-greenmain';
    }
}
