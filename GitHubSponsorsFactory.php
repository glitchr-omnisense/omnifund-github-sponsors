<?php

namespace Omnifund\GitHubSponsors;

use Omnifund\Config;
use Omnifund\ProviderFactory;
use Omnifund\ProviderInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * GitHub Sponsors, from its options:
 *
 *   account     the user or organization whose listing is read: "glitchr-studio" (required)
 *   token       a GitHub token (required: GitHub's GraphQL API answers no anonymous call). Any token reads
 *               a public listing and its public sponsors; a fine-grained token with no permission is enough
 *   endpoint    https://api.github.com/graphql (GitHub Enterprise: https://HOST/api/graphql)
 *   user_agent  sent with every call (GitHub refuses a request without one)
 */
final class GitHubSponsorsFactory extends ProviderFactory
{
    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnifund.factory_name' => 'github-sponsors',
            'omnifund.factory_title' => 'GitHub Sponsors',
            'omnifund.required_options' => ['account', 'token'],
            'endpoint' => GitHubSponsorsProvider::ENDPOINT,
            'user_agent' => 'omnifund (+https://github.com/glitchr-omnisense/omnifund)',
        ]);
    }

    protected function build(Config $c): ProviderInterface
    {
        return new GitHubSponsorsProvider($this->http, (string) $c->string('account'), (string) $c->string('token'), $c->string('endpoint') ?? GitHubSponsorsProvider::ENDPOINT, (string) $c->string('user_agent'));
    }
}
