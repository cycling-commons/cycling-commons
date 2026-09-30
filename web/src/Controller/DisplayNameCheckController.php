<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Account\DisplayNameCheck;
use App\Entity\User;
use App\Security\FormGuard;
use App\Security\PseudonymousKey;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The display-name hint on the sign-up form and in settings: does another
 * account already use this name? A hint, never a block.
 *
 * Every answer has one shape, `{"inUse": true|false|null}`: null when the
 * request gets no answer (no live sign-up stamp for an anonymous caller, a
 * name the forms would refuse by length, or the per-connection limit).
 *
 * Under `/register` so the access_control rule that opens the sign-up pages
 * opens this too. An anonymous caller must carry the sign-up form's signed
 * timer (FormGuard::STAMP); a signed-in rider needs none, and their own
 * account never counts against them.
 *
 * @see docs/specs/account-and-auth.md §9
 *
 * @api
 */
final class DisplayNameCheckController extends AbstractController
{
    public function __construct(
        private readonly DisplayNameCheck $check,
        private readonly FormGuard $guard,
    ) {
    }

    #[Route('/register/name-check', name: 'display_name_check', methods: ['POST'])]
    public function __invoke(
        Request $request,
        RateLimiterFactoryInterface $displayNameCheckLimiter,
        #[Autowire('%kernel.secret%')]
        string $secret,
    ): JsonResponse {
        $user = $this->getUser();

        if (!$user instanceof User
            && !$this->guard->stampIsLive($request->request->getString(FormGuard::STAMP), new \DateTimeImmutable())) {
            return self::answer(null, Response::HTTP_FORBIDDEN);
        }

        $name = $request->request->getString('name');
        if (!DisplayNameCheck::askable($name)) {
            return self::answer(null, Response::HTTP_OK);
        }

        $key = PseudonymousKey::limiter('display_name_check', $request->getClientIp() ?? 'unknown', $secret);
        if (!$displayNameCheckLimiter->create($key)->consume()->isAccepted()) {
            return self::answer(null, Response::HTTP_TOO_MANY_REQUESTS);
        }

        return self::answer($this->check->inUse($name, $user instanceof User ? $user->getId() : null), Response::HTTP_OK);
    }

    private static function answer(?bool $inUse, int $status): JsonResponse
    {
        $response = new JsonResponse(['inUse' => $inUse], $status);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
