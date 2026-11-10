<?php
declare(strict_types=1);

namespace Winglet\Core\Security\AccessControl;

final class PrivilegeMatcher
{
    /** @param iterable<mixed> $grants */
    public static function matches(iterable $grants, string $required): bool
    {
        if (preg_match('/^[\p{L}\p{N}_-]+(?:\.[\p{L}\p{N}_-]+)*$/uD', $required) !== 1) return false;
        foreach ($grants as $grant) {
            if (!is_string($grant)) continue;
            if ($grant === '*') return true;
            if (preg_match('/^[\p{L}\p{N}_-]+(?:\.[\p{L}\p{N}_-]+)*(?:\.\*)?$/uD', $grant) !== 1) continue;
            if (str_ends_with($grant, '.*') && str_starts_with($required, substr($grant, 0, -1))) return true;
            if ($grant === $required) return true;
        }
        return false;
    }
}
