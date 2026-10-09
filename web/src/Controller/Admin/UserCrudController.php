<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Catalog\Entity\Region;
use App\Entity\User;
use App\Moderation\Entity\ModeratorArea;
use App\Moderation\ModerationScopeProvider;
use App\Moderation\StatementGround;
use App\Routing\Languages;
use App\Service\GuardrailViolationException;
use App\Service\UserAdminService;
use App\World\Entity\Country;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * User CRUD + support-desk actions. Never exposes password, totpSecret, backupCodes.
 *
 * @see docs/specs/account-and-auth.md §6.6
 *
 * @api
 *
 * @extends AbstractCrudController<User>
 */
#[IsGranted('ROLE_ADMIN')]
final class UserCrudController extends AbstractCrudController
{
    use RiderDatedFields;

    /** CSRF token id shared by the action form template and {@see run()}. */
    public const string CSRF_TOKEN_ID = 'ea-user-support';

    public function __construct(
        private readonly UserAdminService $svc,
        private readonly AdminUrlGenerator $urls,
        private readonly TranslatorInterface $translator,
        private readonly ModerationScopeProvider $scopeProvider,
        private readonly Languages $languages,
    ) {
    }

    #[\Override]
    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('User')
            ->setEntityLabelInPlural('Users')
            ->setSearchFields(['email', 'displayName'])
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    #[\Override]
    public function configureFilters(\EasyCorp\Bundle\EasyAdminBundle\Config\Filters $filters): \EasyCorp\Bundle\EasyAdminBundle\Config\Filters
    {
        return $filters
            ->add(BooleanFilter::new('emailVerified', 'Email verified'))
            ->add(BooleanFilter::new('publicProfile', 'Public profile'))
            ->add(EntityFilter::new('country'))
            // The languages this deployment serves. A rider whose stored
            // language is no longer served is not offered as a filter; the
            // site already treats that preference as unset.
            ->add(ChoiceFilter::new('locale')->setChoices(array_flip($this->languages->options())))
            ->add(DateTimeFilter::new('createdAt', 'Registered'));
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        // The row's primary key, on every admin list (owner 2026-10-04).
        yield IdField::new('id', 'ID')->hideOnForm();
        yield EmailField::new('email');
        yield TextField::new('displayName', 'Display Name');
        // docs/specs/account-and-auth.md §6.1 — roles/verified/lockout only via audited actions.
        yield ChoiceField::new('roles')
            ->setChoices(['Curator' => 'ROLE_CURATOR', 'Admin' => 'ROLE_ADMIN'])
            ->allowMultipleChoices()
            ->renderExpanded(false)
            ->hideOnForm();
        yield BooleanField::new('emailVerified', 'Email Verified')->hideOnForm();
        yield BooleanField::new('twoFaEnabled', '2FA Enabled')->hideOnForm();
        yield $this->riderDateTime('lockedUntil', 'Locked Until')->setRequired(false)->hideOnForm();
        // account-and-auth.md §6.8: changed only through Suspend and Lift suspension.
        yield $this->riderDateTime('suspendedUntil', $this->translator->trans('account_suspension.admin.field_suspended_until'))->setRequired(false)->hideOnForm();
        yield BooleanField::new('publicProfile', 'Public Profile');
        yield $this->riderDateTime('createdAt', 'Registered')->hideOnForm();
        yield TextField::new('email', $this->t('admin.field.mod_areas'))
            ->onlyOnDetail()
            // A rider who is no curator moderates nothing; only a curator or an
            // admin without areas moderates everywhere (moderation-and-contribution.md §9.2).
            ->formatValue(fn ($v, User $u): string => [] === array_intersect(['ROLE_CURATOR', 'ROLE_ADMIN'], $u->getRoles())
                ? $this->translator->trans('admin.field.mod_areas_none')
                : (implode(' · ', $this->scopeProvider->describe($u)) ?: $this->translator->trans('account.mod_scope_all')));
    }

    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        // docs/specs/account-and-auth.md §6.1 — POST + CSRF; never a GET link.
        $mk = fn (string $name, string $label, string $icon, bool $confirm, callable $when): Action => Action::new($name, $this->t($label), $icon)
            ->linkToCrudAction($name)
            ->displayIf($when)
            ->setTemplatePath('admin/user_support_action.html.twig')
            ->addCssClass($confirm ? 'action-confirm' : '');

