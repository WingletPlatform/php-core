<?php
declare(strict_types=1);

/**
 * Marker interface for controllers.
 *
 * Responsibilities:
 * - Provides a common type for framework controllers.
 *
 * Implemented by:
 * - AbstractController and application controllers.
 * Used by:
 * - ControllerInvoker (and later DI/container rules).
 */

namespace Winglet\Core\Http\Controller;

interface ControllerInterface {}
