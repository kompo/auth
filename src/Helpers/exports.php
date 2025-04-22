<?php

function _ExcelExportButton()
{
    return _Link('Excel')->icon('download')->outlined()->class('mb-4 !px-3')->selfPost('exportToExcel')->withAllFormValues()->inModal();
}
