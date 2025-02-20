<?php

namespace Kompo\Auth\Exports;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\Log;
use Kompo\Auth\Exports\Traits\ExportableUtilsTrait;
use Kompo\Query;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

abstract class ExportableToExcel extends Query implements FromArray, WithHeadings, ShouldAutoSize, WithColumnFormatting, WithTitle, WithStyles
{
    use ExportableUtilsTrait;

    public const REGEX_CURRENCY = '/^\$\s*-?\d{1,3}(,\d{3})*(\.\d{2})?$/';
    public const REGEX_CURRENCY_FR = '/^-?\d{1,3}(.\d{3})*(\,\d{2})?\s*\$$/';

    protected $filename;
    protected $title;

    protected $columnFormats = [];

    protected $exportChildClass = null;
    protected $boldColumns = [1];
    protected $pastCountOfItems = 1; // Just needed to child classes

    public function title(): string
    {
        return $this->title ?: 'Worksheet';
    }

    public function styles($sheet)
    {
        // Rows height adjustment when we use break lines in cells
        $sheet->getStyle('A1:Z' . $sheet->getHighestRow())
            ->getAlignment()
            ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP)
            ->setWrapText(true);

        return collect($this->boldColumns)
            ->mapWithKeys(fn($col) => [$col => ['font' => ['bold' => true]]])
            ->all();
    }

    // Excel export methods
    public function columnFormats(): array
    {
        return $this->columnFormats;
    }

    public function headings(): array
    {
        if ($this->exportChildClass) {
            $childInstance = $this->render($this->getItems(null, 1)->first())->findByComponent($this->exportChildClass);

            return $this->parseHeaders($childInstance->headers());
        }

        try {
            return $this->parseHeaders($this->headers());
        } catch (\Exception $e) {
            Log::error($e->getMessage(), ['class' => static::class, 'trace' => $e->getTraceAsString(), 'user' => auth()->user()]);
            abort(500);
        }
        
    }

    public function array(): array
    {
        if ($this->exportChildClass) {
            $itemsGroups = $this->getItems()->map(function ($item) {
                $childInstance = $this->render($item)->findByComponent($this->exportChildClass);

                $items = $this->getItems($childInstance);

                // Adding a separator between each child with a bold column
                $boldColumn = collect($this->boldColumns)->last() + $this->pastCountOfItems;
                $this->pastCountOfItems = ($items->count() ?: 1) + 1;
                $this->boldColumns[] = $boldColumn;

                $items->prepend([$childInstance->exportableSeparator()]);

                return ['items' => $items, 'component' => $childInstance];
            });


            return $itemsGroups->map(function($itemsGroup) {
                if ($itemsGroup['items']->count() == 1) {
                    return [...$itemsGroup['items'], ['No items found.']];
                }

                return $itemsGroup['items']->map(function($item, $i) use ($itemsGroup) {
                    if ($i === 0) {
                        return $item;
                    }

                    return $this->formatItemToExport($item, $itemsGroup['component']);
                });
            })->flatten(1)->all();
        }

        return $this->getItems()->map(fn($item) => $this->formatItemToExport($item))->all();
    }

    /* PARSING METHODS */
    public function formatItemToExport($item, $fromInstance = null)
    {
        $fromInstance = $fromInstance ?? $this;

        $renderedItem = $fromInstance->render($item);

        if (!$renderedItem) {
            return [];
        }

        return collect($renderedItem->elements)
        ->filter(fn($el) => $el && !property_exists($el, 'class') || !str_contains($el->class, 'exclude-export'))
        ->map(function ($element, $i) {
            $letter = chr(65 + $i);

            $text = $this->getLabelsFromComponent($element);

            $format = $this->getCurrencyFormat($text);

            if ($format) {
                $this->columnFormats[strtoupper($letter)] = $format;
            }

            return $this->sanatizeText($text);
        })->all();
    }

    protected function parseHeaders($ths)
    {
        return collect($ths)->map(fn($th) => ($this->isExcludedHeader($th) || !$th) ? null : ($th?->label ?: '-'))->filter()->all();
    }

    protected function isExcludedHeader($th)
    {
        return $th && property_exists($th, 'class') && str_contains($th->class, 'exclude-export');
    }

    protected function getLabelsFromComponent($el)
    {
        if (property_exists($el, 'elements')) {
            $implodeUnion = (str_contains($el->bladeComponent, 'Flex') || str_contains($el->bladeComponent, 'Columns')) ? ' | ' : "\r\n ";

            return collect($el->elements)->map(fn($el) => $this->getLabelsFromComponent($el))->filter()->implode($implodeUnion);
        }

        if (property_exists($el, 'label')) {
            if (preg_match('/<[^>]*>/', $el->label)) {
                return $this->convertHtmlToPlainText($el->label);
            }

            return \Lang::has($el->label) ? __($el->label) : $el->label;
        }

        return "";
    }

    protected function getItems($fromInstance = null, $perPage = 1000)
    {
        $fromInstance = $fromInstance ?? $this;

        $prevPerPage = $fromInstance->perPage;
        $fromInstance->perPage = $perPage ?? 1000000;

        $items = $fromInstance->query();

        if ($items instanceof Builder) {
            $items = $items->take($perPage ?? 1000000)->get();
        }

        $fromInstance->perPage = $prevPerPage;

        return $items;
    }

    /** FORMATS */
    protected function currencyFormat()
    {
        return NumberFormat::FORMAT_CURRENCY_USD;
    }

    protected function getCurrencyFormat($text)
    {
        if (preg_match(static::REGEX_CURRENCY, $text) || preg_match(static::REGEX_CURRENCY_FR, $text)) {
            return $this->currencyFormat();
        }

        return null;
    }

    /* SANATIZE */
    protected function sanatizeText($text)
    {
        if (preg_match(static::REGEX_CURRENCY, $text)) {
            return floatval(preg_replace('/[^0-9.-]/', '', $text));
        }

        if (preg_match(static::REGEX_CURRENCY_FR, $text)) {
            return floatval(str_replace(',', '.', preg_replace('/[^0-9.,-]/', '', $text)));
        }

        return  html_entity_decode(trim($text));
        // return mb_convert_encoding(trim($text), 'ISO-8859-1', 'UTF-8');
    }

    protected function convertHtmlToPlainText($html)
    {
        $dom = new \DOMDocument;
        $html = preg_replace('/&(?!amp)/', '&amp;', $html);
        try{
            $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        } catch (\Exception $e) {
            Log::error($e->getMessage(), ['class' => static::class, 'html' => $html, 'trace' => $e->getTraceAsString(), 'user' => auth()->user()]);

            return preg_replace("/\n+/", "\n", strip_tags($html));;
        }

        $xpath = new \DOMXPath($dom);

        $nodes = $xpath->query('//text()');

        $texts = [];
        foreach ($nodes as $node) {
            $texts[] = trim($node->nodeValue);
        }

        $texts = array_filter($texts);

        $text = implode(" \n", $texts);

        return \Lang::has($text) ? __($text) : $text;
    }
}
