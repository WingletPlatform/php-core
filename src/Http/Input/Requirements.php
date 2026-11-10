<?php
declare(strict_types=1);

namespace Winglet\Core\Http\Input;

/**
 * Helpers to build presence rules:
 * - Requirements::var('a')
 * - Requirements::allOf(...)
 * - Requirements::anyOf(...)
 */
final class Requirements
{
    public static function var(string $name): Requirement
    {
        return new RequirementVar($name);
    }

    public static function allOf(Requirement ...$reqs): Requirement
    {
        return new RequirementAll(array_values($reqs));
    }

    public static function anyOf(Requirement ...$reqs): Requirement
    {
        return new RequirementAny(array_values($reqs));
    }
}

final class RequirementVar implements Requirement
{
    public function __construct(private string $name) {}

    public function isSatisfied(array $present): bool
    {
        return !empty($present[$this->name]);
    }

    public function missing(array $present): array
    {
        return $this->isSatisfied($present) ? [] : [$this->name];
    }
}

final class RequirementAll implements Requirement
{
    /** @param list<Requirement> $reqs */
    public function __construct(private array $reqs) {}

    public function isSatisfied(array $present): bool
    {
        foreach ($this->reqs as $r) {
            if (!$r->isSatisfied($present)) return false;
        }
        return true;
    }

    public function missing(array $present): array
    {
        $out = [];
        foreach ($this->reqs as $r) {
            foreach ($r->missing($present) as $m) {
                $out[$m] = true;
            }
        }
        return array_keys($out);
    }
}

final class RequirementAny implements Requirement
{
    /** @param list<Requirement> $reqs */
    public function __construct(private array $reqs) {}

    public function isSatisfied(array $present): bool
    {
        foreach ($this->reqs as $r) {
            if ($r->isSatisfied($present)) return true;
        }
        return false;
    }

    public function missing(array $present): array
    {
        if ($this->isSatisfied($present)) return [];

        // pick the branch that needs the fewest variables
        $best = null;
        foreach ($this->reqs as $r) {
            $m = $r->missing($present);
            if ($best === null || count($m) < count($best)) {
                $best = $m;
            }
        }
        return $best ?? [];
    }
}