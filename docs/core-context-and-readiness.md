# Core request context, sessions and readiness

## Request context

`RequestContextMiddleware` creates a server-owned request id for every HTTP request. A syntactically valid inbound `X-Correlation-Id` is propagated; otherwise the request id becomes the correlation id. Both ids are attached to the request, included in access/error logging, and returned as response headers.

## Sessions

Winglet Identity is the session authority. It creates anonymous or authenticated sessions and issues the JWT bearer token containing `sid`. Core accepts session identity only from a successfully validated token. The legacy `Session` request attribute is a compatibility projection of `AuthContext::getSessionId()`; `X-Session-Id` is no longer trusted.

## AppContext

Applications may replace `AppContextProviderInterface` in `bootstrap/services.php`. The default provider exposes validated auth, resolved tenancy and request context. Domain-specific context belongs to the application provider, not Core.

## API errors

Applications may replace `ApiExceptionMapperInterface` and `ApiErrorResponseFactoryInterface`. Framework defaults preserve the established error shape while adding correlation id when available.

## Readiness

`/health` remains a liveness endpoint. `/readiness` executes services tagged `readiness.probe` that implement `ReadinessProbeInterface`; no probes means ready. Probe responses expose stable codes, not raw dependency errors.
