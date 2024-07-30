<?php

namespace App\Exports;

use Kompo\TableRow;

class TableExportableToExcel extends ExportableToExcel
{
    public $layout = 'Table';
    public $card = TableRow::class;
}