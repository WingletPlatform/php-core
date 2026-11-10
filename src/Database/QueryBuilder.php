<?php
declare(strict_types=1);

namespace Winglet\Core\Database;

/**
 * Small QueryBuilder-like helper for common REST query patterns.
 *
 * Goals:
 * - Safe WHERE building with bound parameters (no manual escaping).
 * - Safe ORDER BY via allow-list.
 * - Pagination helpers (limit/offset/page).
 * - Simple SELECT queries (can be extended later).
 *
 * Created by:
 * - DatabaseManager::query() (recommended)
 * Used by:
 * - Controllers/services for direct SQL reads.
 */
final class QueryBuilder
{
    private array $select = ['*'];
    private ?string $from = null;
    private ?string $fromAlias = null;

    /** @var string[] */
    private array $joins = [];

    /** @var string[] */
    private array $where = [];

    /** @var array<string,mixed> */
    private array $params = [];

    /** @var string[] */
    private array $orderBy = [];

    private ?int $limit = null;
    private ?int $offset = null;

    /** @var string[]|null */
    private ?array $orderAllowList = null;

    private int $paramCounter = 0;

    public function __construct(private readonly DatabaseManager $db) {}

    /** @param string[] $columns */
    public function select(array $columns): self
    {
        $this->select = $columns ?: ['*'];
        return $this;
    }

    public function from(string $table, ?string $alias = null): self
    {
        $this->from = $table;
        $this->fromAlias = $alias;
        return $this;
    }

    public function join(string $sqlFragment): self
    {
        // e.g. "JOIN users u ON u.id = t.user_id"
        $this->joins[] = $sqlFragment;
        return $this;
    }

    public function where(string $expr, array $params = []): self
    {
        $this->where = [$expr];
        $this->bindAll($params);
        return $this;
    }

    public function andWhere(string $expr, array $params = []): self
    {
        $this->where[] = $expr;
        $this->bindAll($params);
        return $this;
    }

    public function orWhere(string $expr, array $params = []): self
    {
        if (!$this->where) {
            $this->where[] = $expr;
        } else {
            $last = array_pop($this->where);
            $this->where[] = '(' . $last . ' OR ' . $expr . ')';
        }
        $this->bindAll($params);
        return $this;
    }

    public function eq(string $field, mixed $value): self
    {
        $p = $this->nextParamName($field);
        return $this->andWhere("{$field} = :{$p}", [$p => $value]);
    }

    public function neq(string $field, mixed $value): self
    {
        $p = $this->nextParamName($field);
        return $this->andWhere("{$field} <> :{$p}", [$p => $value]);
    }

    public function like(string $field, string $pattern): self
    {
        $p = $this->nextParamName($field);
        return $this->andWhere("{$field} LIKE :{$p}", [$p => $pattern]);
    }

    /**
     * Adds "field IN (:p0,:p1,...)" safely.
     * If $values is empty, adds a false condition (1=0) to return no rows.
     */
    public function in(string $field, array $values): self
    {
        if ($values === []) {
            return $this->andWhere('1=0');
        }

        $placeholders = [];
        foreach (array_values($values) as $v) {
            $p = $this->nextParamName($field);
            $placeholders[] = ':' . $p;
            $this->params[$p] = $v;
        }

        $ph = implode(',', $placeholders);
        return $this->andWhere("{$field} IN ({$ph})");
    }

    public function between(string $field, mixed $from, mixed $to): self
    {
        $p1 = $this->nextParamName($field . '_from');
        $p2 = $this->nextParamName($field . '_to');
        return $this->andWhere(
            "{$field} BETWEEN :{$p1} AND :{$p2}",
            [$p1 => $from, $p2 => $to]
        );
    }

    /**
     * Safe ORDER BY using allow-list.
     *
     * @param string[] $allowed Example: ['id', 'created_at', 'name'] or ['u.id','u.name']
     */
    public function allowOrderBy(array $allowed): self
    {
        $this->orderAllowList = $allowed;
        return $this;
    }

    public function orderBy(string $field, string $direction = 'ASC'): self
    {
        $dir = strtoupper($direction);
        if (!in_array($dir, ['ASC', 'DESC'], true)) {
            $dir = 'ASC';
        }

        if ($this->orderAllowList !== null && !in_array($field, $this->orderAllowList, true)) {
            // ignore unsafe/unknown sort field
            return $this;
        }

        $this->orderBy[] = "{$field} {$dir}";
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = max(0, $limit);
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = max(0, $offset);
        return $this;
    }

    /**
     * Page helper (1-based).
     */
    public function page(int $page, int $perPage): self
    {
        $perPage = max(1, min(500, $perPage));
        $page = max(1, $page);
        $this->limit($perPage);
        $this->offset(($page - 1) * $perPage);
        return $this;
    }

    /** @return array<int,array<string,mixed>> */
    public function fetchAll(): array
    {
        [$sql, $params] = $this->toSql();
        return $this->db->fetchAll($sql, $this->withColonParams($params));
    }

    /** @return array<string,mixed>|null */
    public function fetchOne(): ?array
    {
        // Ensure single-row fetch
        if ($this->limit === null) {
            $this->limit(1);
        }
        [$sql, $params] = $this->toSql();
        return $this->db->fetchOne($sql, $this->withColonParams($params));
    }

    public function count(string $as = 'cnt'): int
    {
        $clone = clone $this;
        $clone->select(["COUNT(*) AS {$as}"]);
        $clone->orderBy = [];
        $clone->limit = null;
        $clone->offset = null;

        $row = $clone->fetchOne();
        if (!$row) return 0;
        return (int)($row[$as] ?? 0);
    }

    /**
     * Returns SQL + params (without leading colons in param keys).
     *
     * @return array{0:string,1:array<string,mixed>}
     */
    public function toSql(): array
    {
        if ($this->from === null) {
            throw new \RuntimeException('QueryBuilder: missing FROM()');
        }

        $sql = 'SELECT ' . implode(', ', $this->select)
            . ' FROM ' . $this->from;

        if ($this->fromAlias) {
            $sql .= ' ' . $this->fromAlias;
        }

        if ($this->joins) {
            $sql .= ' ' . implode(' ', $this->joins);
        }

        if ($this->where) {
            $sql .= ' WHERE ' . implode(' AND ', $this->where);
        }

        if ($this->orderBy) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orderBy);
        }

        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . (int)$this->limit;
        }

        if ($this->offset !== null) {
            $sql .= ' OFFSET ' . (int)$this->offset;
        }

        return [$sql, $this->params];
    }

    // -------- internals --------

    private function bindAll(array $params): void
    {
        foreach ($params as $k => $v) {
            $this->params[(string)$k] = $v;
        }
    }

    private function nextParamName(string $hint): string
    {
        $this->paramCounter++;
        $clean = preg_replace('/[^a-zA-Z0-9_]/', '_', $hint) ?: 'p';
        return $clean . '_' . $this->paramCounter;
    }

    /** PDO expects keys like ':name' for named params; we store without ':' */
    private function withColonParams(array $params): array
    {
        $out = [];
        foreach ($params as $k => $v) {
            $out[':' . ltrim((string)$k, ':')] = $v;
        }
        return $out;
    }
}