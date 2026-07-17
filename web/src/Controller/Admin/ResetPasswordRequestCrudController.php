<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Read-only password-reset diagnostics: which accounts have an outstanding
 * request, when it expires, and whether it is still active. Secrets
 * (selector/hashedToken) are never exposed. Only Delete is allowed, to purge
 * a stuck row.
 *
 * @see docs/specs/account-and-auth.md §6.5
 *
 * @api Instantiated by EasyAdmin's router.
 *
 * @extends AbstractCrudController<ResetPasswordRequest>
 */
#[IsGranted('ROLE_ADMIN')]
final class ResetPasswordRequestCrudController extends AbstractCrudController
{
    #[\Override]
    public static function getEntityFqcn(): string
    {
        return ResetPasswordRequest::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Reset request')
            ->setEntityLabelInPlural('Reset requests')
            ->setDefaultSort(['id' => 'DESC']);
    }

    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        // Only Delete is enabled, to purge a stuck row. Detail is disabled
        // because the secret fields (selector/hashedToken) must never render.
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DETAIL, Action::BATCH_DELETE);
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('user', 'Account')->formatValue(
            static fn (mixed $v, ResetPasswordRequest $r): string => $r->getUser() instanceof User ? $r->getUser()->getEmail() : '—'
        );
        yield DateTimeField::new('requestedAt', 'Requested');
        yield DateTimeField::new('expiresAt', 'Expires');
        yield BooleanField::new('active', 'Active')->renderAsSwitch(false);
    }
}
