<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Blog\BlogLocales;
use App\Blog\BlogStatus;
use App\Blog\Entity\BlogPost;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Writing and publishing a post.
 *
 * **In EasyAdmin, not a hand-built desk.** `admin stays EasyAdmin` is the
 * standing rule, and this is exactly the case it is for: a small CRUD over one
 * table, used by a handful of people, where a bespoke surface would be a
 * second set of forms to keep working for no gain a reader ever sees.
 *
 * **Publishing is a field, not a button.** {@see BlogPost::setStatus()} stamps
 * `publishedAt` the first time it goes live and keeps it if the post is later
 * withdrawn, so pulling a post and putting it back does not reorder the index
 * and misdate it for everybody who already read it.
 *
 * **The body is the restricted markdown the bug desk uses**
 * ({@see \App\Support\BugMarkdown}). A plain textarea is right for it: it is
 * six rules, and a rich editor would produce markup the renderer then strips.
 *
 * @see docs/specs/blog.md §6
 *
 * @api
 *
 * @extends AbstractCrudController<BlogPost>
 */
#[IsGranted('ROLE_ADMIN')]
final class BlogPostCrudController extends AbstractCrudController
{
    use RiderDatedFields;

    #[\Override]
    public static function getEntityFqcn(): string
    {
        return BlogPost::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Post')
            ->setEntityLabelInPlural('Blog')
            // Newest first, drafts included: the list is a workbench, not the
            // public index.
            ->setDefaultSort(['updatedAt' => 'DESC'])
            ->setPaginatorPageSize(25)
            ->setSearchFields(['title', 'slug', 'lede', 'body']);
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('title')
            ->setHelp('Up to '.BlogPost::TITLE_MAX.' characters.');

        yield TextField::new('slug')
            ->setHelp('The URL. Leave it and it is made from the title; change it and it stays changed.')
            ->setRequired(false);

        yield ChoiceField::new('locale')
            ->setChoices(array_combine(BlogLocales::WRITTEN, BlogLocales::WRITTEN))
            ->setHelp('The language this post is WRITTEN in. Readers in other languages see the English posts.');

        yield TextareaField::new('lede')
            ->setNumOfRows(3)
            ->setRequired(false)
            ->setHelp('The standfirst, shown on the index and in the feed. Optional.');

        yield TextareaField::new('body')
            ->setNumOfRows(22)
            ->setHelp('Markdown: **bold**, *italic*, `code`, ``` blocks, and lists. No images or links.')
            ->hideOnIndex();

        yield ChoiceField::new('status')
            ->setChoices(array_combine(
                array_map(static fn (BlogStatus $s): string => $s->value, BlogStatus::all()),
                BlogStatus::all(),
            ))
            ->renderAsBadges([
                BlogStatus::Draft->value => 'secondary',
                BlogStatus::Published->value => 'success',
            ]);

        yield TextField::new('translationOf')
            ->setLabel('Translation of (post id)')
            ->setRequired(false)
            ->setHelp('The id of the post this one is the other language of. Links the pair for readers.')
            ->hideOnIndex();

        yield $this->riderDateTime('publishedAt')->setDisabled()->hideOnForm();
        yield $this->riderDateTime('updatedAt')->setDisabled()->hideOnForm();
    }

    /**
     * Fill in the two things a writer should not have to.
     *
     * The slug from the title when it was left blank, and the author from
     * whoever is signed in. Both on create only: changing a published post's
     * slug breaks every link to it, so that stays a deliberate act.
     */
    #[\Override]
    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ('' === trim($entityInstance->getSlug())) {
            $entityInstance->setSlug($entityInstance->getTitle());
        }

        $user = $this->getUser();
        if ($user instanceof User) {
            $entityInstance->setAuthorId($user->getId());
        }

        parent::persistEntity($entityManager, $entityInstance);
    }
}
