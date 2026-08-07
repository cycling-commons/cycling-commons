<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Catalog\Entity\Region;
use App\Entity\User;
use App\Moderation\Entity\ModeratorArea;
use App\Moderation\ModerationScopeProvider;
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
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
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
 * CRUD + support-desk actions for the User entity.
 *
 * Exposed fields only: email, displayName, roles, emailVerified, twoFaEnabled
 * (read-only), lockedUntil, publicProfile, createdAt. NEVER exposes password,
 * totpSecret, backupCodes (see docs/specs/account-and-auth.md §6.5).
 *
 * Support actions delegate to UserAdminService; guardrail violations become a
 * flash + redirect, never a 500.
 *
 * @api Instantiated by EasyAdmin's router.
 *
 * @extends AbstractCrudController<User>
 */
#[IsGranted('ROLE_ADMIN')]
final class UserCrudController extends AbstractCrudController
{
    /** CSRF token id shared by the action form template and {@see run()}. */
    public const string CSRF_TOKEN_ID = 'ea-user-support';

    public function __construct(
        private readonly UserAdminService $svc,
        private readonly AdminUrlGenerator $urls,
        private readonly TranslatorInterface $translator,
        private readonly ModerationScopeProvider $scopeProvider,
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
            ->add(ChoiceFilter::new('locale')->setChoices([
                'English' => 'en',
                'Français' => 'fr',
                'Nederlands' => 'nl',
                'Deutsch' => 'de',
                'Español' => 'es',
            ]))
            ->add(DateTimeFilter::new('createdAt', 'Registered'));
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield EmailField::new('email');
        yield TextField::new('displayName', 'Display Name');
        // roles / emailVerified / lockedUntil are shown but not form-editable.
        // Changes must go through the audited UserAdminService support actions
        // (grant/revoke, verify, unlock), never the generic EA form. That is
        // also why the built-in EDIT/DELETE are disabled below
        // (docs/specs/account-and-auth.md §6.1).
        yield ChoiceField::new('roles')
            ->setChoices(['Curator' => 'ROLE_CURATOR', 'Admin' => 'ROLE_ADMIN'])
            ->allowMultipleChoices()
            ->renderExpanded(false)
            ->hideOnForm();
        yield BooleanField::new('emailVerified', 'Email Verified')->hideOnForm();
        yield BooleanField::new('twoFaEnabled', '2FA Enabled')->hideOnForm();
        yield DateTimeField::new('lockedUntil', 'Locked Until')->setRequired(false)->hideOnForm();
        yield BooleanField::new('publicProfile', 'Public Profile');
        yield DateTimeField::new('createdAt', 'Registered')->hideOnForm();
        // 'email' is only a property carrier here. The displayed value comes
        // entirely from formatValue(), which reads the curator's live
        // moderator_area rows via ModerationScopeProvider
        // (docs/specs/moderation-and-contribution.md §9). 'id' was tried
        // first, but EasyAdmin's TextConfigurator rejects non-string,
        // non-Stringable raw values (int ids included) before formatValue
        // ever runs. 'email' is already a string, so it clears that check
        // untouched.
        yield TextField::new('email', $this->t('admin.field.mod_areas'))
            ->onlyOnDetail()
            ->formatValue(fn ($v, User $u): string => implode(' · ', $this->scopeProvider->describe($u)) ?: $this->translator->trans('account.mod_scope_all'));
    }

    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        // Every support action renders through a custom template that POSTs a
        // CSRF-tokened form (see admin/user_support_action.html.twig) instead of
        // EasyAdmin's default GET <a href>. Paired with the POST-only route on
        // each handler and the token check in run(), this closes the CSRF hole
        // a GET link with no token would otherwise leave open
        // (docs/specs/account-and-auth.md §6.1).
        $mk = fn (string $name, string $label, string $icon, bool $confirm, callable $when): Action => Action::new($name, $this->t($label), $icon)
            ->linkToCrudAction($name)
            ->displayIf($when)
            ->setTemplatePath('admin/user_support_action.html.twig')
            ->addCssClass($confirm ? 'action-confirm' : '');

