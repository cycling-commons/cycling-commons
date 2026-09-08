<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Support\Entity\ReleaseTag;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The releases the bugs desk may name in "Fixed in" (owner 2026-09-08).
 * A tag here is a name the desk offers; the changelog text stays in code.
 *
 * @api
 *
 * @extends AbstractCrudController<ReleaseTag>
 */
#[IsGranted('ROLE_ADMIN')]
final class ReleaseTagCrudController extends AbstractCrudController
{
    #[\Override]
    public static function getEntityFqcn(): string
    {
        return ReleaseTag::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Release')
            ->setEntityLabelInPlural('Releases')
            ->setDefaultSort(['releasedAt' => 'DESC', 'createdAt' => 'DESC'])
            ->setSearchFields(['tag', 'note']);
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('tag')
            ->setHelp('The git tag, with its v: v0.9.0. The bugs desk offers exactly these.');
        yield DateField::new('releasedAt', 'Released on')
            ->setRequired(false)
            ->setHelp('Leave it empty for a release that is planned but not cut.');
        yield TextareaField::new('note')
            ->setRequired(false)
            ->hideOnIndex();
    }
}
