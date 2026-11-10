<?php
declare(strict_types=1);

return [
    // Deprecated: HTTP session/request-pipeline settings now live under http.session.*.
    // Legacy application or instance configs may still define session.*; Config::load/loadStack
    // migrate those keys to http.session.* within the same precedence layer.
];