        // Kill the generic EA create/edit/delete: they would let an admin change
        // roles / emailVerified / lockedUntil or delete an account WITHOUT the
        // UserAdminService guardrails (last-admin protection, …) and audit log.
        $actions->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE);

        $actions
            ->add(Crud::PAGE_DETAIL, $mk(UserAdminService::UNLOCK, 'admin.action.unlock', 'fa fa-unlock', false, static fn (User $u) => $u->isLocked()))
            ->add(Crud::PAGE_DETAIL, $mk(UserAdminService::DISARM_2FA, 'admin.action.disarm_2fa', 'fa fa-shield-halved', true, static fn (User $u) => $u->isTwoFaEnabled()))
            ->add(Crud::PAGE_DETAIL, $mk(UserAdminService::VERIFY_EMAIL, 'admin.action.verify_email', 'fa fa-envelope-circle-check', false, static fn (User $u) => !$u->isEmailVerified()))
            ->add(Crud::PAGE_DETAIL, $mk(UserAdminService::UNVERIFY_EMAIL, 'admin.action.unverify_email', 'fa fa-envelope', true, static fn (User $u) => $u->isEmailVerified()))
            ->add(Crud::PAGE_DETAIL, $mk(UserAdminService::GRANT_CURATOR, 'admin.action.grant_curator', 'fa fa-user-shield', false, fn (User $u) => !$this->svc->hasRole($u, 'ROLE_CURATOR')))
            ->add(Crud::PAGE_DETAIL, $mk(UserAdminService::REVOKE_CURATOR, 'admin.action.revoke_curator', 'fa fa-user', true, fn (User $u) => $this->svc->hasRole($u, 'ROLE_CURATOR')))
            ->add(Crud::PAGE_DETAIL, $mk(UserAdminService::GRANT_ADMIN, 'admin.action.grant_admin', 'fa fa-user-gear', true, fn (User $u) => !$this->svc->hasRole($u, 'ROLE_ADMIN')))
            ->add(Crud::PAGE_DETAIL, $mk(UserAdminService::REVOKE_ADMIN, 'admin.action.revoke_admin', 'fa fa-user-minus', true, fn (User $u) => $this->svc->hasRole($u, 'ROLE_ADMIN')))
            ->add(Crud::PAGE_DETAIL, $mk(UserAdminService::REMOVE_ACCOUNT, 'admin.action.remove_account', 'fa fa-trash', true, static fn (User $u) => null !== $u->getDeletionRequestedAt()))
            ->add(Crud::PAGE_DETAIL, $mk(UserAdminService::CANCEL_REMOVAL, 'admin.action.cancel_removal', 'fa fa-rotate-left', false, static fn (User $u) => null !== $u->getDeletionRequestedAt()))
            // Not one of the $mk one-click POST mutations: this opens a form
            // page (GET) the curator fills in before submitting (POST), so it
            // stays a plain link rather than the CSRF-form template above.
            ->add(Crud::PAGE_DETAIL, Action::new(UserAdminService::MODERATOR_AREAS, $this->t('admin.action.moderator_areas'), 'fa fa-map')
                ->linkToCrudAction(UserAdminService::MODERATOR_AREAS)
                ->displayIf(fn (User $u) => $this->svc->hasRole($u, 'ROLE_CURATOR')))
            ->add(Crud::PAGE_INDEX, Action::DETAIL);

        return $actions;
    }

    // ── Action handlers (one per support operation) ────────────────────────────

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

    /** @param AdminContext<User> $context */
    #[AdminRoute(options: ['methods' => ['POST']])]
    public function grant_curator(AdminContext $context): RedirectResponse
    {
        return $this->run($context, fn (User $t, User $a) => $this->svc->grantCurator($t, $a), 'admin.flash.role_changed');
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

    /** @param AdminContext<User> $context */
    #[AdminRoute(options: ['methods' => ['POST']])]
    public function cancel_removal(AdminContext $context): RedirectResponse
    {
        return $this->run($context, fn (User $t, User $a) => $this->svc->cancelPendingRemoval($t, $a), 'admin.flash.removal_cancelled');
    }

    /**
     * Assign moderator areas: GET renders the pick-regions/countries form,
     * POST validates and replaces the target's moderator_area rows
     * (docs/specs/moderation-and-contribution.md §9). Unlike the one-click
     * $mk actions above, this is a genuine intermediate page, hence GET+POST
     * rather than POST-only; see the comment on
     * testEverySupportActionRouteIsPostOnly().
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

        return $this->render('admin/moderator_areas.html.twig', [
            'target' => $target,
            'countries' => $em->getRepository(Country::class)->findBy([], ['name' => 'ASC']),
            'regions' => $em->getRepository(Region::class)->findBy([], ['name' => 'ASC']),
            'assigned' => $em->getRepository(ModeratorArea::class)->findBy(['userId' => (int) $target->getId()]),
        ]);
    }

    // ── Shared handler plumbing ────────────────────────────────────────────────

    /**
     * @param AdminContext<User>         $context
     * @param callable(User, User): void $op
     */
    private function run(AdminContext $context, callable $op, string $successKey, bool $backToIndex = false): RedirectResponse
    {
        // Defence-in-depth alongside the POST-only route: a forged/tokenless
        // request (or any GET that slipped through) is refused before the
        // account is touched. The token is minted by the action form template.
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
