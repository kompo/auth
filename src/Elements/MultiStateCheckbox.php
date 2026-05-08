<?php

namespace Kompo\Auth\Elements;

use Illuminate\Support\Collection;
use Kompo\Elements\Field;

/**
 * A field that displays one of N color states (single mode) or a stack of
 * bars representing the distinct values currently held by a group of
 * sibling fields (section mode).
 *
 * Single-mode value is a scalar in `values`. Section-mode value is an
 * array of scalars; the bar stack shows each distinct entry once.
 */
class MultiStateCheckbox extends Field
{
    public $vueComponent = 'MultiStateCheckbox';

    protected function initialize($label)
    {
        parent::initialize($label);

        $this->noInputWrapper();
    }

    public function values($values)
    {
        return $this->config(['values' => $this->normalize($values)]);
    }

    public function colors($colors)
    {
        return $this->config(['colors' => $this->normalize($colors)]);
    }

    public function mode($mode)
    {
        return $this->config(['mode' => $mode]);
    }

    public function readonly(bool $readonly = true)
    {
        return $this->config(['readonly' => $readonly]);
    }

    /**
     * Identify a sync group: cells and a section sharing the same group key
     * keep each other's visuals in sync via a Vue event bus (no server round-trip).
     */
    public function group(string $key)
    {
        return $this->config(['group' => $key]);
    }

    /**
     * Per-cell identifier within its group. Used so the section header knows
     * which cell's value changed when receiving a cell-state event.
     */
    public function permissionId($id)
    {
        return $this->config(['permission_id' => $id]);
    }

    protected function normalize($input): array
    {
        $arr = $input instanceof Collection ? $input->all() : (array) $input;
        return array_values($arr);
    }
}
