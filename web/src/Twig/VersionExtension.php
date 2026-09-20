<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Twig;

use App\Service\BuildVersion;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `cc_build()` — footer stamp ({@see BuildVersion}) plus the source offer.
 *
 * `url` points at the running commit, and falls back to the repository root
 * when the build cannot name one. It is here rather than in the template
 * because AGPL section 13 is a duty of the deployment: a box serving a hotfix
 * has to offer the source OF THAT BOX, so the link is derived from the same
 * stamp the footer prints and never hand-written beside it.
 *
 * @api
 */
final class VersionExtension extends AbstractExtension
{
    public function __construct(
        private readonly BuildVersion $version,
        #[Autowire('%cc.source.repo_url%')] private readonly string $repoUrl,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cc_build', fn (): array => $this->build()),
        ];
    }

    /** @return array{number: string, date: string, commit: string, url: string} */
    private function build(): array
    {
        $stamp = $this->version->stamp();
        $root = rtrim($this->repoUrl, '/');

        return $stamp + ['url' => '' !== $stamp['commit'] ? $root.'/commit/'.$stamp['commit'] : $root];
    }
}
