<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * Foreign keys a migration created on a column the entity maps as a plain
 * integer, added to the schema the ORM generates so `doctrine:migrations:diff`
 * and `doctrine:schema:update` keep them instead of dropping them.
 *
 * A user id kept as a plain integer is the house style: loading the row never
 * loads the account behind it. The foreign key still does its job in the
 * database (here: clearing the column when that account is deleted,
 * docs/specs/account-and-auth.md §6.3), and the ORM cannot know about it
 * without an association. Each key listed here names the migration that made
 * it.
 *
 * @api
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class MigrationOwnedForeignKeys
{
    /**
     * @var list<array{table: string, name: string, column: string, references: string, on_delete: ReferentialAction}>
     */
    private const array KEYS = [
        // Version20261009050000: the administrator who decided a suspension.
        ['table' => 'users', 'name' => 'fk_users_suspended_by', 'column' => 'suspended_by', 'references' => 'users', 'on_delete' => ReferentialAction::SET_NULL],
    ];

    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();
        foreach (self::KEYS as $key) {
            if (!$schema->hasTable($key['table'])) {
                continue;
            }
            $constraint = ForeignKeyConstraint::editor()
                ->setUnquotedName($key['name'])
                ->setUnquotedReferencingColumnNames($key['column'])
                ->setUnquotedReferencedTableName($key['references'])
                ->setUnquotedReferencedColumnNames('id')
                ->setOnDeleteAction($key['on_delete'])
                ->create();
            $schema = $schema->edit()
                ->modifyTableByUnquotedName($key['table'], static function (TableEditor $table) use ($constraint): void {
                    $table->addForeignKeyConstraint($constraint);
                })
                ->create();
        }
        $args->setSchema($schema);
    }
}
