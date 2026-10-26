<?php

namespace Omnifund\GitHubSponsors\Tests;

use Omnifund\Exception\InvalidConfigException;
use Omnifund\Exception\ProviderException;
use Omnifund\GitHubSponsors\GitHubSponsorsFactory;
use Omnifund\GitHubSponsors\GitHubSponsorsProvider;
use Omnifund\Model\Period;
use Omnifund\Model\Sponsor;
use Omnifund\Registry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * GitHub's answers recorded (the shapes of a live answer read with a token
 * on 2026-10-24, the people invented), each request checked: the endpoint,
 * the bearer token, the User-Agent, the query and its variables.
 */
final class GitHubSponsorsProviderTest extends TestCase
{
    /** @var list<array{method: string, url: string, options: array<mixed>}> */
    private array $requests = [];

    public function testTheProfileFromTheListing(): void
    {
        $profile = $this->provider(['listing'])->profile();

        self::assertSame('github-sponsors', $profile->provider);
        self::assertSame('octo-studio', $profile->handle);
        self::assertSame('Octo Studio', $profile->name);
        self::assertSame("Support Octo Studio's open source work", $profile->description);
        self::assertSame('https://github.com/sponsors/octo-studio', $profile->url);
        self::assertSame(3, $profile->sponsors);
        self::assertNull($profile->income, 'GitHub gives no public income');
        $goal = $profile->goal;
        self::assertNotNull($goal);
        self::assertSame('$500 a month', $goal->title);
        self::assertNotNull($goal->amount);
        self::assertSame(['500.00', 'USD'], [$goal->amount->decimal(), $goal->amount->currency]);
        self::assertSame(Period::Month, $goal->period);
        self::assertNull($goal->sponsors);
        self::assertSame(12, $goal->percent);

        self::assertCount(1, $this->requests);
        self::assertSame('POST', $this->requests[0]['method']);
        self::assertSame('https://api.github.com/graphql', $this->requests[0]['url']);
        self::assertContains('Authorization: Bearer ghp_test', $this->header(0));
        self::assertContains('User-Agent: omnifund (+https://github.com/glitchr-omnisense/omnifund)', $this->header(0));
        $body = $this->body(0);
        self::assertSame(['login' => 'octo-studio'], $body['variables']);
        self::assertIsString($body['query']);
        self::assertStringContainsString('sponsorsListing {', $body['query']);
    }

    public function testTheTiersComeFromTheSameAnswer(): void
    {
        $provider = $this->provider(['listing']);
        $provider->profile();
        $tiers = $provider->tiers();

        self::assertCount(1, $this->requests, 'the listing asked once');
        self::assertCount(2, $tiers);
        self::assertSame(['$5 a month', '5.00', 'USD', Period::Month, false], [$tiers[0]->name, $tiers[0]->price?->decimal(), $tiers[0]->price?->currency, $tiers[0]->period, $tiers[0]->customAmount]);
        self::assertSame('MDEyOlNwb25zb3JzVGllcjEyMQ==', $tiers[0]->id);
        self::assertSame(['$25 one time', '25.00', Period::Once, true], [$tiers[1]->name, $tiers[1]->price?->decimal(), $tiers[1]->period, $tiers[1]->customAmount]);
    }

    public function testAGoalCountedInSponsors(): void
    {
        $listing = (string) file_get_contents(__DIR__.'/Fixtures/listing.json');
        $answer = str_replace(['"MONTHLY_SPONSORSHIP_AMOUNT"', '"targetValue":500'], ['"TOTAL_SPONSORS_COUNT"', '"targetValue":10'], $listing);
        $goal = $this->provider([$answer])->profile()->goal;

        self::assertNotNull($goal);
        self::assertSame(10, $goal->sponsors);
        self::assertNull($goal->amount);
        self::assertNull($goal->period);
    }

    public function testEverySponsorPageAfterPage(): void
    {
        $sponsors = $this->provider(['sponsors-1', 'sponsors-2'])->sponsors();

        self::assertSame(['ada', 'acme', 'bob'], array_map(static fn (Sponsor $s) => $s->handle, $sponsors));
        self::assertSame(Sponsor::ORGANIZATION, $sponsors[1]->kind);
        self::assertSame(Sponsor::USER, $sponsors[0]->kind);
        self::assertSame('bob', $sponsors[2]->label());
        self::assertSame('https://github.com/ada', $sponsors[0]->url);
        self::assertSame(['login' => 'octo-studio', 'first' => 100, 'after' => null], $this->body(0)['variables']);
        self::assertSame(['login' => 'octo-studio', 'first' => 100, 'after' => 'Y3Vyc29yOnYyOpIAzQeS'], $this->body(1)['variables']);
    }

    public function testALimitAsksNoMoreThanItNeeds(): void
    {
        $sponsors = $this->provider(['sponsors-1'])->sponsors('other-account', 2);

        self::assertCount(2, $sponsors);
        self::assertCount(1, $this->requests);
        self::assertSame(['login' => 'other-account', 'first' => 2, 'after' => null], $this->body(0)['variables']);
    }

    public function testAnAccountGitHubDoesNotKnow(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage("GitHub answered Could not resolve to a RepositoryOwner with the login of 'nobody-here-0'. for the listing of nobody-here-0.");
        $this->provider(['not-found'])->profile('nobody-here-0');
    }

    public function testAnAccountWithoutAListing(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('GitHub answered no GitHub Sponsors listing for the listing of plain-user.');
        $this->provider(['no-listing'])->tiers('plain-user');
    }

    public function testABadToken(): void
    {
        $provider = new GitHubSponsorsProvider(new MockHttpClient(new MockResponse('{"message":"Bad credentials"}', ['http_code' => 401])), 'octo-studio', 'nope');

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('GitHub answered 401 for the listing of octo-studio.');
        $provider->profile();
    }

    public function testTheFactoryNeedsAnAccountAndAToken(): void
    {
        $registry = new Registry([new GitHubSponsorsFactory(new MockHttpClient())], ['github' => ['factory' => 'github-sponsors', 'options' => ['account' => 'octo-studio']]]);

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('The "github-sponsors" provider needs: token.');
        $registry->get('github');
    }

    public function testTheFactoryBuildsTheProvider(): void
    {
        $registry = new Registry([new GitHubSponsorsFactory(new MockHttpClient())], ['github' => ['factory' => 'github-sponsors', 'options' => ['account' => 'octo-studio', 'token' => 't']]]);

        self::assertInstanceOf(GitHubSponsorsProvider::class, $registry->get('github'));
        self::assertSame(['profile', 'sponsors', 'tiers'], $registry->get('github')->capabilities()->contracts());
        self::assertTrue($registry->get('github')->capabilities()->key);
    }

    /** @param list<string> $answers fixture names, or answers themselves */
    private function provider(array $answers): GitHubSponsorsProvider
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$answers): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
            $answer = (string) array_shift($answers);

            return new MockResponse(str_starts_with($answer, '{') ? $answer : (string) file_get_contents(__DIR__."/Fixtures/$answer.json"));
        });

        return new GitHubSponsorsProvider($http, 'octo-studio', 'ghp_test');
    }

    /** @return list<string> */
    private function header(int $request): array
    {
        $headers = $this->requests[$request]['options']['headers'] ?? [];

        return \is_array($headers) ? array_values(array_filter($headers, 'is_string')) : [];
    }

    /** @return array<string, mixed> */
    private function body(int $request): array
    {
        $body = json_decode(\is_string($b = $this->requests[$request]['options']['body'] ?? null) ? $b : '', true);
        self::assertIsArray($body);

        /** @var array<string, mixed> $body */
        return $body;
    }
}
