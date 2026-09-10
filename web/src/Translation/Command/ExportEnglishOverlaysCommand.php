<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Translation\Command;

use App\Translation\Entity\TranslationOverlay;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Live English overlays as YAML, for a developer to carry into messages.en.yaml.
 *
 * The next sync drops each overlay whose wording git now carries
 * (translations.md §3.3). Prints to stdout; never writes the catalogue itself.
 *
 * @api
 */
#[AsCommand(name: 'app:translations:english-export', description: 'Print live English overlays as YAML for messages.en.yaml')]
final class ExportEnglishOverlaysCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tree = [];
        foreach ($this->em->getRepository(TranslationOverlay::class)->findBy(['locale' => 'en']) as $overlay) {
            $key = $overlay->getEntry()->getMessageKey();
            self::setPath($tree, explode('.', $key), $overlay->getValue(), $key);
        }

        $output->writeln('# Live English overlays (translations.md §3.3). Merge into web/translations/messages.en.yaml');
        $output->writeln('# and also update the four locale files in the same pull request.');
        $output->write(Yaml::dump($tree, 8, 2));

        return Command::SUCCESS;
    }

    /**
     * Writes $value at the dotted $path inside $tree, building intermediate
     * arrays as needed. A plain recursive builder, not the by-reference
     * `$node = &$node[$part]` walk this replaced: Psalm cannot follow that
     * walk's type through an array only ever known to hold either nested
     * arrays or a leaf string, and flags it (EmptyArrayAccess /
     * InvalidArrayOffset) even though the runtime behaviour is correct.
     *
     * The invariant this depends on: no message key may be both a leaf
     * itself AND the dotted prefix of another key (e.g. `a` and `a.b` both
     * existing as overlays). Verified against every key in
     * messages.en.yaml (3844 keys, zero collisions): the catalogue does
     * not have this shape today, but nothing in the schema forbids a
     * future overlay pair colliding this way, and building the YAML tree
     * necessarily has to pick a winner between "leaf" and "parent of more
     * keys" at that node. The old by-reference walk this replaced would
     * have hit a PHP fatal error on that shape (you cannot take a
     * reference into a string offset); silently overwriting instead would
     * trade that loud crash for quiet data loss in an export a developer
     * is about to paste into the catalogue. Throw instead, so the failure
     * stays loud.
     *
     * @param array<string, mixed>   $tree
     * @param non-empty-list<string> $path
     * @param string                 $fullKey the untruncated dotted key, for the exception message
     */
    private static function setPath(array &$tree, array $path, string $value, string $fullKey): void
    {
        $key = array_shift($path);
        if ([] === $path) {
            if (\array_key_exists($key, $tree) && \is_array($tree[$key])) {
                throw new \LogicException(sprintf('Overlay key collision: "%s" is a leaf, but is also the prefix of another overlay key already exported under it. Both cannot be written into one YAML tree.', $fullKey));
            }
            $tree[$key] = $value;

            return;
        }
        if (\array_key_exists($key, $tree) && !\is_array($tree[$key])) {
            throw new \LogicException(sprintf('Overlay key collision: "%s" needs "%s" as a parent segment, but "%s" is already exported as a leaf value ("%s"). Both cannot be written into one YAML tree.', $fullKey, $key, $key, $tree[$key]));
        }
        if (!isset($tree[$key])) {
            $tree[$key] = [];
        }
        /** @var array<string, mixed> $child */
        $child = &$tree[$key];
        self::setPath($child, $path, $value, $fullKey);
    }
}
