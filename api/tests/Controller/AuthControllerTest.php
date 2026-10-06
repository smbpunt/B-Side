<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

class AuthControllerTest extends WebTestCase
{
    use SpotifyOAuthMock;

    public function testLoginRedirectsToSpotifyWithScopes(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/auth/login');

        self::assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('https://accounts.spotify.com/authorize', $location);
        self::assertStringContainsString('client_id=test-client-id', $location);
        self::assertStringContainsString('scope=user-top-read%20user-read-recently-played', $location);
        self::assertStringContainsString(urlencode('/api/auth/callback'), $location);
    }

    public function testCallbackSavesTheAccountAndOpensTheSession(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->get(EntityManagerInterface::class)->createQuery('DELETE FROM ' . User::class)->execute();

        $client->request('GET', '/api/auth/login');
        parse_str((string) parse_url((string) $client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $query);
        $this->mockSpotifyOAuth('spotify', 'me', 'Jane Doe');
        $client->request('GET', '/api/auth/callback', ['code' => 'code', 'state' => $query['state']]);

        self::assertResponseRedirects('/');
        self::assertSame('me-access-token', static::getContainer()->get(UserRepository::class)->findOneBySpotifyId('me')?->getAccessToken());
        $client->request('GET', '/api/me');
        self::assertJsonStringEqualsJsonString('{"id":"me","displayName":"Jane Doe","avatarUrl":null}', (string) $client->getResponse()->getContent());
    }

    public function testRememberMeKeepsTheUserLoggedInOnceTheSessionIsGone(): void
    {
        $client = $this->logInThroughSpotify(remember: true);
        $rememberMe = $client->getCookieJar()->get('REMEMBERME');
        self::assertNotNull($rememberMe);

        // Session expirée : seul le cookie remember_me reste
        $client->getCookieJar()->clear();
        $client->getCookieJar()->set($rememberMe);
        $client->request('GET', '/api/me');

        self::assertResponseIsSuccessful();
    }

    public function testLogoutRevokesTheRememberMeCookieServerSide(): void
    {
        $client = $this->logInThroughSpotify(remember: true);
        $rememberMe = $client->getCookieJar()->get('REMEMBERME');
        self::assertNotNull($rememberMe);

        $client->request('POST', '/api/auth/logout');
        self::assertResponseRedirects('/');

        // Copie du cookie gardée ailleurs (vol, autre appareil) : le jeton n'existe plus en base
        $client->getCookieJar()->clear();
        $client->getCookieJar()->set($rememberMe);
        $client->request('GET', '/api/me');

        self::assertResponseStatusCodeSame(401);
    }

    public function testTamperedRememberMeCookieIsRejected(): void
    {
        $client = $this->logInThroughSpotify(remember: true);
        $rememberMe = $client->getCookieJar()->get('REMEMBERME');
        self::assertNotNull($rememberMe);

        $client->getCookieJar()->clear();
        $client->getCookieJar()->set(new Cookie('REMEMBERME', $rememberMe->getValue() . 'x', null, '/', $rememberMe->getDomain()));
        $client->request('GET', '/api/me');

        self::assertResponseStatusCodeSame(401);
    }

    public function testLogoutRefusesGet(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/auth/logout');

        self::assertResponseStatusCodeSame(405);
    }

    public function testNoRememberMeCookieUnlessAsked(): void
    {
        $client = $this->logInThroughSpotify(remember: false);

        self::assertNull($client->getCookieJar()->get('REMEMBERME'));
    }

    public function testApiRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/me');

        self::assertResponseStatusCodeSame(401);
        self::assertJsonStringEqualsJsonString('{"error":"unauthenticated"}', (string) $client->getResponse()->getContent());
    }

    private function logInThroughSpotify(bool $remember): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->get(EntityManagerInterface::class)->createQuery('DELETE FROM ' . User::class)->execute();

        $client->request('GET', '/api/auth/login', $remember ? ['remember' => '1'] : []);
        parse_str((string) parse_url((string) $client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $query);
        $this->mockSpotifyOAuth('spotify', 'me', 'Jane Doe');
        $client->request('GET', '/api/auth/callback', ['code' => 'code', 'state' => $query['state']]);
        self::assertResponseRedirects('/');

        return $client;
    }
}
