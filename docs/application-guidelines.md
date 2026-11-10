# Winglet Application Development Guidelines (User Version)

## General Principles
Winglet applications follow a practical architecture:
Controller → Service → Entity

Controllers handle HTTP, Services contain business logic, Entities represent domain data.

## Project Structure
src/
  Controller/
  Services/
  Entities/
  Enums/
  Support/
  Security/
resources/
  config/
tests/

## Controllers
- Keep controllers small.
- No business logic in controllers.
- Controllers validate input and call Services.

### Controller grouping
Use primarily domain-based grouping:
- Controller/Events/
- Controller/Registrations/

Use special groups only when justified:
- Controller/Admin/
- Controller/Self/
- Controller/Public/
- Controller/Auth/ (authentication services only)

Rule: Domain first, role-based grouping only when it improves structure.

## Services
- Business logic in Services.
- Controllers call Services.
- Small app: Services/
- Large app: Services/<Domain>/

## Entities
- Doctrine entities.
- exportArray() allowed for internal APIs.
- External APIs should use response models.

## Request Handling
- Simple input → controller
- Complex input → DTO/helper

## Logging
- app log
- security log
- audit log for admin/state changes

## Bootstrap
- Prefer CLI initialization.
- API bootstrap only if CLI impractical.
- Must be config-protected.
- Must run only once.
- Must be audit logged.

## Tests
- PHPUnit
- PHPStan


## Domain Events and optional webhooks
- Core provides only generic domain event contracts and a no-op event publisher.
- Outbound webhook delivery is provided by optional package `winglet/webhooks`.
- Services that need webhook delivery should install `winglet/webhooks` and enable `\Winglet\Webhooks\Bridge\WebhookExtension::class` in `app.extensions`.
- Services that do not send webhook events should not install the webhook package.
