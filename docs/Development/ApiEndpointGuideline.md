# API Endpoint Naming Guidelines

This document defines the official conventions for naming HTTP API endpoints within the framework. The goal is to ensure consistency, predictability, and long-term maintainability across all applications built on top of the framework.

---

# 1. Core Principles

All API endpoints should follow these foundational principles:

- **Use nouns, not verbs** in URL paths
- **Use HTTP methods to express actions**
- **Use plural resource names** for collections
- **Use path parameters for identity**
- **Use query parameters for filtering and searching**
- **Keep URLs lowercase and hyphenated**

Correct API design should feel predictable without documentation.

---

# 2. Resource Naming

## 2.1 Use Plural Nouns

Collections must use plural names:

```
/customers
/orders
/products
```

Individual resources are identified via path parameters:

```
/customers/{customerId}
/orders/{orderId}
```

Avoid singular base paths like:

```
/customer
/order
```

---

# 3. HTTP Method Conventions (CRUD)

Use HTTP verbs to express operations instead of encoding actions into the path.

| Operation            | Method  | Endpoint                    |
|----------------------|---------|-----------------------------|
| List resources       | GET     | /customers                  |
| Get single resource  | GET     | /customers/{id}             |
| Create resource      | POST    | /customers                  |
| Replace resource     | PUT     | /customers/{id}             |
| Partial update       | PATCH   | /customers/{id}             |
| Delete resource      | DELETE  | /customers/{id}             |

Avoid patterns like:

```
/customers/getCustomer
/customers/listCustomers
/customer/add
/customers/{id}/edit
```

---

# 4. Filtering, Pagination & Search

## 4.1 Simple Filtering (Preferred)

Filtering should be implemented using query parameters:

```
GET /customers?status=active
GET /customers?email=aki@example.com
GET /customers?country=FI&page=2&pageSize=50
```

Search is usually just filtered listing:

```
GET /customers?search=aki
```

## 4.2 Complex Search

If search requires complex filters, sorting, or scoring logic, a dedicated endpoint is acceptable:

```
POST /customers/search
```

With JSON body:

```json
{
  "query": "aki",
  "filters": {
    "status": "active"
  },
  "sort": ["lastName"]
}
```

---

# 5. Sub-Resources & Relationships

Use nested routes for resource relationships:

```
GET  /customers/{id}/addresses
POST /customers/{id}/addresses
GET  /customers/{id}/orders
```

This clearly expresses ownership and hierarchy.

Avoid deeply nested routes beyond 2–3 levels.

---

# 6. Actions That Are Not Pure CRUD

If an operation represents a domain command rather than a simple state update, a verb-based sub-resource is acceptable.

Examples:

```
POST /customers/{id}/deactivate
POST /customers/{id}/send-verification-email
POST /orders/{id}/cancel
```

However, if the operation can be modeled as a state change, prefer PATCH:

```
PATCH /customers/{id}
{
  "status": "inactive"
}
```

---

# 7. URL Formatting Rules

- Use **lowercase letters only**
- Use **hyphens** instead of camelCase
- Do not use underscores

Correct:

```
/customers
/billing-address
/order-items
```

Avoid:

```
/getCustomers
/customerList
/order_items
```

---

# 8. API Versioning

APIs should be versioned to allow future breaking changes.

Recommended approach:

```
/v1/customers
/v1/orders
```

Version should appear immediately after the domain.

---

# 9. HTTP Status Code Guidelines

Follow standard HTTP semantics:

| Scenario              | Status Code |
|-----------------------|------------|
| Successful GET        | 200 OK     |
| Successful POST       | 201 Created|
| Successful DELETE     | 204 No Content |
| Invalid input         | 400 Bad Request |
| Unauthorized          | 401 Unauthorized |
| Forbidden             | 403 Forbidden |
| Not found             | 404 Not Found |
| Validation error      | 422 Unprocessable Entity |

---

# 10. Consistency Rules

When designing new endpoints:

- Follow existing resource patterns
- Do not mix singular and plural naming
- Do not mix RPC-style and REST-style conventions
- Keep naming aligned across all modules and plugins

Consistency across the entire ecosystem is more important than theoretical perfection.

---

# 11. Example: Customer API Overview

```
GET    /v1/customers
GET    /v1/customers/{id}
POST   /v1/customers
PATCH  /v1/customers/{id}
DELETE /v1/customers/{id}
GET    /v1/customers/{id}/orders
POST   /v1/customers/search
```

This structure is considered the baseline standard for framework-based applications.

---

# Final Rule

If unsure:

> Model your API around resources (nouns) and let HTTP methods describe the action.

This ensures clarity, scalability, and long-term maintainability.

