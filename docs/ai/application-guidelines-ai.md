# Winglet Application Development Guidelines (AI Detailed Version)

## Architecture
Controller → Service → Entity

Responsibilities:
- Controller: HTTP, validation, response
- Service: Business logic
- Entity: Domain model
- Support: Utilities
- Security: Auth & access control

Controllers MUST NOT contain business logic.

## Directory Structure
src/
  Controller/
  Services/
  Entities/
  Enums/
  Support/
  Security/
  Cli/
resources/
  config/
tests/

## Controller Rules
- One controller per endpoint or small operation.
- Controllers must be thin.
- Controllers call Services.

### Grouping Priority
1. Domain
2. Admin
3. Self
4. Public
5. Auth

Rule: Domain before role.

## Services
- Business logic only.
- No HTTP dependencies.
- Use AppContext.

## Entities
- Doctrine ORM
- Represent domain model
- exportArray() only for internal APIs

## Request / Response
- Simple request → Controller
- Complex → DTO
- Internal API → exportArray
- External API → Response DTO

## Logging
Required:
- app log
- security log
- audit log for state changes

Audit log fields:
- actor
- action
- target
- timestamp
- result

## Bootstrap
Allowed only if:
- Not initialized
- Enabled via config
- Prefer CLI
- API only if necessary
- Must audit log

## Naming
Controllers:
- CreateX
- UpdateX
- DeleteX
- GetX
- ListX

Services:
- XService
