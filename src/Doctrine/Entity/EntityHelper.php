<?php
declare(strict_types=1);

namespace Winglet\Core\Doctrine\Entity;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Winglet\Core\Http\Exception\HttpException;

/**
 * EntityHelper
 *
 * Generic Doctrine entity management helpers for Winglet.
 *
 * Rationale:
 * - Centralize common “object or id -> entity” resolving
 * - Provide consistent HTTP-friendly exceptions (400/404/409/403)
 * - Provide reusable order-id list manipulation utilities (move/normalize/insert/remove)
 * - Keep DB-backed logic out of entity classes
 */
final class EntityHelper
{
    private function __construct() {}

    /**
     * Resolve an entity parameter that may be:
     * - a real entity instance OR
     * - an entity identifier (int|string)
     *
     * Use when service/controller accepts “entity or id” and you want to:
     * - avoid duplicate find() calls if entity already provided
     * - validate type if object was given
     * - throw a consistent 404 if id not found
     *
     * @template T of object
     * @param class-string<T> $class
     * @param object|int|string $item
     * @param string $humanName Used in error messages ("customer", "order item", etc.)
     * @return T
     */
    public static function resolve(
        EntityManagerInterface $em,
        string $class,
        object|int|string $item,
        string $humanName = 'item',
    ): object {
        $typeName = ucfirst($humanName);

        if (is_object($item)) {
            if ($item instanceof $class) {
                return $item;
            }
            throw new HttpException(
                400,
                sprintf('%s is invalid type (expected %s, got %s)', $typeName, $class, $item::class),
            );
        }

        $id = self::normalizeId($item);
        $entity = $em->find($class, $id);

        if ($entity instanceof $class) {
            return $entity;
        }

        throw new HttpException(404, sprintf('%s %s not found', $typeName, (string)$item));
    }

    /**
     * Require an entity by id (shortcut wrapper around EntityManager::find()).
     * Throws 404 when not found.
     *
     * Use when you always have an id (not object) and want consistent error handling.
     *
     * @template T of object
     * @param class-string<T> $class
     * @param int|string $id
     * @param string $humanName
     * @return T
     */
    public static function requireById(
        EntityManagerInterface $em,
        string $class,
        int|string $id,
        string $humanName = 'item',
    ): object {
        return self::resolve($em, $class, $id, $humanName);
    }

    /**
     * Require exactly one entity by criteria using repository->findOneBy().
     * Throws 404 when not found.
     *
     * Use when lookup key is not the primary id (e.g. slug, externalId).
     *
     * @template T of object
     * @param class-string<T> $class
     * @param array<string, mixed> $criteria
     * @param string $humanName
     * @return T
     */
    public static function requireOneBy(
        EntityManagerInterface $em,
        string $class,
        array $criteria,
        string $humanName = 'item',
    ): object {
        /** @var EntityRepository<T> $repo */
        $repo = $em->getRepository($class);

        $entity = $repo->findOneBy($criteria);
        /** @var T|null $entity */

        if ($entity instanceof $class) {
            return $entity;
        }

        throw new HttpException(404, sprintf('%s not found', ucfirst($humanName)));
    }

    /**
     * Get a reference (proxy) or do a find() depending on whether validation is required.
     *
     * - If $validateExists=true (default): uses find() and throws 404 if not found.
     * - If $validateExists=false: uses getReference() (no immediate DB hit).
     *
     * Use when assigning relations:
     * - validate=true: user input should be verified
     * - validate=false: internal code already trusts the id and wants performance
     *
     * @template T of object
     * @param class-string<T> $class
     * @param int|string $id
     * @param bool $validateExists
     * @param string $humanName
     * @return T
     */
    public static function referenceOrFind(
        EntityManagerInterface $em,
        string $class,
        int|string $id,
        bool $validateExists = true,
        string $humanName = 'item',
    ): object {
        if ($validateExists) {
            return self::requireById($em, $class, $id, $humanName);
        }

        $ref = $em->getReference($class, $id);
        if ($ref instanceof $class) {
            return $ref;
        }

        // getReference should still match
        throw new HttpException(500, 'Invalid entity reference', [
            'class' => $class,
            'id' => $id,
        ]);
    }

    /**
     * Flush EM (safe wrapper for readability).
     */
    public static function flush(EntityManagerInterface $em): void
    {
        $em->flush();
    }

    /**
     * Persist and flush entity.
     *
     * Use to reduce boilerplate in services.
     */
    public static function persistAndFlush(EntityManagerInterface $em, object $entity): void
    {
        $em->persist($entity);
        $em->flush();
    }

    /**
     * Remove and flush an entity.
     *
     * Use to reduce boilerplate in services.
     */
    public static function removeAndFlush(EntityManagerInterface $em, object $entity): void
    {
        $em->remove($entity);
        $em->flush();
    }

    /**
     * Simple pagination helper for QueryBuilder.
     *
     * Returns:
     * - items: array
     * - page, perPage, total, pages
     *
     * Use when returning paginated lists in API.
     *
     * @return array{items: list<object>, page:int, perPage:int, total:int, pages:int}
     */
    public static function paginate(QueryBuilder $qb, int $page = 1, int $perPage = 25): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(200, $perPage));

        $first = ($page - 1) * $perPage;

        // Clone for count query
        $countQb = clone $qb;
        $rootAliases = $countQb->getRootAliases();
        $alias = $rootAliases[0] ?? 'e';

        $countQb->resetDQLPart('orderBy');
        $countQb->select("COUNT($alias)");

        $total = (int)$countQb->getQuery()->getSingleScalarResult();
        $pages = (int)max(1, (int)ceil($total / $perPage));

        /** @var list<object> $items */
        $items = $qb
            ->setFirstResult($first)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return [
            'items' => $items,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'pages' => $pages,
        ];
    }

    private static function normalizeId(int|string $id): int|string
    {
        if (is_int($id)) return $id;

        $s = trim($id);
        if ($s === '') {
            throw new HttpException(400, 'Invalid identifier');
        }

        if (preg_match('/^-?\d+$/', $s)) {
            return (int)$s;
        }

        return $s;
    }
}