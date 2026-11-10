<?php
declare(strict_types=1);

namespace App\Controllers;

use Winglet\Core\Auth\AuthContext;
use Winglet\Core\Http\Controller\AbstractController;
use Winglet\Core\Http\Request;
use Winglet\Core\Http\Routing\Attributes\Route;
use Winglet\Core\Security\AccessControl\ControllerAccessRulesInterface;
use Winglet\Core\Security\AccessControl\DefaultAccessRulesTrait;

final class AdminController extends AbstractController implements ControllerAccessRulesInterface
{
    use DefaultAccessRulesTrait;

    public function getRequiredSessionLevel(): ?int { return 20; }

    public function getRequiredGroups(): array { return ['admin', 'ops']; }

    public function customAccessRule(): bool
    {
        // optional extra checks, e.g. maintenance mode, allowlist, etc.
        return true;
    }

    #[Route('/customer/{id}', methods: ['GET'])]
    public function handlePost(Request $request, int $id): array
    {
        return ['id' => $id];
    }

    public function handleGet(Request $request, int $id): array
    {
        return ['id' => $id];
    }

    public function index(Request $request): array
    {
        /** @var AuthContext $auth */
        $auth = $request->getAttribute('auth');

        /*
         * Use of entity manager
         * @var \Winglet\Core\Kernel\Bootstrap\Application $app
         * $app = $request->getAttribute('app');
         * $em = $app->doctrine->getEntityManager(); // Doctrine initializes HERE (first use)
         */

        /** @var \Winglet\Core\Kernel\Bootstrap\Application $app
         * $app = $request->getAttribute('app');
         *
         * // Transaction helper:
         * $userId = $app->doctrine->transactional(function ($em) use ($dto) {
         *    $user = new User(...);
         *    $em->persist($user);
         *    // flush happens inside transaction helper (fallback path) or inside wrapInTransaction
         *    return $user->getId();
         * });
         */

        /**
         *  $app = $request->getAttribute('app');
         *  $row = $app->db->fetchOne(
         *     "SELECT id, name FROM users WHERE id = :id",
         *     [':id' => 123]
         * );
         */

        /*
         * Käyttöesimerkki controllerissa
    use Winglet\Core\Http\Input\Input;
    use Winglet\Core\Http\Input\Validators;
    use Winglet\Core\Http\Input\Requirements;

        $in = Input::from($request)
            ->required('id', 'int')
            ->optional('email', 'string', ['validators' => [Validators::email()]])
            ->optional('avatar', 'file', ['validators' => [
                Validators::fileMaxSize(2_000_000),
                Validators::fileMime(['image/jpeg','image/png']),
            ]])
            ->requireRule(
                Requirements::allOf(
                    Requirements::var('id'),
                    Requirements::anyOf(
                        Requirements::var('email'),
                        Requirements::allOf(Requirements::var('first'), Requirements::var('last'))
                    )
                )
            )
            ->validate();

        $id = $in->get('id');         // int
        $email = $in->get('email');   // string|null
        $avatar = $in->get('avatar'); // UploadedFile|null
*/

        return [
            'clientId' => $auth->clientId,
            'sessionLevel' => $auth->sessionLevel,
            'groups' => $auth->groups,
        ];
    }
}