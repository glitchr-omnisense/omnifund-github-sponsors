# omnifund/github-sponsors

GitHub Sponsors for [omnisense/omnifund](https://github.com/glitchr-omnisense/omnifund): a user's
or an organization's GitHub Sponsors listing read through GitHub's GraphQL API
(`POST https://api.github.com/graphql`, or a GitHub Enterprise endpoint) - its page, its goal, its
tiers, the number of sponsors and the public ones. Two queries: the listing (the account, its
listing, its active goal, its first 100 tiers, the sponsors counted) and the sponsors, 100 a page.

## Installation

```sh
composer require omnifund/github-sponsors symfony/http-client
```

Requires PHP 8.2, `omnisense/omnifund` (1.x) and `symfony/http-client-contracts` (^3.0): the
application gives the HTTP client (`symfony/http-client`, or any implementation of the
contracts) to the factory's constructor.

No library is wrapped: no maintained framework-free client covers GitHub Sponsors - a general
GitHub client would bring its own HTTP stack for two GraphQL queries.

## Credentials

A **GitHub token**: GitHub's GraphQL API answers no anonymous call. Any token reads a public
listing and its public sponsors; a fine-grained personal access token with no permission at all
is enough. No GitHub
Sponsors account is needed to read someone else's listing.

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `account` | string | - | yes | the user or organization whose listing is read: `glitchr-studio` |
| `token` | string | - | yes | the token, sent as `Authorization: Bearer` |
| `endpoint` | string | `https://api.github.com/graphql` | no | GitHub Enterprise: `https://HOST/api/graphql` |
| `user_agent` | string | `omnifund (+https://github.com/glitchr-omnisense/omnifund)` | no | sent with every call (GitHub refuses a request without one) |

`create()` without `account` or `token` is an `InvalidConfigException`
(`The "github-sponsors" provider needs: token.`).

## Capabilities

| Contract | Supported |
|---|---|
| `ProfileInterface` | yes (no income) |
| `SponsorsInterface` | yes: the public sponsors |
| `TiersInterface` | yes |

`capabilities()`: profile, sponsors and tiers; `key` true; terms: GitHub's Terms of Service, the
GitHub Sponsors additional terms, the GraphQL rate limits.

## Behaviour

**The listing.** One query reads the account (`repositoryOwner(login)`): its login, page,
picture and name, `hasSponsorsListing`, `sponsors.totalCount`, and its `sponsorsListing` (name,
short description, page, active goal, first 100 tiers). The answer is kept in the provider object
per account: `profile()` and `tiers()` for the same account cost one query. An account GitHub does
not know is a `ProviderException` with GitHub's message; an account without a listing
`GitHub answered no GitHub Sponsors listing for the listing of <account>.`

**Profile.** `handle` the login, `name` the user's or organization's name, `description` the
listing's short description, `url` the listing's page (else the account's), `avatarUrl` the
account's, `sponsors` the total count - private sponsors included, as GitHub counts them.
`income` is `null`: GitHub shows it to the account alone. The goal: its title, description and
`percentComplete`; a `MONTHLY_SPONSORSHIP_AMOUNT` goal is an amount, GitHub's `targetValue` in
dollars a month (`Money` in USD cents, period month); a `TOTAL_SPONSORS_COUNT` goal (any other
kind) a number of sponsors. The listing's `isPublic` is read and not used.

**Sponsors.** `sponsors(first, after)` page after page while `hasNextPage`, 100 a page, or no
more than the limit leaves; each node with a login becomes a `Sponsor`: login, name, picture, page,
`organization` for an `Organization`, else `user`. A private sponsor is never listed; no tier,
since or total (GitHub shows them to the sponsor and the maintainer alone).

**Tiers.** Each tier: name, `monthlyPriceInCents` as USD, period once (`isOneTime`) or month, id,
description, `isCustomAmount`. No minimum.

**Calls.** `POST` with `Authorization: Bearer <token>`, the `User-Agent`, `Accept:
application/json`, and `{"query", "variables"}`. A GraphQL `errors` member is a
`ProviderException` joining the messages.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnifund\GitHubSponsors\GitHubSponsorsFactory;
use Omnifund\Registry;
use Symfony\Component\HttpClient\HttpClient;

$registry = new Registry([new GitHubSponsorsFactory(HttpClient::create(['timeout' => 10]))], [
    'github' => ['factory' => 'github-sponsors', 'options' => ['account' => 'glitchr-studio', 'token' => getenv('GITHUB_TOKEN')]],
]);

$profile = $registry->profile('github')->profile();
echo $profile->name, ': ', $profile->sponsors, " sponsors\n";
foreach ($registry->sponsors('github')->sponsors(limit: 12) as $sponsor) {
    echo '- ', $sponsor->label(), ' ', $sponsor->url, "\n";
}
foreach ($registry->tiers('github')->tiers() as $tier) {
    echo $tier->name, ': ', $tier->price?->decimal(), ' ', $tier->price?->currency, ' ', $tier->period->value, "\n";
}
```

On the package's recorded answers (run on 2026-10-26 in the family's harness), the profile,
sponsors and tiers read:

```
GitHub Sponsors (profile sponsors tiers): Octo Studio, 3 sponsors, goal $500 a month 12%
  sponsor Ada L. (user)
  sponsor ACME (organization)
  tier $5 a month: 5.00 month
  tier $25 one time: 25.00 once, any amount
```

In Symfony, the factory as a service and the registry given to it: [the core's Symfony
page](https://github.com/glitchr-omnisense/omnifund/blob/1.x/docs/symfony.md).

## Errors

- A status of 300 or more: `ProviderException` `GitHub answered <status> for the listing of
  <account>.` (or `the sponsors of <account>`): a bad token is a 401.
- A GraphQL `errors` member: `ProviderException` with GitHub's messages.
- No such account, no listing: `ProviderException`.
- A body that is not JSON: `ProviderException` `... answered something that is not JSON ...`.
- A network failure: `UnreachableException` `GitHub could not be reached for ...`.

## Verified, and not

`Tests/GitHubSponsorsProviderTest.php`, on recorded answers (the shapes of live answers of
2026-10-24, the people invented): the profile from the listing, the tiers from the same answer, a
goal counted in sponsors, every sponsor page after page, a limit asking no more than it needs, an
account GitHub does not know, an account without a listing, a bad token, the factory's required
options, the provider built. Green in the family's harness on 2026-10-26. Live (README,
2026-10-24, a token): glitchr-studio's listing; sindresorhus's tiers and two sponsors
(`Tests/LiveTest.php`, group `live`, needs `OMNIFUND_GITHUB_TOKEN`). Not verified live: an active
goal (none at hand).

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by GitHub, Inc. Whoever runs it complies with GitHub's [Terms of
Service](https://docs.github.com/en/site-policy/github-terms/github-terms-of-service), the
[GitHub Sponsors additional terms](https://docs.github.com/en/site-policy/github-terms/github-sponsors-additional-terms)
and the [GraphQL rate limits](https://docs.github.com/en/graphql/overview/rate-limits-and-node-limits-for-the-graphql-api)
(5,000 points an hour per token); see the README's Responsibility section.
