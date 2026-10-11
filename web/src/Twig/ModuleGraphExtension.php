<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Every module a page's script graph loads at once, for its modulepreload
 * links: `cc_module_graph('map/map.js')`.
 *
 * Read from AssetMapper's own import graph, so the list is whole and follows
 * every new import by itself; a hand-kept list had drifted to 32 of the map's
 * modules, and each one missing loaded a round trip later, once the module
 * importing it had been read (owner 2026-10-11). Static imports only: a lazy
 * `import()` loads when it is needed, and preloading it would defeat that.
 *
 * In production the graph is cached in the system cache, keyed by the
 * compiled asset manifest, so a deploy with new files builds a new list. In
 * development, without a compiled manifest, it is walked on every request.
 *
 * @see docs/specs/map-and-search.md (the map's script loading)
 *
 * @api
 */
final class ModuleGraphExtension extends AbstractExtension
{
    public function __construct(
        private readonly AssetMapperInterface $assets,
        #[Autowire(service: 'cache.system')]
        private readonly CacheInterface $cache,
        #[Autowire('%kernel.project_dir%/public/assets/manifest.json')]
        private readonly string $manifest,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cc_module_graph', $this->graph(...)),
        ];
    }

    /**
     * The public paths of the entry and every module it imports statically,
     * nearest first.
     *
     * @return list<string>
     */
    public function graph(string $entry): array
    {
        $stamp = is_file($this->manifest) ? @filemtime($this->manifest).'-'.@filesize($this->manifest) : null;
        if (null === $stamp) {
            return $this->walk($entry);
        }

        return $this->cache->get('cc_module_graph.'.hash('xxh128', $entry.'|'.$stamp), fn (): array => $this->walk($entry));
    }

    /** @return list<string> */
    private function walk(string $entry): array
    {
        $seen = [];
        $queue = [$entry];
        $paths = [];
        while ([] !== $queue) {
            $logical = array_shift($queue);
            if (isset($seen[$logical])) {
                continue;
            }
            $seen[$logical] = true;

            $asset = $this->assets->getAsset($logical);
            if (null === $asset) {
                continue;
            }
            $paths[] = $asset->publicPath;
            foreach ($asset->getJavaScriptImports() as $import) {
                if (!$import->isLazy) {
                    $queue[] = $import->assetLogicalPath;
                }
            }
        }

        return $paths;
    }
}
