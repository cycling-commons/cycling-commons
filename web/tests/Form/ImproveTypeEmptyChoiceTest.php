<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Form;

use App\Catalog\ItemType;
use App\Catalog\ServiceKind;
use App\Form\ImproveType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Form\FormFactoryInterface;
use Twig\Environment;

/**
 * A stored empty value shows as empty, so saving the form for another reason
 * stores nothing nobody chose (owner 2026-10-01).
 *
 * "Still as mapped?" says it in words: its empty first option reads "Not
 * checked yet", because it is the question only a rider who looked can answer
 * (catalog-data-model.md §7).
 */
final class ImproveTypeEmptyChoiceTest extends KernelTestCase
{
    public function testStillAsMappedOpensOnNotCheckedYetWhenNothingIsStored(): void
    {
        $select = $this->select(ItemType::WaterFood, ['potable' => 'Yes (public supply)'], 'details', 'condition');

        $first = $select->filter('option')->first();
        self::assertSame('', $first->attr('value'));
        self::assertSame('Not checked yet', trim($first->text()));
        self::assertSame(0, $select->filter('option[selected]')->count(), 'nothing is preselected, so the empty first option shows');
    }

    public function testAStoredConditionStillShows(): void
    {
        $select = $this->select(ItemType::WaterFood, ['condition' => 'Out of order'], 'details', 'condition');

        self::assertSame('Out of order', $select->filter('option[selected]')->attr('value'));
    }

    public function testEveryOtherOptionalSelectOpensOnItsEmptyOption(): void
    {
        $select = $this->select(ItemType::WaterFood, [], 'details', 'type');

        self::assertSame('', $select->filter('option')->first()->attr('value'));
        self::assertSame(0, $select->filter('option[selected]')->count(), 'an empty type is not shown as the first type');
    }

    /**
     * A station's opening hours preselect 24/7 on a NEW place only. On one
     * that exists with nothing stored, preselecting would store "24/7" the
     * first time a rider saved the form to fix the note.
     */
    public function testARegistryDefaultPreselectsOnANewPlaceOnly(): void
    {
        $edit = $this->select(ItemType::BikeServices, ['tools' => 'Pump'], 'details', 'openingHours', ServiceKind::Station, addMode: false);
        self::assertSame(0, $edit->filter('option[selected]')->count());

        $add = $this->select(ItemType::BikeServices, [], 'details', 'openingHours', ServiceKind::Station, addMode: true);
        self::assertSame('24/7', $add->filter('option[selected]')->attr('value'));
    }

    /** @param array<string, string> $current */
    private function select(ItemType $type, array $current, string $group, string $field, ?ServiceKind $kind = null, bool $addMode = false): Crawler
    {
        self::bootKernel();
        $container = static::getContainer();
        $form = $container->get(FormFactoryInterface::class)->create(ImproveType::class, null, [
            'catalog_type' => $type,
            'current' => $current,
            'service_kind' => $kind,
            'add_mode' => $addMode,
        ]);
        $html = $container->get(Environment::class)
            ->createTemplate('{{ form_widget(f) }}')
            ->render(['f' => $form->createView()[$group][$field]]);

        return (new Crawler($html))->filter('select');
    }
}
