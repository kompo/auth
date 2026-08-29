<?php

namespace Kompo\Auth\Teams\Roles;

use Kompo\Auth\Models\Teams\PermissionTypeEnum;

/**
 * What one role holds on one section: per-state counts and the resulting kind
 * (none / partial / mixed / a uniform state code), rendered as the section pill.
 */
final class SectionAggregate
{
    public readonly array $counts;
    public readonly int $total;
    public readonly int $set;
    public readonly string $kind;

    public function __construct(iterable $types)
    {
        $counts = ['all' => 0, 'read' => 0, 'deny' => 0, 'write' => 0, 'none' => 0];

        foreach ($types as $type) {
            $counts[PermissionTypeEnum::tryFrom((int) $type)?->code() ?? 'none']++;
        }

        $this->counts = $counts;
        $this->total = array_sum($counts);
        $this->set = $this->total - $counts['none'];
        $this->kind = $this->resolveKind();
    }

    public static function sample(array $counts): self
    {
        return new self(collect($counts)->flatMap(fn($count, $code) => array_fill(0, $count, PermissionTypeEnum::fromCode($code)?->value ?? 0)));
    }

    public function hasDeny(): bool
    {
        return $this->counts['deny'] > 0;
    }

    public function label(): string
    {
        return match ($this->kind) {
            'partial' => 'permissions-coverage-partial',
            'mixed' => 'permissions-coverage-mixed',
            'none' => 'permissions-coverage-none',
            'deny' => 'permissions-state-denied',
            default => PermissionTypeEnum::fromCode($this->kind)->label(),
        };
    }

    public function html(): string
    {
        $segments = collect($this->counts)->filter()
            ->map(fn($count, $state) => '<i class="roles-seg-' . $state . '" style="width:' . $this->percent($count) . '%"></i>')
            ->implode('');

        $dot = $this->hasDeny() && $this->kind !== 'deny' ? '<span class="roles-agg-dot"></span>' : '';
        $coverage = $this->kind !== 'partial' ? '' : '<span class="roles-agg-cov"><i style="width:' . $this->percent($this->set) . '%"></i></span>';

        return '<span class="roles-agg roles-agg--' . $this->kind . '">'
            . '<span class="roles-agg-bar">' . $segments . '</span>'
            . '<span class="roles-agg-word">' . e(__($this->label())) . '</span>'
            . '<span class="roles-agg-n"><b>' . $this->set . '</b>/' . $this->total . '</span>'
            . $dot . $coverage
            . '</span>';
    }

    protected function resolveKind(): string
    {
        if (!$this->set) {
            return 'none';
        }

        if ($this->counts['none']) {
            return 'partial';
        }

        $present = array_keys(array_filter(array_diff_key($this->counts, ['none' => 0])));

        return count($present) > 1 ? 'mixed' : $present[0];
    }

    protected function percent(int $count): float
    {
        return round($count / max($this->total, 1) * 100, 1);
    }
}
