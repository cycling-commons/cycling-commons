<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Display-name format rules live on the ENTITY, not on the two form types
 * (account-and-auth.md §9). The registration and settings forms are only two
 * of the write paths — admin CRUD (UserCrudController exposes displayName as
 * an editable field), console commands and fixtures are others, and they
 * validate the entity, not a form. A rule that only a form enforces is a rule
 * an administrator can walk straight past.
 *
 * These tests validate the entity directly, which is exactly what those other
 * paths do.
 */
final class DisplayNameValidationTest extends KernelTestCase
{
    private ValidatorInterface $validator;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->validator = static::getContainer()->get(ValidatorInterface::class);
    }

    /** @return list<string> */
    private function displayNameViolations(string $name): array
    {
        $user = (new User())->setEmail('name-validation@example.test');
        $user->setPassword('x');
        $user->setDisplayName($name);

        $messages = [];
        foreach ($this->validator->validate($user) as $violation) {
            if ('displayName' === $violation->getPropertyPath()) {
                $messages[] = (string) $violation->getMessage();
            }
        }

        return $messages;
    }

    /** @return iterable<string, array{string}> */
    public static function unacceptableNames(): iterable
    {
        yield 'doubled space' => ['Hanne  Vermeulen'];
        yield 'non-breaking space' => ["Hanne\u{00A0}Vermeulen"];
        yield 'fullwidth letters' => ["\u{FF28}\u{FF41}\u{FF4E}\u{FF4E}\u{FF45}"];
        yield 'mixed-script confusable' => ["H\u{0430}nne Vermeulen"];
        yield 'zero-width space' => ["Hanne\u{200B}Vermeulen"];
        yield 'too long' => [str_repeat('a', 101)];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unacceptableNames')]
    public function testTheEntityRejectsItOnEveryWritePath(string $name): void
    {
        self::assertNotSame(
            [],
            $this->displayNameViolations($name),
            sprintf('%s must not survive entity validation', json_encode($name)),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function acceptableNames(): iterable
    {
        yield 'plain' => ['Hanne Vermeulen'];
        yield 'accented' => ['Élise Østergård'];
        yield 'apostrophe' => ["Jean-Luc D'Arcy"];
        yield 'at the length limit' => [str_repeat('a', 100)];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('acceptableNames')]
    public function testOrdinaryNamesPass(string $name): void
    {
        self::assertSame([], $this->displayNameViolations($name));
    }

    /**
     * Several write paths persist a User before a name exists — the fixtures,
     * the moderation tests, partial flows — and the canonical column is
     * nullable precisely to support them (account-and-auth.md §9). Requiring a
     * name is the forms' job; the entity only says what a name must LOOK like
     * once there is one.
     */
    public function testANamelessUserIsStillValid(): void
    {
        $user = (new User())->setEmail('nameless@example.test');
        $user->setPassword('x');

        $paths = [];
        foreach ($this->validator->validate($user) as $violation) {
            $paths[] = $violation->getPropertyPath();
        }

        self::assertNotContains('displayName', $paths, 'an unnamed row must stay persistable');
    }

    /**
     * The other half of that split: a one-character name is refused when a
     * human submits one, even though the entity tolerates it. Pinned here so
     * the floor cannot quietly disappear along with the form constraints.
     */
    public function testTheTwoCharacterFloorIsEnforcedWhereAHumanTypes(): void
    {
        self::assertSame([], $this->displayNameViolations('H'), 'the entity itself is indifferent');

        $form = static::getContainer()->get('form.factory')
            ->create(\App\Form\SettingsType::class, (new User())->setEmail('floor@example.test'));
        $form->submit(['displayName' => 'H'], false);

        self::assertFalse($form->get('displayName')->isValid(), 'the settings form holds the floor');
    }
}
