<?php
declare(strict_types=1);

namespace Winglet\Core\Http\Input;

use Winglet\Core\Http\Request;

/**
 * Convenience facade for controller input validation.
 *
 * Usage:
 *   $in = Input::from($request)
 *       ->required('id', 'int')
 *       ->optional('q', 'string', ['validators' => [Validators::minLen(2)]])
 *       ->validate();
 *
 *   $id = $in->get('id'); // converted int
 */
final class Input
{
    public static function from(Request $request): InputValidator
    {
        return InputValidator::from($request);
    }
}
