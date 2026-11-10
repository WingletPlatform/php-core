# EntityHelper

**Namespace:** `Winglet\Core\Doctrine\Entity`  
**File:** `src/Doctrine/Entity/EntityHelper.php`

**NOTE:** This md needs to be formatted better.

---

## Overview

`EntityHelper` is a collection of static utility methods for managing Doctrine entities in a consistent and framework-friendly way.

It centralizes common patterns such as:

- Resolving `object | id` into a validated entity
- Consistent HTTP-friendly exception handling (400 / 403 / 404)
- Order index management within grouped entities
- Pagination helpers
- Soft-delete validation
- Boilerplate persistence shortcuts

This keeps services and controllers clean while maintaining consistent behavior across the application.

---

# 1. Entity Resolving

## resolve()

```php
resolve(EntityManagerInterface $em, string $class, object|int|string $item, string $humanName = 'item'): object
```

### Purpose

Accepts either:

A real entity instance or an entity identifier (int|string)

Always returns a valid entity instance or throws:
* 400 Bad Request if object type is invalid
* 404 Not Found if entity with given id does not exist

### Example
```php
$customer = EntityHelper::resolve($em, Customer::class, $customerOrId, 'customer');
```

## requireById()
requireById(EntityManagerInterface $em, string $class, int|string $id, string $humanName = 'item'): object


Shortcut for resolving by id only.

Throws 404 if not found.

## requireOneBy()
requireOneBy(EntityManagerInterface $em, string $class, array $criteria, string $humanName = 'item'): object


Finds entity using findOneBy() and throws 404 if not found.

Useful for slug-based or external-id lookups.

## referenceOrFind()
referenceOrFind(EntityManagerInterface $em, string $class, int|string $id, bool $validateExists = true, string $humanName = 'item'): object


If $validateExists = true → uses find() (safe, validates existence)

If false → uses getReference() (no immediate DB hit)

Use false when assigning relations internally and performance matters.

# 2. Group & Ownership Validation
## assertBelongsToGroup()
assertBelongsToGroup(object $entity, string $groupField, mixed $expectedGroupValue, string $humanName = 'item'): void


Ensures that an entity belongs to a specific group/parent.

Throws 403 Forbidden if mismatch.

Example
EntityHelper::assertBelongsToGroup($task, 'project', $project);

## requireNotDeleted()
requireNotDeleted(object $entity, string $deletedAtField = 'deletedAt'): void


Checks soft-delete state using convention:

If property deletedAt exists and is not null → treated as deleted.

Throws 404 if soft-deleted.

# 3. Persistence Shortcuts
## persistAndFlush()
persistAndFlush(EntityManagerInterface $em, object $entity): void


Shortcut for:

$em->persist($entity);
$em->flush();

removeAndFlush()
removeAndFlush(EntityManagerInterface $em, object $entity): void


Shortcut for removing and flushing an entity.

# 4. Pagination
## paginate()
paginate(QueryBuilder $qb, int $page = 1, int $perPage = 25): array


Returns:

[
  'items' => [...],
  'page' => int,
  'perPage' => int,
  'total' => int,
  'pages' => int
]


Automatically clones the QueryBuilder for count query.

Example
$result = EntityHelper::paginate($qb, 2, 50);

# 5. Order ID Management

Designed for entities that:

Belong to a group (e.g. same parent)

Have an integer order field (default: orderId)

These methods assume ordering is group-based.

## normalizeOrder()
normalizeOrder($em, $class, $groupField, $groupValue, $orderField = 'orderId'): int


Normalizes order values to contiguous sequence starting from 0.

Example:

1, 3, 5 → 0, 1, 2


Returns number of updated rows.

## moveUp()
moveUp($em, $class, $item, $groupField, $groupValue, $orderField = 'orderId')


Moves entity one step up (orderId - 1).

No-op if already at top.

## moveDown()
moveDown($em, $class, $item, $groupField, $groupValue, $orderField = 'orderId')


Moves entity one step down (orderId + 1).

## moveToPosition()
moveToPosition($em, $class, $item, $groupField, $groupValue, int $newIndex, $orderField = 'orderId')


Moves entity to exact index.

Useful for drag-and-drop UI reorder.

## insertAtPosition()
insertAtPosition($em, $class, $item, $groupField, $groupValue, int $index, $orderField = 'orderId')


Inserts entity into ordered group at specific index.

## removeAndCloseGap()
removeAndCloseGap($em, $class, $item, $groupField, $groupValue, bool $removeEntity = true, $orderField = 'orderId')


Removes entity from ordered group and closes order gap.

If $removeEntity = true, entity is deleted as well.

Recommended Database Constraint

For order management, it is strongly recommended to add:

UNIQUE (groupField, orderField)


This prevents duplicate order positions and ensures integrity.

When To Use EntityHelper

Use EntityHelper when:

Accepting flexible parameters (object or id)

Implementing reorderable lists

Enforcing parent-child integrity

Handling soft delete consistently

Reducing repeated persistence boilerplate

Building consistent paginated endpoints

Do not use it for:

Complex domain logic (belongs in service layer)

Heavy business rules (belongs in domain layer)

Custom query logic (belongs in repository)

Design Philosophy

EntityHelper is intentionally:

Stateless

Static

Doctrine-focused

HTTP-aware (throws consistent exceptions)

Group-order aware

It centralizes repetitive infrastructure patterns without polluting entity classes.