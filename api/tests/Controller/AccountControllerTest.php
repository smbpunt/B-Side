<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Jane Doe (`me`) lie son autre compte Spotify (`other`). Spotify (token, puis profil) est simulé derrière le client OAuth.
 */
class AccountControllerTest extends WebTestCase
{
    use SpotifyOAuthMock;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->createQuery('DELETE FROM ' . User::class)->execute();

        $this->user = $this->user('me', 'Jane Doe');
        $this->client->loginUser($this->user);
    }

    public function testLinkAsksSpotifyWhichAccount(): void
    {
        $location = $this->startLink();

        self::assertStringStartsWith('https://accounts.spotify.com/authorize', $location);
        self::assertStringContainsString('show_dialog=true', $location);
        self::assertStringContainsString('scope=user-top-read%20user-read-recently-played', $location);
        self::assertStringContainsString('playlist-modify-private', $location, 'Pour y copier des playlists');
        self::assertStringContainsString(urlencode('/api/accounts/link/callback'), $location);
    }

    public function testCallbackLinksTheOtherAccountAndKeepsTheSession(): void
    {
        $this->authorize('other', 'Jane Pro');

        self::assertResponseRedirects('/comptes');
        $this->client->request('GET', '/api/me');
        self::assertSame('me', $this->json()['id'], 'Toujours connecté avec le premier compte');

        $this->client->request('GET', '/api/accounts');
        self::assertSame([['id' => 'other', 'displayName' => 'Jane Pro', 'avatarUrl' => null]], $this->json());

        $other = static::getContainer()->get(UserRepository::class)->findOneBySpotifyId('other');
        self::assertSame('other-access-token', $other?->getAccessToken());
        self::assertSame(['me'], array_map(static fn (User $account): string => $account->getSpotifyId(), $other->getLinkedAccounts()), 'Dans les deux sens');
    }

    public function testCallbackRejectsAnotherState(): void
    {
        $this->startLink();
        $this->client->request('GET', '/api/accounts/link/callback', ['code' => 'code', 'state' => 'forged']);

        self::assertResponseRedirects('/comptes?error=failed');
        self::assertNull(static::getContainer()->get(UserRepository::class)->findOneBySpotifyId('other'));
    }

    public function testCallbackOfAnAccountMissingFromTheAppUsers(): void
    {
        $this->authorize('other', 'Jane Pro', profileStatus: 403);

        self::assertResponseRedirects('/comptes?error=failed');
        self::assertNull(static::getContainer()->get(UserRepository::class)->findOneBySpotifyId('other'));
    }

    public function testCallbackWithAnExpiredSessionGoesToLogin(): void
    {
        $this->client->request('POST', '/api/auth/logout');
        $this->client->request('GET', '/api/accounts/link/callback', ['code' => 'code', 'state' => 'state']);

        self::assertResponseRedirects('/login');
    }

    public function testCallbackRejectsTheSameAccount(): void
    {
        $this->authorize('me', 'Jane Doe');

        self::assertResponseRedirects('/comptes?error=same');
        $this->client->request('GET', '/api/accounts');
        self::assertSame([], $this->json());
    }

    public function testUnlink(): void
    {
        $this->user->link($this->user('other', 'Jane Pro'));
        $this->em->flush();

        $this->client->request('DELETE', '/api/accounts/other');
        self::assertResponseStatusCodeSame(204);
        $this->client->request('GET', '/api/accounts');
        self::assertSame([], $this->json());

        $this->client->request('DELETE', '/api/accounts/other');
        self::assertResponseStatusCodeSame(404);
    }

    public function testLinkRequiresASession(): void
    {
        $this->client->request('POST', '/api/auth/logout');
        $this->client->request('GET', '/api/accounts/link');

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @return string adresse de Spotify où l'utilisateur est envoyé
     */
    private function startLink(): string
    {
        $this->client->request('GET', '/api/accounts/link');
        self::assertResponseRedirects();

        return (string) $this->client->getResponse()->headers->get('Location');
    }

    /**
     * Va jusqu'au retour de Spotify, où le compte `$spotifyId` a autorisé l'app.
     *
     * @param int $profileStatus 403 quand le compte ne fait pas partie des utilisateurs de l'app
     */
    private function authorize(string $spotifyId, string $displayName, int $profileStatus = 200): void
    {
        parse_str((string) parse_url($this->startLink(), \PHP_URL_QUERY), $query);
        $this->mockSpotifyOAuth('spotify_link', $spotifyId, $displayName, $profileStatus);

        $this->client->request('GET', '/api/accounts/link/callback', ['code' => 'code', 'state' => $query['state']]);
    }

    private function user(string $spotifyId, string $displayName): User
    {
        $user = new User($spotifyId)
            ->setDisplayName($displayName)
            ->updateTokens($spotifyId . '-token', 'refresh-token', new \DateTimeImmutable('+1 hour'));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * @return array<mixed>
     */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
