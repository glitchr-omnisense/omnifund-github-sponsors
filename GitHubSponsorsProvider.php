<?php

namespace Omnifund\GitHubSponsors;

use Omnifund\Capabilities;
use Omnifund\Exception\ProviderException;
use Omnifund\Http\Json;
use Omnifund\Model\Goal;
use Omnifund\Model\Money;
use Omnifund\Model\Period;
use Omnifund\Model\Profile;
use Omnifund\Model\Sponsor;
use Omnifund\Model\Tier;
use Omnifund\ProfileInterface;
use Omnifund\SponsorsInterface;
use Omnifund\TiersInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A GitHub Sponsors listing through GitHub's GraphQL API: the listing (its
 * name, page, goal and tiers), the number of sponsors and the public ones -
 * a private sponsor is counted by GitHub and never listed. GitHub gives no
 * public income: Profile::$income stays null.
 */
final class GitHubSponsorsProvider implements ProfileInterface, SponsorsInterface, TiersInterface
{
    public const ENDPOINT = 'https://api.github.com/graphql';

    private const LISTING = 'query($login: String!) { repositoryOwner(login: $login) { login url avatarUrl ... on Sponsorable { hasSponsorsListing sponsors { totalCount } sponsorsListing { name shortDescription url isPublic activeGoal { kind title description percentComplete targetValue } tiers(first: 100) { nodes { id name description monthlyPriceInCents isOneTime isCustomAmount } } } } ... on User { name } ... on Organization { name } } }';
    private const SPONSORS = 'query($login: String!, $first: Int!, $after: String) { repositoryOwner(login: $login) { ... on Sponsorable { sponsors(first: $first, after: $after) { pageInfo { hasNextPage endCursor } nodes { __typename ... on User { login name avatarUrl url } ... on Organization { login name avatarUrl url } } } } } }';

    /** @var array<string, array<mixed>> */
    private array $listings = [];

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $account,
        private readonly string $token,
        private readonly string $endpoint = self::ENDPOINT,
        private readonly string $userAgent = 'omnifund (+https://github.com/glitchr-omnisense/omnifund)',
    ) {
    }

    public function getName(): string
    {
        return 'github-sponsors';
    }

    public function getTitle(): string
    {
        return 'GitHub Sponsors';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(profile: true, sponsors: true, tiers: true, key: true, terms: [
            'https://docs.github.com/en/site-policy/github-terms/github-terms-of-service',
            'https://docs.github.com/en/site-policy/github-terms/github-sponsors-additional-terms',
            'https://docs.github.com/en/graphql/overview/rate-limits-and-node-limits-for-the-graphql-api',
        ]);
    }

    public function profile(?string $account = null): Profile
    {
        $owner = $this->listing($account ??= $this->account);
        $listing = Json::get($owner, 'sponsorsListing');
        $goal = Json::get($listing, 'activeGoal');
        $target = Json::int($goal, 'targetValue');
        $byAmount = 'MONTHLY_SPONSORSHIP_AMOUNT' === Json::string($goal, 'kind');

        return new Profile(
            'github-sponsors',
            Json::string($owner, 'login') ?? $account,
            Json::string($owner, 'name'),
            Json::string($listing, 'shortDescription'),
            Json::string($listing, 'url') ?? Json::string($owner, 'url'),
            Json::string($owner, 'avatarUrl'),
            Json::int($owner, 'sponsors', 'totalCount'),
            null,
            \is_array($goal) ? new Goal(
                Json::string($goal, 'title'),
                Json::string($goal, 'description'),
                // GitHub gives an amount goal in dollars a month.
                $byAmount && null !== $target ? new Money($target * 100, 'USD') : null,
                $byAmount ? Period::Month : null,
                !$byAmount ? $target : null,
                Json::int($goal, 'percentComplete'),
            ) : null,
        );
    }

    public function sponsors(?string $account = null, ?int $limit = null): array
    {
        $account ??= $this->account;
        $sponsors = [];
        $after = null;
        do {
            $first = null === $limit ? 100 : min(100, $limit - \count($sponsors));
            $page = Json::get($this->query(self::SPONSORS, ['login' => $account, 'first' => $first, 'after' => $after], "the sponsors of $account"), 'data', 'repositoryOwner', 'sponsors');
            foreach (Json::list($page, 'nodes') as $node) {
                if (null !== $login = Json::string($node, 'login')) {
                    $sponsors[] = new Sponsor($login, Json::string($node, 'name'), Json::string($node, 'avatarUrl'), Json::string($node, 'url'), 'Organization' === Json::string($node, '__typename') ? Sponsor::ORGANIZATION : Sponsor::USER);
                }
            }
            $after = Json::bool($page, 'pageInfo', 'hasNextPage') ? Json::string($page, 'pageInfo', 'endCursor') : null;
        } while (null !== $after && (null === $limit || \count($sponsors) < $limit));

        return $sponsors;
    }

    public function tiers(?string $account = null): array
    {
        $tiers = [];
        foreach (Json::list($this->listing($account ?? $this->account), 'sponsorsListing', 'tiers', 'nodes') as $node) {
            $cents = Json::int($node, 'monthlyPriceInCents');
            $tiers[] = new Tier(
                Json::string($node, 'name') ?? '',
                null === $cents ? null : new Money($cents, 'USD'),
                Json::bool($node, 'isOneTime') ? Period::Once : Period::Month,
                Json::string($node, 'id'),
                Json::string($node, 'description'),
                Json::bool($node, 'isCustomAmount'),
            );
        }

        return $tiers;
    }

    /** @return array<mixed> the account, its listing, its tiers - asked once */
    private function listing(string $account): array
    {
        if (!isset($this->listings[$account])) {
            $owner = Json::get($this->query(self::LISTING, ['login' => $account], "the listing of $account"), 'data', 'repositoryOwner');
            if (!\is_array($owner)) {
                throw ProviderException::answered('GitHub', "the listing of $account", 'no such account');
            }
            if (!Json::bool($owner, 'hasSponsorsListing')) {
                throw ProviderException::answered('GitHub', "the listing of $account", 'no GitHub Sponsors listing');
            }
            $this->listings[$account] = $owner;
        }

        return $this->listings[$account];
    }

    /**
     * @param array<string, mixed> $variables
     *
     * @return array<mixed>
     */
    private function query(string $query, array $variables, string $what): array
    {
        $answer = Json::request($this->http, 'POST', $this->endpoint, [
            'headers' => ['Authorization' => 'Bearer '.$this->token, 'User-Agent' => $this->userAgent, 'Accept' => 'application/json'],
            'json' => ['query' => $query, 'variables' => $variables],
        ], 'GitHub', $what);
        if ([] !== $errors = Json::list($answer, 'errors')) {
            throw ProviderException::answered('GitHub', $what, implode('; ', array_map(static fn ($e) => Json::string($e, 'message') ?? '?', $errors)));
        }

        return $answer;
    }
}
