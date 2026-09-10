<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Translation;

use App\Translation\Entity\TranslationEntry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SyncTranslationsCommandTest extends KernelTestCase
{
    public function testCommandSyncsFixturePath(): void
    {
        self::bootKernel();
        $tester = new CommandTester((new Application(self::$kernel))->find('app:translations:sync'));
        $tester->execute([
            '--file' => __DIR__.'/fixtures/messages.en.yaml',
        ]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('2 keys', $tester->getDisplay());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $alpha = $em->getRepository(TranslationEntry::class)->findOneBy(['messageKey' => 'alpha']);
        self::assertNotNull($alpha);
        self::assertSame('Alpha', $alpha->getEnglish());
    }
}
