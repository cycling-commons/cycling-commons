<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Moderation\UnsentStatements;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Unsent statements: account statements of reasons whose email did not go
 * out, each kept whole, with Send again and Discard
 * (docs/specs/account-and-auth.md §6.8).
 *
 * @api
 */
#[IsGranted('ROLE_ADMIN')]
final class UnsentStatementController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'admin-unsent-statement';

    public function __construct(
        private readonly UnsentStatements $unsent,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[AdminRoute('/unsent-statements', 'unsent_statements', options: ['methods' => ['GET', 'POST']])]
    public function index(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }
            /** @var User $admin */
            $admin = $this->getUser();
            $id = $request->request->getInt('id');

            try {
                if ('discard' === $request->request->getString('op')) {
                    $this->unsent->discard($id, $admin);
                    $this->addFlash('success', $this->translator->trans('account_suspension.unsent.flash_discarded'));
                } elseif ($this->unsent->resend($id, $admin)) {
                    $this->addFlash('success', $this->translator->trans('account_suspension.unsent.flash_resent'));
                } else {
                    $this->addFlash('danger', $this->translator->trans('account_suspension.unsent.flash_failed'));
                }
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('warning', $this->translator->trans($e->getMessage()));
            }

            return $this->redirectToRoute('admin_unsent_statements');
        }

        return $this->render('admin/unsent_statements.html.twig', [
            'rows' => $this->unsent->all(),
            'csrf_token_id' => self::CSRF_TOKEN_ID,
        ]);
    }
}
