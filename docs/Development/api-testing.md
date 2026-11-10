# API Testing (Bruno)

Winglet uses Bruno for API testing. This allows API tests to be stored as files in the repository,
run from CLI, and integrated with PhpStorm and CI in the same way as PHPUnit tests.

## Running tests

Run API tests from project root:

    bin/test-api api-tests local

Or via Composer:

    composer test:api

## Environment variables

All Winglet applications should use the same environment variable names:

| Variable        | Description                         |
|-----------------|-------------------------------------|
| BASE_URL        | Base URL of tested service          |
| AUTH_URL        | Identity login URL                  |
| AUTH_KIND       | Login type (e.g. username)          |
| AUTH_IDENTIFIER | Username / identifier               |
| AUTH_PASSWORD   | Password                            |
| ACCESS_TOKEN    | Bearer token (set automatically)    |

## Login flow

1. 00_auth/login.bru is executed first
2. Login request is sent to AUTH_URL
3. Token is read from response JSON field `token`
4. Token is stored to variable ACCESS_TOKEN
5. All subsequent requests use:

       Authorization: Bearer {{ACCESS_TOKEN}}

## Test order

Tests are executed in folder order:

    00_auth   -> login
    01_smoke  -> health checks
    02_*      -> actual API tests
    90_*      -> error tests

## Adding new API tests

Place new tests under:

    api-tests/02_<area>/

Example:

    api-tests/02_users/
    api-tests/03_roles/
    api-tests/04_permissions/

## Example authorized request header

    Authorization: Bearer {{ACCESS_TOKEN}}

## Notes

- Do not store real passwords in repository. Use environment-specific files.
- ACCESS_TOKEN is always generated during test run.
- Identity service is used for authentication for all Winglet services.
