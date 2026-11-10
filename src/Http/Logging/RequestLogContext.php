<?php
declare(strict_types=1);

namespace Winglet\Core\Http\Logging;

final class RequestLogContext
{
    public string $authState = 'anonymous';
    public ?int $identityId = null;
    public ?int $sessionId = null;
    public ?string $tenantId = null;
    public ?string $requestId = null;
    public ?string $correlationId = null;
    public ?string $route = null;
    public ?string $controller = null;
    public ?string $action = null;

    public function markAuthenticated(?int $identityId, ?int $sessionId): void
    {
        $this->authState = 'authenticated';
        $this->identityId = $identityId;
        $this->sessionId = $sessionId;
    }

    public function markInvalidToken(): void
    {
        $this->authState = 'invalid_token';
        $this->identityId = null;
        $this->sessionId = null;
    }

    public function setTenantId(?string $tenantId): void
    {
        $this->tenantId = ($tenantId !== null && $tenantId !== '') ? $tenantId : null;
    }

    public function setRoute(string $route, string $controller, string $action): void
    {
        $this->route = $route;
        $this->controller = $controller;
        $this->action = $action;
    }
}
