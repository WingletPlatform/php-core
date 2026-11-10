<?php
declare(strict_types=1);

namespace Winglet\Core\Http\Input;

use Winglet\Core\Http\Request;

/**
 * Optional trait for controllers to shorten InputValidator usage.
 *
 * Usage:
 *   class CustomerController {
 *     use ControllerInputTrait;
 *
 *     public function get(Request $request, int $id) {
 *       $in = $this->input($request)
 *         ->optional('q', 'string', ['validators' => [Validators::maxLen(100)]])
 *         ->validate();
 *     }
 *   }
 */
// @phpstan-ignore-next-line trait.unused
trait ControllerInputTrait
{
    protected function input(Request $request): InputValidator
    {
        return Input::from($request);
    }
}
