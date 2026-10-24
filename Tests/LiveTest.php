<?php

namespace Omnifund\GitHubSponsors\Tests;

use Omnifund\GitHubSponsors\GitHubSponsorsProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

/**
 * GitHub's GraphQL API itself: three queries, public data only. GitHub
 * answers no anonymous GraphQL call: skipped unless OMNIFUND_GITHUB_TOKEN
 * holds a token (any token reads public listings). `docker compose run
 * --rm -e OMNIFUND_GITHUB_TOKEN omnifund live`; never in the default run.
 */
#[Group('live')]
final class LiveTest extends TestCase
{
    public function testAPublicListingItsTiersAndSponsors(): void
    {
        $token = (string) getenv('OMNIFUND_GITHUB_TOKEN');
        if ('' === $token) {
            self::markTestSkipped('No OMNIFUND_GITHUB_TOKEN: GitHub answers no anonymous GraphQL call.');
        }
        $provider = new GitHubSponsorsProvider(HttpClient::create(['timeout' => 20]), 'glitchr-studio', $token, userAgent: 'omnifund-tests/1.0 (+https://github.com/glitchr-studio/omnifund)');

        $profile = $provider->profile();
        self::assertSame('glitchr-studio', $profile->handle);
        self::assertSame('https://github.com/sponsors/glitchr-studio', $profile->url);
        self::assertIsInt($profile->sponsors);

        // A listing with tiers and public sponsors: GitHub's own sample of a long-standing one.
        $tiers = $provider->tiers('sindresorhus');
        self::assertNotEmpty($tiers);
        self::assertSame('USD', $tiers[0]->price?->currency);
        self::assertCount(2, $provider->sponsors('sindresorhus', 2));
    }
}
