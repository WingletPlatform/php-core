# Winglet Guideline Compliance Audit Checklist

## Structure
[ ] src/Controller exists
[ ] src/Services exists
[ ] src/Entities exists
[ ] resources/config exists
[ ] tests exists

## Controllers
[ ] Controllers are small
[ ] No business logic in controllers
[ ] Controllers call Services
[ ] Domain-based grouping used
[ ] Admin separated if exists
[ ] Self used only if justified

## Services
[ ] Business logic in Services
[ ] No HTTP dependencies

## Entities
[ ] Doctrine entities
[ ] exportArray only for internal APIs

## Requests
[ ] Complex inputs use DTO/helper

## Logging
[ ] app log
[ ] security log
[ ] audit log for admin/state changes

## Bootstrap
[ ] Config protected
[ ] Runs only once
[ ] Prefer CLI
[ ] Audit logged

## General
[ ] Clear separation Controller/Service/Entity/Support/Security
[ ] No large controllers
