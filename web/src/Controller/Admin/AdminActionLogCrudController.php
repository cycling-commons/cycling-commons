<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AdminActionLog;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Read-only audit trail; `?targetUser=` scopes the index.
 *
 * @see docs/specs/account-and-auth.md §6.2
 *
 * @api
 *
 * @extends AbstractCrudController<AdminActionLog>
 */
#[IsGranted('ROLE_ADMIN')]
final class AdminActionLogCrudController extends AbstractCrudController
{
    use RiderDatedFields;

    #[\Override]
    public static function getEntityFqcn(): string
    {
        return AdminActionLog::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Activity')
            ->setEntityLabelInPlural('Activities')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE);
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield $this->riderDateTime('createdAt', 'When');
        yield TextField::new('action', 'Action');
        yield AssociationField::new('actor', 'By')->formatValue(
            static fn (mixed $v, AdminActionLog $l): string => $l->getActor()?->getEmail() ?? 'system'
        );
        yield AssociationField::new('targetUser', 'Target')->formatValue(
            static fn (mixed $v, AdminActionLog $l): string => $l->getTargetUser()?->getEmail() ?? '—'
        );
        yield TextField::new('note', 'Note')->hideOnIndex();
    }

    /** Scope the index to one account when `?targetUser=` is present. */
    #[\Override]
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);

        $targetId = $this->getContext()?->getRequest()->query->get('targetUser');
        if (null !== $targetId && '' !== $targetId) {
            $qb->andWhere('entity.targetUser = :t')->setParameter('t', (int) $targetId);
        }

        return $qb;
    }
}
