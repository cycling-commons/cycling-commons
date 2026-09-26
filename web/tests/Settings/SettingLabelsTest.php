<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Tests\Settings;

use App\Settings\SettingsRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\TranslatorBagInterface;

/**
 * Every setting on /admin/system-config shows a label and a help line, so
 * each definition's two keys must exist in the English catalogue. A setting
 * added without them renders its raw key to the admin.
 */
final class SettingLabelsTest extends KernelTestCase
{
    public function testEverySettingHasALabelAndAHelpLine(): void
    {
        self::bootKernel();
        $registry = self::getContainer()->get(SettingsRegistry::class);
        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(SettingsRegistry::class, $registry);
        self::assertInstanceOf(TranslatorBagInterface::class, $translator);
        $catalogue = $translator->getCatalogue('en');

        $missing = [];
        foreach ($registry->all() as $definition) {
            foreach ([$definition->labelKey, $definition->helpKey] as $key) {
                if (!$catalogue->has($key)) {
                    $missing[] = $key;
                }
            }
        }

        self::assertSame([], $missing, 'settings without a label or help line in messages.en.yaml');
    }
}
