<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Doctrine\TableComments;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The gate that keeps a new table from arriving unexplained.
 *
 * A table comment is the one piece of documentation a reader meets without
 * being sent to it: it is already on the screen when they open the table in
 * DBeaver. That only holds while every table has one, so the suite checks it
 * rather than trusting a habit.
 *
 * @see docs/specs/dev-environment.md §9
 */
final class TableCommentsTest extends KernelTestCase
{
    public function testEveryTableHasACommentOnRecord(): void
    {
        self::bootKernel();
        $comments = self::getContainer()->get(TableComments::class);
        self::assertInstanceOf(TableComments::class, $comments);

        self::assertSame(
            [],
            $comments->missing(),
            'Tables with no comment on record. Give the entity a docblock summary, '
            .'or add the table to config/table_comments.yaml.',
        );
    }

    public function testApplyWritesTheCommentOntoThePostgresTable(): void
    {
        self::bootKernel();
        $comments = self::getContainer()->get(TableComments::class);
        self::assertInstanceOf(TableComments::class, $comments);
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        $comments->apply();

        $stored = $connection->fetchOne("SELECT obj_description('users'::regclass, 'pg_class')");

        self::assertSame($comments->map()['users'], $stored);
    }

    /**
     * The first paragraph, and nothing after it.
     *
     * The long "why" that follows an opening sentence belongs in the source,
     * where it is read together with the code. A schema browser wants the
     * sentence.
     */
    public function testSummariseTakesTheFirstParagraphAsPlainProse(): void
    {
        $docComment = <<<'DOC'
            /**
             * **One post** on the curator room's board.
             *
             * A board row, not an inbox row: {@see \App\Messaging\Entity\UserMessage}
             * stores one row per recipient.
             *
             * @api
             */
            DOC;

        self::assertSame(
            "One post on the curator room's board.",
            TableComments::summarise($docComment),
        );
    }

    public function testSummariseJoinsAWrappedFirstParagraphIntoOneLine(): void
    {
        $docComment = "/**\n * One dataset we take records from,\n * and everything we owe it.\n *\n * More.\n */";

        self::assertSame(
            'One dataset we take records from, and everything we owe it.',
            TableComments::summarise($docComment),
        );
    }

    public function testSummariseReturnsNullForADocblockThatOnlyCarriesAnnotations(): void
    {
        self::assertNull(TableComments::summarise("/**\n * @api\n */"));
        self::assertNull(TableComments::summarise(false));
    }
}
