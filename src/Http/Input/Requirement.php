<?php
declare(strict_types=1);

namespace Winglet\Core\Http\Input;

/**
 * Boolean requirement expression for presence rules.
 *
 * Example:
 *   a AND (b OR (c AND d AND e))
 */
interface Requirement
{
    /** @param array<string,bool> $present */
    public function isSatisfied(array $present): bool;

    /**
     * Suggests missing variable names that would satisfy this requirement.
     *
     * - For AND: union of missing suggestions.
     * - For OR: returns the branch with smallest missing set.
     *
     * @param array<string,bool> $present
     * @return list<string>
     */
    public function missing(array $present): array;
}
