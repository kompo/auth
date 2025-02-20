<?php

namespace Kompo\Auth\Common;

use Kompo\Auth\Exports\TableExportableToExcel;

class WhiteTable extends TableExportableToExcel
{
    use HasAuthorizationUtils;
    
    public function createdDisplay()
    {
        $this->itemsWrapperClass = 'bg-white rounded-2xl border border-greenmain';
    }
}
