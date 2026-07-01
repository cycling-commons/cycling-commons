<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Service\GuardrailViolationException;
use App\Service\UserAdminService;
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
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * CRUD + support-desk actions for the User entity.
 *
 * Exposed fields only: email, displayName, roles, emailVerified, twoFaEnabled
 * (read-only), lockedUntil, publicProfile, createdAt. NEVER exposes password,
 * totpSecret, backupCodes (see docs/specs/2026-07-01-admin-panel-account-support-design.md §10).
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
    public function __construct(
        private readonly UserAdminService $svc,
        private readonly AdminUrlGenerator $urls,
        private readonly TranslatorInterface $translator,
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
            ]))
            ->add(DateTimeFilter::new('createdAt', 'Registered'));
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield EmailField::new('email');
        yield TextField::new('displayName', 'Display Name');
        yield ChoiceField::new('roles')
            ->setChoices(['Curator' => 'ROLE_CURATOR', 'Admin' => 'ROLE_ADMIN'])
            ->allowMultipleChoices()
            ->renderExpanded(false);
        yield BooleanField::new('emailVerified', 'Email Verified');
        yield BooleanField::new('twoFaEnabled', '2FA Enabled')->hideOnForm();
        yield DateTimeField::new('lockedUntil', 'Locked Until')->setRequired(false);
        yield BooleanField::new('publicProfile', 'Public Profile');
        yield DateTimeField::new('createdAt', 'Registered')->hideOnForm();
    }

    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        $mk = fn (string $name, string $label, string $icon, bool $confirm, callable $when): Action => Action::new($name, $this->t($label), $icon)
            ->linkToCrudAction($name)
            ->displayIf($when)
            ->addCssClass($confirm ? 'action-confirm' : '');

        $actions
            ->add(Crud::PAGE_DETAIL, $mk(UserAdminService::UNLOCK, 'admin.action.unlock', 'fa fa-unlock', false, static fn (User $u) => $u->isLocked()))
            ->add(Crud::PAGE_DETAIL, $mk(UserAdminService::DISARM_2FA, 'admin.action.disarm_2fa', 'fa fa-shield-halved', true, static fn (User $u) => $u->isTwoFaEnabled()))
            ->add(Crud::PAGE_DETAIL, $mk(UserAdminService::VERIFY_EMAIL, 'admin.action.verify_email', 'fa fa-envelope-circle-check', false, static fn (User $u) => !$u->isEmailVerified()))
            ->add(Crud::PAGE_DETAIL, $mk(UserAdminService::UNVERIFY_EMAIL, 'admin.action.unverify_email', 'fa fa-envelope', true, static fn (User $u) => $u->isEmailVerified()))
            ->add(Crud::PAGE_DETAIL, $mk(UserAdminService::GRANT_CURATOR, 'admin.action.grant_curator', 'fa fa-user-shield', false, fn (User $u) => !$this->svc->hasRole($u, 'ROLE_CURATOR')))
            ->add(Crud::PAGE_DETAIL, $mk(UserAdminService::REVOKE_CURATOR, 'admin.action.revoke_curator', 'fa fa-user', true, fn (User $u) => $this->svc->hasRole($u, 'ROLE_CURATOR')))
            ->add(Crud::PAGE_DETAIL, $mk(UserAdminService::GRANT_ADMIN, 'admin.action.grant_admin', 'fa fa-user-gear', true, fn (User $u) => !$this->svc->hasRole($u, 'ROLE_ADMIN')))
            ->add(Crud::PAGE_DETAIL, $mk(UserAdminService::REVOKE_ADMIN, 'admin.action.revoke_admin', 'fa fa-user-minus', true, fn (User $u) => $this->svc->hasRole($u, 'ROLE_ADMIN')))
            // Shown only for accounts with a pending self-requested deletion (deletionRequestedAt set).
            ->add(Crud::PAGE_DETAIL, $mk(UserAdminService::REMOVE_ACCOUNT, 'admin.action.remove_account', 'fa fa-trash', true, static fn (User $u) => null !== $u->getDeletionRequestedAt()))
            ->add(Crud::PAGE_DETAIL, $mk(UserAdminService::CANCEL_REMOVAL, 'admin.action.cancel_removal', 'fa fa-rotate-left', false, static fn (User $u) => null !== $u->getDeletionRequestedAt()))
            ->add(Crud::PAGE_INDEX, Action::DETAIL);

        return $actions;
    }

    // ── Action handlers (one per support operation) ────────────────────────────

    /** @param AdminContext<User> $context */
    #[AdminRoute]
    public function unlock(AdminContext $context): RedirectResponse
    {
        return $this->run($context, fn (User $t, User $a) => $this->svc->unlock($t, $a), 'admin.flash.unlocked');
    }

    /** @param AdminContext<User> $context */
    #[AdminRoute]
    public function disarm_2fa(AdminContext $context): RedirectResponse
    {
        return $this->run($context, fn (User $t, User $a) => $this->svc->disarmTwoFa($t, $a), 'admin.flash.2fa_disarmed');
    }

    /** @param AdminContext<User> $context */
    #[AdminRoute]
    public function verify_email(AdminContext $context): RedirectResponse
    {
        return $this->run($context, fn (User $t, User $a) => $this->svc->verifyEmail($t, $a), 'admin.flash.email_verified');
    }

    /** @param AdminContext<User> $context */
    #[AdminRoute]
    public function unverify_email(AdminContext $context): RedirectResponse
    {
        return $this->run($context, fn (User $t, User $a) => $this->svc->unverifyEmail($t, $a), 'admin.flash.email_unverified');
    }

    /** @param AdminContext<User> $context */
    #[AdminRoute]
    public function grant_curator(AdminContext $context): RedirectResponse
    {
        return $this->run($context, fn (User $t, User $a) => $this->svc->grantCurator($t, $a), 'admin.flash.role_changed');
    }

    /** @param AdminContext<User> $context */
    #[AdminRoute]
    public function revoke_curator(AdminContext $context): RedirectResponse
    {
        return $this->run($context, fn (User $t, User $a) => $this->svc->revokeCurator($t, $a), 'admin.flash.role_changed');
    }

    /** @param AdminContext<User> $context */
    #[AdminRoute]
    public function grant_admin(AdminContext $context): RedirectResponse
    {
        return $this->run($context, fn (User $t, User $a) => $this->svc->grantAdmin($t, $a), 'admin.flash.role_changed');
    }

    /** @param AdminContext<User> $context */
    #[AdminRoute]
    public function revoke_admin(AdminContext $context): RedirectResponse
    {
        return $this->run($context, fn (User $t, User $a) => $this->svc->revokeAdmin($t, $a), 'admin.flash.role_changed');
    }

    /** @param AdminContext<User> $context */
    #[AdminRoute]
    public function remove_account(AdminContext $context): RedirectResponse
    {
        return $this->run($context, fn (User $t, User $a) => $this->svc->removeAccount($t, $a), 'admin.flash.account_removed', backToIndex: true);
    }

    /** @param AdminContext<User> $context */
    #[AdminRoute]
    public function cancel_removal(AdminContext $context): RedirectResponse
    {
        return $this->run($context, fn (User $t, User $a) => $this->svc->cancelPendingRemoval($t, $a), 'admin.flash.removal_cancelled');
    }

    // ── Shared handler plumbing ────────────────────────────────────────────────

    /**
     * @param AdminContext<User>         $context
     * @param callable(User, User): void $op
     */
    private function run(AdminContext $context, callable $op, string $successKey, bool $backToIndex = false): RedirectResponse
    {
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
