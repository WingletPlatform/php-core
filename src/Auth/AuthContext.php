<?php
declare(strict_types=1);

namespace Winglet\Core\Auth;

/**
 * Authentication context resolved from Bearer token (or null for anonymous).
 *
 * Stored to Request attributes by AuthMiddleware under key "auth".
 * Read by:
 * - Controllers (for clientId/sessionLevel/groups/etc.)
 * - AccessDecisionManager (authorization checks).
 */
final class AuthContext
{
    public function __construct(
        public readonly bool $authenticated,
        public readonly ?int $clientId,        // idi
        public readonly ?int $sessionId,       // sid
        public readonly ?int $identityVersion, // jti
        public readonly ?int $sessionLevel,    // slv
        /** @var string[] */
        public readonly array $groups,         // optional: groups
        public readonly array $privileges,     // legacy: privileges
        public readonly ?array $roles,         // deprecated compatibility slot; never used for authorization
        public readonly ?\DateTimeImmutable $expiresAt,
        public readonly array $rawPayload = [],
        public readonly ?string $actorSubject = null,
        public readonly ?string $callerServiceSubject = null,
        public readonly ?int $sessionVersion = null,
        public readonly ?int $tenantId = null,
        public readonly string $tenantContext = 'global',
        public readonly string $assurance = 'Anonymous',
        public readonly string $tokenProfile = 'legacy',
    ) {}

    public static function anonymous(): self
    {
        return new self(
            authenticated: false,
            clientId: null,
            sessionId: null,
            identityVersion: null,
            sessionLevel: null,
            groups: [],
            privileges: [],
            roles: null,
            expiresAt: null,
            rawPayload: [],
        );
    }
    /**
     * Factory for authenticated contexts (used by legacy token service).
     *
     * @param string[] $groups
     * @param array<string,mixed> $claims Extra claims from token parser (sessionId, identityVersion, privileges, expiresAt, rawPayload).
     */
    public static function authenticated(?string $clientId, ?int $sessionLevel, array $groups = [], array $claims = []): self
    {
        $sessionId = isset($claims['sessionId']) ? (is_numeric($claims['sessionId']) ? (int)$claims['sessionId'] : null) : null;
        $identityVersion = isset($claims['identityVersion']) ? (is_numeric($claims['identityVersion']) ? (int)$claims['identityVersion'] : null) : null;

        $privileges = [];
        if (isset($claims['privileges']) && is_array($claims['privileges'])) {
            $candidate = $claims['privileges'];
            if (array_is_list($candidate) && self::hasOnlyNonEmptyStrings($candidate)) {
                $privileges = $candidate;
            }
        }

        // Kept as a compatibility accessor; roles are not an authorization input.
        $roles = null;

        $expiresAt = null;
        if (isset($claims['expiresAt']) && $claims['expiresAt'] instanceof \DateTimeImmutable) {
            $expiresAt = $claims['expiresAt'];
        }

        $rawPayload = [];
        if (isset($claims['rawPayload']) && is_array($claims['rawPayload'])) {
            $rawPayload = $claims['rawPayload'];
        }

        return new self(
            authenticated: true,
            clientId: $clientId === null ? null : (int)$clientId,
            sessionId: $sessionId,
            identityVersion: $identityVersion,
            sessionLevel: $sessionLevel,
            groups: $groups,
            privileges: $privileges,
            roles: $roles,
            expiresAt: $expiresAt,
            rawPayload: $rawPayload,
        );
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isAuthenticated(): bool
    {
        return $this->authenticated;
    }

    /**
     * Identity id from the validated token subject claim.
     *
     * Historically this value is exposed as clientId because legacy tokens used
     * the `idi` claim for the authenticated client/identity. Newer service
     * tokens may also carry a separate string `clientId` claim for the service
     * client identifier. Use getTokenClientId() for that value.
     */
    public function getClientId(): ?int
    {
        return $this->clientId;
    }

    /**
     * Explicit alias for getClientId() for code that treats `idi` as identity id.
     */
    public function getIdentityId(): ?int
    {
        return $this->clientId;
    }

    public function getActorSubject(): ?string { return $this->actorSubject; }
    public function getCallerServiceSubject(): ?string { return $this->callerServiceSubject; }
    public function getSessionVersion(): ?int { return $this->sessionVersion ?? $this->identityVersion; }
    public function getTenantId(): ?int { return $this->tenantId; }
    public function getTenantContext(): string { return $this->tenantContext; }
    public function getAssurance(): string { return $this->assurance; }
    public function getTokenProfile(): string { return $this->tokenProfile; }

    public function hasPrivilege(string $required): bool
    {
        return \Winglet\Core\Security\AccessControl\PrivilegeMatcher::matches($this->getPrivileges(), $required);
    }

    /**
     * Service/user identity type claim, when present in the validated token.
     */
    public function getIdentityType(): ?string
    {
        $value = $this->rawPayload['identityType'] ?? $this->rawPayload['identity_type'] ?? null;
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string)$value);
        return $value !== '' ? $value : null;
    }

    /**
     * String service client id claim, when present in the validated token.
     *
     * This is intentionally separate from getClientId()/getIdentityId(), which
     * return the numeric authenticated identity id from `idi`.
     */
    public function getTokenClientId(): ?string
    {
        $value = $this->rawPayload['clientId'] ?? $this->rawPayload['client_id'] ?? null;
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string)$value);
        return $value !== '' ? $value : null;
    }

    public function getSessionId(): ?int
    {
        return $this->sessionId;
    }

    /**
     * Opaque representation suitable for application ownership references.
     * Canonical Identity tokens may use GUID session identifiers while older
     * profiles use numeric identifiers.
     */
    public function getSessionSubject(): ?string
    {
        $subject = $this->rawPayload['sid'] ?? null;
        if (is_string($subject) && $subject !== '') {
            return $subject;
        }

        return $this->sessionId === null ? null : (string)$this->sessionId;
    }

    public function getIdentityVersion(): ?int
    {
        return $this->identityVersion;
    }

    public function getSessionLevel(): ?int
    {
        return $this->sessionLevel;
    }

    public function getGroups(): array
    {
        return $this->groups;
    }

    public function getPrivileges(): array
    {
        if (!array_is_list($this->privileges)) {
            return [];
        }

        foreach ($this->privileges as $privilege) {
            if (!is_string($privilege) || $privilege === '') {
                return [];
            }
        }

        return $this->privileges;
    }

    /** @deprecated Roles claims are ignored; use groups for group-based access. */
    public function getRoles(): ?array
    {
        return $this->roles;
    }

    public function getRawPayload(): array
    {
        return $this->rawPayload;
    }

    /** @param array<mixed> $values */
    private static function hasOnlyNonEmptyStrings(array $values): bool
    {
        foreach ($values as $value) {
            if (!is_string($value) || $value === '') {
                return false;
            }
        }

        return true;
    }

}
