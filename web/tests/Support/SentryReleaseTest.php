<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\BuildVersion;
use App\Support\SentryRelease;
use Sentry\Event;
use Sentry\State\HubInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Every GlitchTip event names the build it came from: the release tag and the
 * commit, from the same BuildVersion stamp as the footer and /humans.txt.
 * Before this, every event said `1.0.0+no-version-set`.
 */
final class SentryReleaseTest extends KernelTestCase
{
    private const string SHA = '0f480ca3eee015d9ccd15e45e63cfb318895d8af';

    #[\Override]
    protected function tearDown(): void
    {
        BuildVersion::reset();
        parent::tearDown();
    }

    private function deployedRelease(?string $version): string
    {
        $dir = sys_get_temp_dir().'/cc-release-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/REVISION', self::SHA."\n");
        if (null !== $version) {
            file_put_contents($dir.'/VERSION', $version."\n");
        }

        return $dir;
    }

    private function releaseOf(BuildVersion $build): ?string
    {
        BuildVersion::reset();

        return (new SentryRelease($build))(Event::createEvent())?->getRelease();
    }

    public function testADeployedBuildIsNamedByTagAndCommit(): void
    {
        $build = new BuildVersion($this->deployedRelease('v0.9.2-beta'), '', static fn () => null);

        self::assertSame('cyclingcommons@v0.9.2-beta+0f480ca3eee0', $this->releaseOf($build));
    }

    /** No tag anywhere: the stamp falls back to the commit, which is named once. */
    public function testABuildWithoutATagIsNamedByItsCommitOnce(): void
    {
        $build = new BuildVersion($this->deployedRelease(null), '', static fn () => null);

        self::assertSame('cyclingcommons@0f480ca3eee0', $this->releaseOf($build));
    }

    /** No REVISION and no git: the environment's label, and nothing invented. */
    public function testABuildWithoutACommitIsNamedByItsLabel(): void
    {
        $build = new BuildVersion(sys_get_temp_dir().'/cc-no-such-release', 'v0.9.2-beta', static fn () => null);

        self::assertSame('cyclingcommons@v0.9.2-beta', $this->releaseOf($build));
    }

    public function testTheHookIsWiredIntoTheSentryClient(): void
    {
        $client = self::getContainer()->get(HubInterface::class)->getClient();
        self::assertNotNull($client);
        $hook = $client->getOptions()->getBeforeSendCallback();

        $event = $hook(Event::createEvent(), null);

        self::assertNotNull($event);
        self::assertStringStartsWith('cyclingcommons@', (string) $event->getRelease());
    }
}
