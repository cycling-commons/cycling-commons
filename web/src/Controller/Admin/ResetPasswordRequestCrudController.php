<?php

// SPDX-License-Identifier: AGPL-3.0-only

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
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Read-only reset diagnostics. Secrets (selector/hashedToken) are never exposed.
 *
 * @see docs/specs/account-and-auth.md §6.5
 *
 * @api
 *
 * @extends AbstractCrudController<ResetPasswordRequest>
 */
#[IsGranted('ROLE_ADMIN')]
final class ResetPasswordRequestCrudController extends AbstractCrudController
{
    use RiderDatedFields;

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
        // docs/specs/account-and-auth.md §6.5 — Detail would render secret fields.
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DETAIL, Action::BATCH_DELETE);
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('user', 'Account')->formatValue(
            static fn (mixed $v, ResetPasswordRequest $r): string => $r->getUser() instanceof User ? $r->getUser()->getEmail() : '—'
        );
        yield $this->riderDateTime('requestedAt', 'Requested');
        yield $this->riderDateTime('expiresAt', 'Expires');
        yield BooleanField::new('active', 'Active')->renderAsSwitch(false);
    }
}