        $actions->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE);

        $actions
            ->add(Crud::PAGE_DETAIL, $mk(UserAdminService::UNLOCK, 'admin.action.unlock', 'fa fa-unlock', false, static fn (User $u) => $u->isLocked()))
            ->add(Crud::PAGE_DETAIL, $mk(UserAdminService::DISARM_2FA, 'admin.action.disarm_2fa', 'fa fa-shield-halved', true, static fn (User $u) => $u->isTwoFaEnabled()))
            ->add(Crud::PAGE_DETAIL, $mk(UserAdminService::VERIFY_EMAIL, 'admin.action.verify_email', 'fa fa-envelope-circle-check', false, static fn (User $u) => !$u->isEmailVerified()))
            ->add(Crud::PAGE_DETAIL, $mk(UserAdminService::UNVERIFY_EMAIL, 'admin.action.unverify_email', 'fa fa-envelope', true, static fn (User $u) => $u->isEmailVerified()))
            ->add(Crud::PAGE_DETAIL, Action::new(UserAdminService::GRANT_CURATOR, $this->t('admin.action.grant_curator'), 'fa fa-user-shield')
                ->linkToCrudAction(UserAdminService::GRANT_CURATOR)
                ->displayIf(fn (User $u) => !$this->svc->hasRole($u, 'ROLE_CURATOR')))
            ->add(Crud::PAGE_DETAIL, $mk(UserAdminService::REVOKE_CURATOR, 'admin.action.revoke_curator', 'fa fa-user', true, fn (User $u) => $this->svc->hasRole($u, 'ROLE_CURATOR')))
            ->add(Crud::PAGE_DETAIL, $mk(UserAdminService::GRANT_ADMIN, 'admin.action.grant_admin', 'fa fa-user-gear', true, fn (User $u) => !$this->svc->hasRole($u, 'ROLE_ADMIN')))
            ->add(Crud::PAGE_DETAIL, $mk(UserAdminService::REVOKE_ADMIN, 'admin.action.revoke_admin', 'fa fa-user-minus', true, fn (User $u) => $this->svc->hasRole($u, 'ROLE_ADMIN')))
            ->add(Crud::PAGE_DETAIL, $mk(UserAdminService::REMOVE_ACCOUNT, 'admin.action.remove_account', 'fa fa-trash', true, static fn (User $u) => null !== $u->getDeletionRequestedAt()))
            ->add(Crud::PAGE_DETAIL, $mk(UserAdminService::CANCEL_REMOVAL, 'admin.action.cancel_removal', 'fa fa-rotate-left', false, static fn (User $u) => null !== $u->getDeletionRequestedAt()))
            // A suspension or a removal for a breach owes its holder a
            // statement of reasons, so each opens a form for the ground and
            // the facts (account-and-auth.md §6.8).
            ->add(Crud::PAGE_DETAIL, Action::new(UserAdminService::SUSPEND, $this->t('account_suspension.admin.action_suspend'), 'fa fa-user-clock')
                ->linkToCrudAction(UserAdminService::SUSPEND)
                ->displayIf(static fn (User $u) => !$u->isSuspendedAt(new \DateTimeImmutable())))
            ->add(Crud::PAGE_DETAIL, $mk(UserAdminService::LIFT_SUSPENSION, 'account_suspension.admin.action_lift', 'fa fa-user-check', false, static fn (User $u) => $u->isSuspendedAt(new \DateTimeImmutable())))
            ->add(Crud::PAGE_DETAIL, Action::new(UserAdminService::REMOVE_FOR_BREACH, $this->t('account_suspension.admin.action_remove'), 'fa fa-user-xmark')
                ->linkToCrudAction(UserAdminService::REMOVE_FOR_BREACH))
            ->add(Crud::PAGE_DETAIL, Action::new(UserAdminService::MODERATOR_AREAS, $this->t('admin.action.moderator_areas'), 'fa fa-map')
                ->linkToCrudAction(UserAdminService::MODERATOR_AREAS)
                ->displayIf(fn (User $u) => $this->svc->hasRole($u, 'ROLE_CURATOR')))
            ->add(Crud::PAGE_INDEX, Action::DETAIL);

        return $actions;
    }

    /** @param AdminContext<User> $context */
    #[AdminRoute(options: ['methods' => ['POST']])]
    public function unlock(AdminContext $context): RedirectResponse
    {
        return $this->run($context, fn (User $t, User $a) => $this->svc->unlock($t, $a), 'admin.flash.unlocked');
    }

    /** @param AdminContext<User> $context */
    #[AdminRoute(options: ['methods' => ['POST']])]
    public function disarm_2fa(AdminContext $context): RedirectResponse
    {
        return $this->run($context, fn (User $t, User $a) => $this->svc->disarmTwoFa($t, $a), 'admin.flash.2fa_disarmed');
    }

    /** @param AdminContext<User> $context */
    #[AdminRoute(options: ['methods' => ['POST']])]
    public function verify_email(AdminContext $context): RedirectResponse
    {
        return $this->run($context, fn (User $t, User $a) => $this->svc->verifyEmail($t, $a), 'admin.flash.email_verified');
    }

    /** @param AdminContext<User> $context */
    #[AdminRoute(options: ['methods' => ['POST']])]
    public function unverify_email(AdminContext $context): RedirectResponse
    {
        return $this->run($context, fn (User $t, User $a) => $this->svc->unverifyEmail($t, $a), 'admin.flash.email_unverified');
    }

    /**
     * Grant curator together with its areas. GET shows the area picker, POST
     * grants. "All areas" is an explicit tick, never an empty picker.
     *
     * @see docs/specs/moderation-and-contribution.md §9.4
     *
     * @param AdminContext<User> $context
     */
    #[AdminRoute(options: ['methods' => ['GET', 'POST']])]
    public function grant_curator(AdminContext $context, Request $request, EntityManagerInterface $em): Response
    {
        /** @var User $target */
        $target = $context->getEntity()->getInstance();

        if (!$request->isMethod('POST')) {
            return $this->renderAreaPicker($target, $em, grant: true);
        }
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->request->get('token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token for a user support action.');
        }
        /** @var User $actor */
        $actor = $this->getUser();
        /** @var list<string> $countries */
        $countries = array_map(strval(...), (array) $request->request->all('countries'));
        /** @var list<int> $regions */
        $regions = array_map(intval(...), (array) $request->request->all('regions'));
        $allAreas = $request->request->getBoolean('all_areas');

        if ($allAreas === ([] !== $countries || [] !== $regions)) {
            $this->addFlash('danger', $this->translator->trans('admin.flash.areas_choose'));

            return $this->redirect(
                $this->urls->setController(self::class)->setAction(UserAdminService::GRANT_CURATOR)->setEntityId($target->getId())->generateUrl()
            );
        }

        try {
            $this->svc->grantCuratorWithAreas($target, $actor, $countries, $regions);
            $this->addFlash('success', $this->translator->trans('admin.flash.curator_granted'));
        } catch (\InvalidArgumentException|GuardrailViolationException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirect(
            $this->urls->setController(self::class)->setAction(Action::DETAIL)->setEntityId($target->getId())->generateUrl()
        );
    }

    /** @param AdminContext<User> $context */
    #[AdminRoute(options: ['methods' => ['POST']])]
    public function revoke_curator(AdminContext $context): RedirectResponse
    {
        return $this->run($context, fn (User $t, User $a) => $this->svc->revokeCurator($t, $a), 'admin.flash.role_changed');
    }

    /** @param AdminContext<User> $context */
    #[AdminRoute(options: ['methods' => ['POST']])]
    public function grant_admin(AdminContext $context): RedirectResponse
    {
        return $this->run($context, fn (User $t, User $a) => $this->svc->grantAdmin($t, $a), 'admin.flash.role_changed');
    }

    /** @param AdminContext<User> $context */
    #[AdminRoute(options: ['methods' => ['POST']])]
    public function revoke_admin(AdminContext $context): RedirectResponse
    {
        return $this->run($context, fn (User $t, User $a) => $this->svc->revokeAdmin($t, $a), 'admin.flash.role_changed');
    }

    /** @param AdminContext<User> $context */
    #[AdminRoute(options: ['methods' => ['POST']])]
    public function remove_account(AdminContext $context): RedirectResponse
    {
        return $this->run($context, fn (User $t, User $a) => $this->svc->removeAccount($t, $a), 'admin.flash.account_removed', backToIndex: true);
    }

    /**
     * Suspend for a number of days. GET shows the form (days, ground, facts),
     * POST suspends and emails the statement of reasons.
     *
     * @see docs/specs/account-and-auth.md §6.8
     *
     * @param AdminContext<User> $context
     */
    #[AdminRoute(options: ['methods' => ['GET', 'POST']])]
    public function suspend(AdminContext $context, Request $request): Response
    {
        /** @var User $target */
        $target = $context->getEntity()->getInstance();
        if (!$request->isMethod('POST')) {
            return $this->renderAccountDecision($target, UserAdminService::SUSPEND);
        }

        return $this->decideAccount($request, $target, UserAdminService::SUSPEND, fn (User $actor, StatementGround $ground, string $facts, bool $reports) => $this->svc->suspend(
            $target, $actor, $request->request->getInt('days'), $ground, $facts, $reports,
        ), 'account_suspension.admin.flash_suspended', 'account_suspension.admin.flash_suspended_unsent');
    }

    /** @param AdminContext<User> $context */
    #[AdminRoute(options: ['methods' => ['POST']])]
    public function lift_suspension(AdminContext $context): RedirectResponse
    {
        return $this->run($context, fn (User $t, User $a) => $this->svc->liftSuspension($t, $a), 'account_suspension.admin.flash_lifted');
    }

    /**
     * Remove an account for a breach of the terms. GET shows the form (ground,
     * facts), POST removes it and emails the statement of reasons.
     *
     * @see docs/specs/account-and-auth.md §6.8
     *
     * @param AdminContext<User> $context
     */
    #[AdminRoute(options: ['methods' => ['GET', 'POST']])]
    public function remove_for_breach(AdminContext $context, Request $request): Response
    {
        /** @var User $target */
        $target = $context->getEntity()->getInstance();
        if (!$request->isMethod('POST')) {
            return $this->renderAccountDecision($target, UserAdminService::REMOVE_FOR_BREACH);
        }

        return $this->decideAccount($request, $target, UserAdminService::REMOVE_FOR_BREACH, fn (User $actor, StatementGround $ground, string $facts, bool $reports) => $this->svc->removeAccountForBreach(
            $target, $actor, $ground, $facts, $reports,
        ), 'account_suspension.admin.flash_removed', 'account_suspension.admin.flash_removed_unsent', backToIndex: true);
    }

    private function renderAccountDecision(User $target, string $action): Response
    {
        return $this->render('admin/user_account_decision.html.twig', [
            'target' => $target,
            'action' => $action,
            'grounds' => StatementGround::forAccounts(),
            'max_days' => UserAdminService::SUSPENSION_MAX_DAYS,
        ]);
    }

    /**
     * The POST half of Suspend and Remove for a breach: CSRF, then the
     * decision; a refusal goes back to the form with the reason. When the
     * statement's email did not go out, the administrator is told so at once,
     * and where it waits to be sent again (Unsent statements).
     *
     * @param callable(User, StatementGround, string, bool): bool $decide returns whether the statement went out
     */
    private function decideAccount(Request $request, User $target, string $action, callable $decide, string $successKey, string $unsentKey, bool $backToIndex = false): RedirectResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->request->get('token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token for a user support action.');
        }
        /** @var User $actor */
        $actor = $this->getUser();
        $ground = StatementGround::tryFrom((string) $request->request->get('ground'));

        try {
            if (null === $ground) {
                throw new \InvalidArgumentException('account_suspension.admin.error_ground');
            }
            if ($decide($actor, $ground, (string) $request->request->get('facts', ''), $request->request->getBoolean('from_reports'))) {
                $this->addFlash('success', $this->translator->trans($successKey));
            } else {
                $this->addFlash('warning', $this->translator->trans($unsentKey));
            }
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $this->translator->trans($e->getMessage(), ['%max%' => UserAdminService::SUSPENSION_MAX_DAYS]));

            return $this->redirect($this->urls->setController(self::class)->setAction($action)->setEntityId($target->getId())->generateUrl());
        } catch (GuardrailViolationException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        if ($backToIndex) {
            return $this->redirect($this->urls->setController(self::class)->setAction(Action::INDEX)->generateUrl());
        }

        return $this->redirect($this->urls->setController(self::class)->setAction(Action::DETAIL)->setEntityId($target->getId())->generateUrl());
    }

    /** @param AdminContext<User> $context */
    #[AdminRoute(options: ['methods' => ['POST']])]
    public function cancel_removal(AdminContext $context): RedirectResponse
    {
        return $this->run($context, fn (User $t, User $a) => $this->svc->cancelPendingRemoval($t, $a), 'admin.flash.removal_cancelled');
    }

    /**
     * Assign moderator areas. GET+POST (form page), not POST-only.
     *
     * @see docs/specs/moderation-and-contribution.md §9
     *
     * @param AdminContext<User> $context
     */
    #[AdminRoute(options: ['methods' => ['GET', 'POST']])]
    public function moderator_areas(AdminContext $context, Request $request, EntityManagerInterface $em): Response
    {
        /** @var User $target */
        $target = $context->getEntity()->getInstance();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->request->get('token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token for a user support action.');
            }
            /** @var User $actor */
            $actor = $this->getUser();
            /** @var list<string> $countries */
            $countries = array_map(strval(...), (array) $request->request->all('countries'));
            /** @var list<int> $regions */
            $regions = array_map(intval(...), (array) $request->request->all('regions'));
            try {
                $this->svc->setModeratorAreas($target, $actor, $countries, $regions);
                $this->addFlash('success', $this->translator->trans('admin.flash.areas_saved'));
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('danger', $e->getMessage());
            }

            return $this->redirect(
                $this->urls->setController(self::class)->setAction(Action::DETAIL)->setEntityId($target->getId())->generateUrl()
            );
        }

        return $this->renderAreaPicker($target, $em, grant: false);
    }

    private function renderAreaPicker(User $target, EntityManagerInterface $em, bool $grant): Response
    {
        // Columns only — hydrating Region.geom OOMs this page.
        $regionRows = $em->getConnection()->fetchAllAssociative(
            'SELECT id, name, country_code FROM region ORDER BY country_code ASC, name ASC'
        );
        $regions = [];
        foreach ($regionRows as $r) {
            $regions[(string) $r['country_code']][] = $r;
        }

        return $this->render('admin/moderator_areas.html.twig', [
            'target' => $target,
            'countries' => $em->getRepository(Country::class)->findBy([], ['name' => 'ASC']),
            'regions' => $regions,
            'assigned' => $em->getRepository(ModeratorArea::class)->findBy(['userId' => (int) $target->getId()]),
            'grant' => $grant,
        ]);
    }

    /**
     * @param AdminContext<User>         $context
     * @param callable(User, User): void $op
     */
    private function run(AdminContext $context, callable $op, string $successKey, bool $backToIndex = false): RedirectResponse
    {
        // CSRF before the account is touched.
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $context->getRequest()->request->get('token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token for a user support action.');
        }

        /** @var User $target */
        $target = $context->getEntity()->getInstance();
        /** @var User $actor */
        $actor = $this->getUser();

        try {
            $op($target, $actor);
            $this->addFlash('success', $this->translator->trans($successKey));
        } catch (GuardrailViolationException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        if ($backToIndex) {
            return $this->redirect(
                $this->urls->setController(self::class)->setAction(Action::INDEX)->generateUrl()
            );
        }

        return $this->redirect(
            $this->urls->setController(self::class)->setAction(Action::DETAIL)->setEntityId($target->getId())->generateUrl()
        );
    }

    private function t(string $key): TranslatableInterface
    {
        return new TranslatableMessage($key);
    }
}
