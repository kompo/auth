<?php

namespace Kompo\Auth\Common;

use Kompo\Auth\Common\Table;
use Kompo\Elements\Layout;
use Kompo\Th;

class ResponsiveTable extends Table
{
    public $class = 'responsive-table';

    protected function decorateRow($row)
    {
        $wrapper = ($row instanceof Layout) ? $row::class : null;
        $elements = $wrapper ? $row->elements : $row;

        $decoratedElements = collect($elements)->map(function ($element, $i) {
            return $element->attr(['data-label' => $this->getHeader($i)]);
        });

        return $wrapper ? $wrapper::form($decoratedElements) : $decoratedElements;
    }

    protected function getHeader($index)
    {
        if (!method_exists($this, 'headers')) {
            return '';
        }

        $header = $this->headers()[$index] ?? '';

        return ($header instanceof Th) ? $header->label : $header;
    }
}