<?php
declare(strict_types=1);

namespace App\Controllers;

use Winglet\Core\Http\Request;
use Winglet\Core\Http\Controller\AbstractController;
use Winglet\Core\Kernel\Bootstrap\Application;

final class UserListController extends AbstractController
{
    public function index(Request $request): array
    {
        /**
         * Notes / gotchas (so you don’t forget later)
         *
         * Always use allowOrderBy([...]) before orderBy($sort, $dir) if $sort comes from request.
         *
         * Use eq()/in()/like() helpers when possible; they bind params for you.
         *
         * count() clones the builder and removes LIMIT/OFFSET/ORDER BY.
         *
         * If you want, I can also add insert/update/delete builders (e.g., $db->insertInto('table')->values([...])->execute()) using the same style.
         */

        /** @var Application $app */
        $app = $request->getAttribute('app');

        $q = (string)($request->query['q'] ?? '');
        $status = $request->query['status'] ?? null;
        $group = $request->query['group'] ?? null;

        $sort = (string)($request->query['sort'] ?? 'u.id');
        $dir  = (string)($request->query['dir'] ?? 'DESC');

        $page = (int)($request->query['page'] ?? 1);
        $perPage = (int)($request->query['perPage'] ?? 25);

        $qb = $app->db->query()
            ->select(['u.id', 'u.name', 'u.email', 'u.status', 'u.created_at'])
            ->from('users', 'u')
            ->allowOrderBy(['u.id', 'u.created_at', 'u.name', 'u.status'])
            ->orderBy($sort, $dir)
            ->page($page, $perPage);

        // Free-text search example
        if ($q !== '') {
            $qb->andWhere('(u.name LIKE :q OR u.email LIKE :q)', ['q' => '%' . $q . '%']);
        }

        // Optional status filter
        if ($status !== null && $status !== '') {
            $qb->eq('u.status', $status);
        }

        // Optional "group" filter using IN() (could be 1 or many)
        if ($group !== null && $group !== '') {
            $groups = is_array($group) ? $group : [$group];
            $qb->in('u.group_code', $groups);
        }

        // Total count for pagination (runs COUNT(*) query with same WHERE)
        $total = $qb->count();

        // Data page
        $rows = $qb->fetchAll();

        return [
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'items' => $rows,
        ];
    }
}
