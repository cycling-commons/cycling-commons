<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * CRUD controller for the User entity.
 *
 * Exposed fields: email, displayName, roles, emailVerified, twoFaEnabled (read-only),
 * lockedUntil, publicProfile, createdAt (read-only).
 *
 * Intentionally NEVER exposed: password, totpSecret, backupCodes.
 *
 * @api Instantiated by EasyAdmin's router; never referenced from application code.
 *
 * @extends AbstractCrudController<User>
 */
#[IsGranted('ROLE_ADMIN')]
final class UserCrudController extends AbstractCrudController
{
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
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield EmailField::new('email');

        yield TextField::new('displayName', 'Display Name');

        // ROLE_USER is always implied by User::getRoles() — listing it here is misleading.
        // Admins toggle only the elevated roles; ROLE_USER is never stored explicitly.
        yield ChoiceField::new('roles')
            ->setChoices([
                'Curator' => 'ROLE_CURATOR',
                'Admin' => 'ROLE_ADMIN',
            ])
            ->allowMultipleChoices()
            ->renderExpanded(false);

        yield BooleanField::new('emailVerified', 'Email Verified');

        yield BooleanField::new('twoFaEnabled', '2FA Enabled')
            ->hideOnForm();

        yield DateTimeField::new('lockedUntil', 'Locked Until')
            ->setRequired(false);

        yield BooleanField::new('publicProfile', 'Public Profile');

        yield DateTimeField::new('createdAt', 'Registered')
            ->hideOnForm();
    }
}
