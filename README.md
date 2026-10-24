# omnifund/github-sponsors

GitHub Sponsors for [omniscient/omnifund](https://github.com/glitchr-studio/omnifund), under any
PHP host: a user's or an organization's listing read through GitHub's GraphQL API - its page,
its goal, its tiers, the number of sponsors and the public ones.

```php
use Omnifund\GitHubSponsors\GitHubSponsorsFactory;
use Omnifund\Registry;

$registry = new Registry([new GitHubSponsorsFactory($httpClient)], [
    'github' => ['factory' => 'github-sponsors', 'options' => ['account' => 'glitchr-studio', 'token' => $token]],
]);
$registry->sponsors('github')->sponsors();
```

Options: `account` (required), `token` (required: GitHub answers no anonymous GraphQL call; any
token reads public listings - a fine-grained token with no permission is enough), `endpoint`
(GitHub Enterprise: `https://HOST/api/graphql`), `user_agent`.

- **Profile**: the listing's name, short description and page, the account's picture, the
  number of sponsors (private ones included, as GitHub counts them), the active goal (an amount
  in dollars a month, or a number of sponsors, and its percentage). No income: GitHub shows it
  to the account alone.
- **Sponsors**: the public ones, 100 a page; a private sponsor is never listed. No tier per
  sponsor: GitHub shows it to the sponsor and the maintainer alone.
- **Tiers**: monthly or one-time, in US cents as GitHub prices them, custom amounts marked.

No library is wrapped: no maintained framework-free client covers GitHub Sponsors - a general
GitHub client (knplabs/github-api) would bring its own HTTP stack for two GraphQL queries.

## Terms

GitHub's [Terms of Service](https://docs.github.com/en/site-policy/github-terms/github-terms-of-service),
the [GitHub Sponsors additional terms](https://docs.github.com/en/site-policy/github-terms/github-sponsors-additional-terms)
and the [GraphQL rate limits](https://docs.github.com/en/graphql/overview/rate-limits-and-node-limits-for-the-graphql-api)
(5,000 points an hour per token): cache what you read; the profile and tiers cost one query, each
page of sponsors one more.

## Verified, and not

- Verified on recorded answers (the shapes of live answers of 2026-10-24, the people invented),
  in the core's harness: the profile, an amount goal and a count goal, the tiers from the same
  answer (one query), paging and limits, an unknown account, an account without a listing, a bad
  token, the factory's required options; every request's endpoint, bearer token, User-Agent,
  query and variables; `BareTest`; PHPStan at the highest level.
- Verified live (2026-10-24, a token): glitchr-studio's listing; sindresorhus's tiers and two
  sponsors.
- Not verified live: an active goal (none at hand).

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by GitHub, Inc. Its MIT licence covers this package's code only and grants no right to
the service or its data. Whoever installs and runs it is responsible for complying with GitHub's
terms, for obtaining what they require, and for any use made of what it returns; the authors
accept no liability for how it is used (see [LICENSE](LICENSE)).

## Licence

MIT.
