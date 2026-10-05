<?php

namespace App\Tests\Controller;

use App\Entity\Track;
use App\Entity\User;
use App\History\StreamedPlay;
use App\History\StreamingHistoryParser;
use App\Message\ImportPlays;
use App\Message\SyncPlaylists;
use App\MessageHandler\ImportPlaysHandler;
use App\MessageHandler\SyncPlaylistsHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Historique (fixture) : Song A écouté deux fois, Song B passé après 12 s.
 *
 * Playlists sur Spotify :
 * - Road trip : Song B, Song C, un fichier local
 * - Mix : « song a » sous un autre id (autre album), Song C
 * - Découvertes : suivie, d'un autre utilisateur
 * - Collab : collaborative, mais Spotify en refuse le contenu
 * - Titres likés : Song A et Song C
 *
 * Les écritures sur Spotify (corbeille, retraits, likes) sont notées dans $writes.
 */
class PlaylistControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private const string SONG_A = '4uLU6hMCjMI75M1A2tKUQC';
    private const string SONG_A_OTHER_ALBUM = '0000000000000000000001';
    private const string SONG_B = '7ouMYWpwJ422jRcDASZB7P';
    private const string SONG_C = '0000000000000000000003';

    private KernelBrowser $client;

    private MockHttpClient $spotify;

    /** @var array<string, string> snapshot_id de chaque playlist sur Spotify */
    private array $snapshots = ['road-trip' => 'v1', 'mix' => 'v1', 'discover' => 'v1', 'collab' => 'v1'];

    /** @var list<string> playlists que Spotify liste deux fois, comme d'une page à la suivante */
    private array $listedTwice = [];

    /** @var list<array<string, mixed>>|null titres likés sur Spotify, du plus récent au plus ancien, null si refusés */
    private ?array $likes;

    /** @var list<array<string, mixed>> titres ajoutés à la fin de Mix */
    private array $mixExtra = [];

    /** @var list<array{0: string, 1: mixed}> écritures sur Spotify : « méthode chemin?query » et corps JSON */
    private array $writes = [];

    /** @var list<string> token de chaque écriture, pour savoir sur quel compte elle a lieu */
    private array $writeTokens = [];

    /** @var (\Closure(string): bool)|null écritures que Spotify refuse, d'après « méthode chemin?query » */
    private ?\Closure $refuse = null;

    protected function setUp(): void
    {
        // Heure figée : deux retraits d'un même test ont la même date, et le journal garde un ordre stable
        static::mockTime();
        $this->client = static::createClient();
        // Le même conteneur pour les requêtes et les synchros, et donc le même faux Spotify
        $this->client->disableReboot();
        $this->spotify = $this->mockSpotify();
        $this->likes = [
            $this->item(self::SONG_A, 'Song A', 'Artist A', '2026-03-01T10:00:00Z', 'track'),
            $this->item(self::SONG_C, 'Song C', 'Artist C', '2026-01-15T10:00:00Z', 'track'),
        ];

        $em = static::getContainer()->get(EntityManagerInterface::class);
        // Écoutes et playlists sont supprimées avec l'utilisateur (ON DELETE CASCADE)
        foreach ([User::class, Track::class] as $entity) {
            $em->createQuery('DELETE FROM ' . $entity)->execute();
        }

        $user = new User('me')
            ->setDisplayName('Jane Doe')
            ->updateTokens('access-token', 'refresh-token', new \DateTimeImmutable('+1 hour'));
        $em->persist($user);
        $em->flush();
        $this->client->loginUser($user);

        $json = (string) file_get_contents(__DIR__ . '/../fixtures/Streaming_History_Audio_2020-2021.json');
        $plays = static::getContainer()->get(StreamingHistoryParser::class)->parse($json);
        static::getContainer()->get(ImportPlaysHandler::class)(new ImportPlays((int) $user->getId(), $plays));
    }

    public function testSyncIsQueued(): void
    {
        $this->client->request('POST', '/api/playlists/sync');

        self::assertResponseStatusCodeSame(202);
        self::assertEquals([new SyncPlaylists($this->userId())], $this->queued());
    }

    public function testOverviewCrossesPlaylistsWithHistory(): void
    {
        self::assertNull($this->get('/api/playlists/overview')['syncedAt']);

        $this->sync();
        $overview = $this->get('/api/playlists/overview');

        self::assertNotNull($overview['syncedAt']);
        self::assertSame(2, $overview['playlists'], 'Sans les titres likés');
        self::assertSame(2, $overview['unreadable'], 'Playlist suivie et playlist refusée par Spotify');
        self::assertSame(3, $overview['tracks']);
        self::assertSame(2, $overview['neverPlayed'], 'Song B (écouté moins de 30 s) et Song C');
        self::assertSame(1, $overview['duplicates'], 'Song C, et pas Song A, à la fois dans Mix et liké');
    }

    public function testPlaylistsList(): void
    {
        $this->sync();
        $playlists = $this->get('/api/playlists');

        self::assertSame(['Mix', 'Road trip', 'Titres likés'], array_column($playlists, 'name'));

        [$mix, $roadTrip] = $playlists;
        self::assertSame('mix', $mix['id']);
        self::assertSame(2, $mix['tracks']);
        self::assertSame(1, $mix['neverPlayed'], 'Song A est reconnu malgré un autre id et une autre casse');
        self::assertEquals(new \DateTimeImmutable('2021-12-31T23:59:59Z'), new \DateTimeImmutable($mix['lastPlayedAt']));

        self::assertSame(2, $roadTrip['tracks'], 'Le fichier local est ignoré');
        self::assertSame(2, $roadTrip['artists']);
        self::assertSame(400000, $roadTrip['durationMs']);
        self::assertSame(2, $roadTrip['neverPlayed']);
        self::assertEqualsWithDelta(1.0, $roadTrip['skipRate'], 0.001);
        self::assertNull($roadTrip['lastPlayedAt']);
        self::assertEquals(new \DateTimeImmutable('2026-02-01T10:00:00Z'), new \DateTimeImmutable($roadTrip['lastAddedAt']));
    }

    public function testPlaylistTracks(): void
    {
        $this->sync();
        $tracks = $this->get('/api/playlists/mix/tracks');

        self::assertSame([0, 1], array_column($tracks, 'position'));
        self::assertSame([self::SONG_A_OTHER_ALBUM, self::SONG_C], array_column($tracks, 'id'));
        self::assertSame([2, 0], array_column($tracks, 'plays'));
        self::assertNull($tracks[1]['lastPlayedAt']);
        self::assertSame('https://i.scdn.co/image/' . self::SONG_C, $tracks[1]['imageUrl'], 'Pochette gardée à la synchro');

        $this->client->request('GET', '/api/playlists/discover/tracks');
        self::assertResponseStatusCodeSame(404, 'Contenu inconnu');

        $this->client->request('GET', '/api/playlists/nope/tracks');
        self::assertResponseStatusCodeSame(404);
    }

    public function testPlaylist(): void
    {
        $this->sync();

        $mix = $this->get('/api/playlists/mix');
        self::assertSame('Mix', $mix['name']);
        self::assertSame(2, $mix['tracks']);
        self::assertSame('Titres likés', $this->get('/api/playlists/liked')['name']);

        $this->client->request('GET', '/api/playlists/discover');
        self::assertResponseStatusCodeSame(404, 'Contenu inconnu');

        $this->client->request('GET', '/api/playlists/nope');
        self::assertResponseStatusCodeSame(404);
    }

    public function testTrackSkips(): void
    {
        // Song A passé deux fois en plus de ses deux écoutes : passé une fois sur deux
        $this->import([
            $this->play(self::SONG_A, 'Song A', 'Artist A', '2022-06-01T10:00:00Z', 5000, true),
            $this->play(self::SONG_A, 'Song A', 'Artist A', '2022-06-02T10:00:00Z', 5000, true),
        ]);
        $this->sync();

        [$songA, $songC] = $this->get('/api/playlists/mix/tracks');
        self::assertSame([2, 4, 0.5], [$songA['plays'], $songA['starts'], $songA['skipRate']]);
        self::assertSame([0, 0], [$songC['plays'], $songC['starts']]);

        // Song A n'est pas encore passé à la mi-2022
        $songA = $this->get('/api/playlists/mix/tracks?to=2022-05-31')[0];
        self::assertSame([2, 2], [$songA['plays'], $songA['starts']]);
    }

    public function testSkippedSongs(): void
    {
        $this->import([
            $this->play(self::SONG_A, 'Song A', 'Artist A', '2022-06-01T10:00:00Z', 5000, true),
            $this->play(self::SONG_A, 'Song A', 'Artist A', '2022-06-02T10:00:00Z', 5000, true),
            // Passé en dernier, mais dans aucune playlist
            $this->play('0000000000000000000004', 'Song D', 'Artist D', '2022-07-01T10:00:00Z', 5000, true),
        ]);
        $this->sync();

        [$songA, $songB] = $this->get('/api/playlists/skipped');
        self::assertSame([self::SONG_A, 'Song A', 2, 4], [$songA['id'], $songA['name'], $songA['skips'], $songA['starts']]);
        self::assertEquals(new \DateTimeImmutable('2022-06-02T10:00:00Z'), new \DateTimeImmutable($songA['skippedAt']));
        self::assertSame([
            ['id' => 'mix', 'name' => 'Mix', 'tracks' => [['position' => 0, 'id' => self::SONG_A_OTHER_ALBUM]]],
            ['id' => 'liked', 'name' => 'Titres likés', 'tracks' => [['position' => 0, 'id' => self::SONG_A]]],
        ], $songA['playlists'], 'Toutes versions confondues, likes compris');
        self::assertSame([self::SONG_B, 1, 1], [$songB['id'], $songB['skips'], $songB['starts']]);
        self::assertSame([['id' => 'road-trip', 'name' => 'Road trip', 'tracks' => [['position' => 0, 'id' => self::SONG_B]]]], $songB['playlists']);

        self::assertSame([self::SONG_B], array_column($this->get('/api/playlists/skipped?offset=1'), 'id'));

        // Seuils cumulables : Song A passé 2 fois sur 4 (50 %), Song B 1 fois sur 1 (100 %)
        $skipped = fn (string $query): array => array_column($this->get('/api/playlists/skipped?' . $query), 'id');
        self::assertSame([self::SONG_A], $skipped('minSkips=2'));
        self::assertSame([self::SONG_B], $skipped('minRate=60'));
        self::assertSame([self::SONG_A, self::SONG_B], $skipped('minRate=50'));
        self::assertSame([], $skipped('minSkips=2&minRate=60'));

        foreach (['minSkips=0', 'minRate=101'] as $query) {
            $this->client->request('GET', '/api/playlists/skipped?' . $query);
            self::assertResponseStatusCodeSame(404, $query);
        }

        // Retiré de sa seule playlist, il quitte la liste
        $this->remove('road-trip', [0 => self::SONG_B]);
        self::assertSame([self::SONG_A], array_column($this->get('/api/playlists/skipped'), 'id'));
    }

    public function testStatsFollowThePeriod(): void
    {
        $this->sync();

        $tracks = $this->get('/api/playlists/mix/tracks?from=2021-01-01&to=2021-12-31');
        self::assertSame([1, 0], array_column($tracks, 'plays'), 'Seule l\'écoute de 2021');

        self::assertSame(3, $this->get('/api/playlists/overview?from=2022-01-01')['neverPlayed']);
        self::assertSame(2, $this->get('/api/playlists/mix?from=2022-01-01')['neverPlayed']);
        self::assertSame(2, $this->get('/api/playlists?artist=Artist%20B')[0]['neverPlayed'], 'Song A ne compte pas pour Artist B');
    }

    public function testLikedTracksAreAPlaylist(): void
    {
        $this->sync();

        $liked = array_column($this->get('/api/playlists'), null, 'id')['liked'];
        self::assertSame('Jane Doe', $liked['ownerName']);
        self::assertNull($liked['imageUrl']);
        self::assertSame(2, $liked['tracks']);
        self::assertSame(1, $liked['neverPlayed'], 'Song C');
        self::assertEquals(new \DateTimeImmutable('2026-03-01T10:00:00Z'), new \DateTimeImmutable($liked['lastAddedAt']));

        $tracks = $this->get('/api/playlists/liked/tracks');
        self::assertSame([self::SONG_A, self::SONG_C], array_column($tracks, 'id'));
        self::assertSame([2, 0], array_column($tracks, 'plays'));
    }

    public function testRefusedLikedTracks(): void
    {
        $this->likes = null;
        $this->sync();

        self::assertSame(['Mix', 'Road trip'], array_column($this->get('/api/playlists'), 'name'));
        self::assertSame(2, $this->get('/api/playlists/overview')['unreadable'], 'Les likes ne sont pas une playlist suivie');
        $this->client->request('GET', '/api/playlists/liked/tracks');
        self::assertResponseStatusCodeSame(404);
    }

    public function testDuplicatesAndTracksMissingFromPlaylists(): void
    {
        self::assertSame([self::SONG_A], array_column($this->get('/api/playlists/missing'), 'id'));

        $this->sync();

        self::assertSame([[
            'id' => self::SONG_C,
            'name' => 'Song C',
            'artistName' => 'Artist C',
            'imageUrl' => 'https://i.scdn.co/image/' . self::SONG_C,
            'playlists' => ['Mix', 'Road trip'],
        ]], $this->get('/api/playlists/duplicates'));
        self::assertSame([], $this->get('/api/playlists/missing'));
    }

    public function testResyncOnlyReadsChangedPlaylistsAndDropsRemovedOnes(): void
    {
        $this->sync();

        $this->snapshots['mix'] = 'v2';
        unset($this->snapshots['road-trip']);
        self::assertSame(3, $this->sync(), 'La liste, le contenu de Mix seulement, et la première page des likes');
        self::assertSame(['Mix', 'Titres likés'], array_column($this->get('/api/playlists'), 'name'));
    }

    public function testPlaylistListedTwiceBySpotify(): void
    {
        $this->listedTwice = ['mix'];
        $this->sync();

        self::assertSame(['Mix', 'Road trip', 'Titres likés'], array_column($this->get('/api/playlists'), 'name'));
    }

    public function testLikedTracksAreOnlyReadAgainWhenTheyChange(): void
    {
        $this->likes = array_map($this->like(...), range(1, 60));
        $this->sync();

        self::assertSame(2, $this->sync(), 'La liste des playlists, et la première page des likes');

        array_pop($this->likes);
        self::assertSame(3, $this->sync(), 'Like retiré : relecture complète, en réutilisant la première page');
        self::assertCount(59, $this->get('/api/playlists/liked/tracks'));

        array_unshift($this->likes, $this->like(61));
        self::assertSame(3, $this->sync(), 'Like ajouté');
        self::assertSame($this->like(61)['track']['id'], $this->get('/api/playlists/liked/tracks')[0]['id']);
    }

    public function testVersions(): void
    {
        // Mix : Song A, Song C, Song C
        $this->mixExtra = [$this->item(self::SONG_C, 'Song C', 'Artist C', null)];
        $this->sync();

        self::assertSame([], $this->get('/api/playlists/road-trip/versions'));

        [$group] = $this->get('/api/playlists/mix/versions');
        self::assertSame([1, 2], array_column(array_column($group, 'track'), 'position'));
        self::assertSame([1, 1], array_column($group, 'recording'));

        $this->client->request('GET', '/api/playlists/discover/versions');
        self::assertResponseStatusCodeSame(404, 'Contenu inconnu');
    }

    public function testKeptTracks(): void
    {
        $this->sync();
        self::assertSame([], $this->get('/api/playlists/road-trip/kept'));

        // Song A n'est pas dans Road trip, et Song B n'est gardé qu'une fois
        $this->client->jsonRequest('POST', '/api/playlists/road-trip/kept', ['trackIds' => [self::SONG_B, self::SONG_A]]);
        self::assertResponseStatusCodeSame(204);
        $this->client->jsonRequest('POST', '/api/playlists/road-trip/kept', ['trackIds' => [self::SONG_B, self::SONG_C]]);
        $this->sync();

        $kept = $this->get('/api/playlists/road-trip/kept');
        self::assertEqualsCanonicalizing([self::SONG_B, self::SONG_C], array_column($kept, 'id'), 'Gardés malgré la synchro');
        self::assertSame([], $this->get('/api/playlists/mix/kept'), 'Gardés pour cette playlist seulement');

        $this->client->jsonRequest('POST', '/api/playlists/road-trip/kept', ['trackIds' => [self::SONG_B], 'kept' => false]);
        self::assertResponseStatusCodeSame(204);
        self::assertSame([self::SONG_C], array_column($this->get('/api/playlists/road-trip/kept'), 'id'));

        $this->client->jsonRequest('POST', '/api/playlists/road-trip/kept', ['trackIds' => []]);
        self::assertResponseStatusCodeSame(422);
        $this->client->jsonRequest('POST', '/api/playlists/discover/kept', ['trackIds' => [self::SONG_A]]);
        self::assertResponseStatusCodeSame(404, 'Contenu inconnu');
    }

    public function testRemovedTracksGoToTheTrashAndTheJournal(): void
    {
        $this->sync();

        self::assertSame(['removed' => 1, 'skipped' => 1], $this->remove('road-trip', [0 => self::SONG_B, 99 => self::SONG_C]));
        self::assertEquals([new SyncPlaylists($this->userId())], $this->queued(), 'Synchro pour réaligner');
        self::assertSame([
            ['POST me/playlists', ['name' => 'B-Side · Corbeille', 'description' => 'Titres retirés par B-Side, à remettre en place depuis son journal.', 'public' => false]],
            ['POST playlists/trash/items', ['uris' => ['spotify:track:' . self::SONG_B]]],
            ['DELETE playlists/road-trip/items', ['items' => [['uri' => 'spotify:track:' . self::SONG_B]]]],
        ], $this->writes);
        self::assertSame([self::SONG_C], array_column($this->get('/api/playlists/road-trip/tracks'), 'id'), 'Retiré en base sans attendre la synchro');

        [$removal] = $this->get('/api/removals');
        self::assertSame([self::SONG_B, 'Song B', 'road-trip', 'Road trip', 0, null], [
            $removal['trackId'], $removal['name'], $removal['playlistId'], $removal['playlistName'], $removal['position'], $removal['restoredAt'],
        ]);
        self::assertEquals(new \DateTimeImmutable('2026-01-01T10:00:00Z'), new \DateTimeImmutable($removal['addedAt']));

        // La corbeille sert à nouveau, et un titre n'y est mis qu'une fois
        $this->writes = [];
        $this->remove('mix', [1 => self::SONG_C]);
        $this->remove('road-trip', [1 => self::SONG_C]);
        self::assertSame([
            ['POST playlists/trash/items', ['uris' => ['spotify:track:' . self::SONG_C]]],
            ['DELETE playlists/mix/items', ['items' => [['uri' => 'spotify:track:' . self::SONG_C]]]],
            ['DELETE playlists/road-trip/items', ['items' => [['uri' => 'spotify:track:' . self::SONG_C]]]],
        ], $this->writes);
        self::assertCount(3, $this->get('/api/removals'));
    }

    public function testRemovedLikesAreUnliked(): void
    {
        $this->sync();
        $this->remove('liked', [1 => self::SONG_C]);

        self::assertSame(['DELETE me/library?uris=spotify:track:' . self::SONG_C, null], $this->writes[2]);
        self::assertSame([self::SONG_A], array_column($this->get('/api/playlists/liked/tracks'), 'id'));
        self::assertSame('Titres likés', $this->get('/api/removals')[0]['playlistName']);
    }

    public function testKeptCopiesOfARemovedTrackArePutBack(): void
    {
        // Mix : Song A, Song C, Song C
        $this->mixExtra = [$this->item(self::SONG_C, 'Song C', 'Artist C', null)];
        $this->sync();

        $this->remove('mix', [1 => self::SONG_C]);

        self::assertSame([
            ['DELETE playlists/mix/items', ['items' => [['uri' => 'spotify:track:' . self::SONG_C]]]],
            ['POST playlists/mix/items', ['uris' => ['spotify:track:' . self::SONG_C], 'position' => 1]],
        ], \array_slice($this->writes, 2), 'Spotify retire les deux : celui qu\'on garde revient après Song A');
        self::assertSame([0, 2], array_column($this->get('/api/playlists/mix/tracks'), 'position'));
    }

    public function testRestoredTracksGoBackAndLeaveTheTrash(): void
    {
        $this->sync();
        $this->remove('road-trip', [0 => self::SONG_B, 1 => self::SONG_C]);
        $this->remove('liked', [1 => self::SONG_C]);
        $ids = array_column($this->get('/api/removals'), 'id', 'name');

        $this->writes = [];
        self::assertSame(['restored' => 2], $this->post('/api/removals/restore', ['ids' => [$ids['Song B'], $ids['Song C']]]));
        self::assertSame([
            ['PUT me/library?uris=spotify:track:' . self::SONG_C, null],
            ['POST playlists/road-trip/items', ['uris' => ['spotify:track:' . self::SONG_B]]],
            ['DELETE playlists/trash/items', ['items' => [['uri' => 'spotify:track:' . self::SONG_B]]]],
        ], $this->writes, 'Song C reste dans la corbeille : son retrait de Road trip n\'est pas annulé');

        $journal = $this->get('/api/removals');
        self::assertSame([true, false, true], array_map(static fn (array $removal): bool => null !== $removal['restoredAt'], $journal));
        self::assertSame(['restored' => 0], $this->post('/api/removals/restore', ['ids' => [$ids['Song B']]]), 'Déjà remis');
    }

    public function testTracksOfAVanishedPlaylistAreNotRestored(): void
    {
        $this->sync();
        $this->remove('road-trip', [0 => self::SONG_B]);
        unset($this->snapshots['road-trip']);
        $this->sync();

        self::assertSame(['restored' => 0], $this->post('/api/removals/restore', ['ids' => array_column($this->get('/api/removals'), 'id')]));
    }

    public function testTrashIsHiddenAndReplacedOnceDeleted(): void
    {
        $this->sync();
        $this->remove('road-trip', [0 => self::SONG_B]);
        $this->sync();

        self::assertSame(['Mix', 'Road trip', 'Titres likés'], array_column($this->get('/api/playlists'), 'name'));

        unset($this->snapshots['trash']);
        $this->sync();
        $this->writes = [];
        $this->remove('road-trip', [1 => self::SONG_C]);
        self::assertContains('POST me/playlists', array_column($this->writes, 0), 'Nouvelle corbeille');
    }

    public function testTrackNoLongerAtItsPositionIsLeftInPlace(): void
    {
        $this->sync();

        self::assertSame(['removed' => 0, 'skipped' => 1], $this->remove('road-trip', [0 => self::SONG_C]), 'Song B est en 0');
        self::assertSame([], $this->writes);
        self::assertSame([], $this->queued());
        self::assertCount(2, $this->get('/api/playlists/road-trip/tracks'));
    }

    public function testRemovedEverywhereWithASingleSync(): void
    {
        $this->sync();

        self::assertSame(['removed' => 3, 'skipped' => 0], $this->post('/api/playlists/remove', ['targets' => [
            ['playlistId' => 'mix', 'tracks' => self::tracks([1 => self::SONG_C])],
            ['playlistId' => 'road-trip', 'tracks' => self::tracks([1 => self::SONG_C])],
            ['playlistId' => 'liked', 'tracks' => self::tracks([1 => self::SONG_C])],
        ]]));
        self::assertEquals([new SyncPlaylists($this->userId())], $this->queued());
        self::assertSame([
            ['POST playlists/trash/items', ['uris' => ['spotify:track:' . self::SONG_C]]],
            ['DELETE playlists/mix/items', ['items' => [['uri' => 'spotify:track:' . self::SONG_C]]]],
            ['DELETE playlists/road-trip/items', ['items' => [['uri' => 'spotify:track:' . self::SONG_C]]]],
            ['DELETE me/library?uris=spotify:track:' . self::SONG_C, null],
        ], \array_slice($this->writes, 1), 'Mis une seule fois dans la corbeille');
        self::assertCount(3, $this->get('/api/removals'));
    }

    public function testBatchesRemovedBeforeASpotifyErrorAreInTheJournal(): void
    {
        $this->likes = array_map($this->like(...), range(1, 45));
        $this->sync();
        $deletes = 0;
        $this->refuse = static function (string $request) use (&$deletes): bool {
            return str_starts_with($request, 'DELETE me/library') && 2 === ++$deletes;
        };

        $this->client->jsonRequest('POST', '/api/playlists/liked/remove', ['tracks' => self::tracks(array_map(
            static fn (int $i): string => \sprintf('L%021d', $i + 1),
            range(0, 44),
        ))]);

        self::assertResponseStatusCodeSame(500);
        self::assertEquals([new SyncPlaylists($this->userId())], $this->queued(), 'Synchro malgré l\'erreur');
        self::assertCount(40, $this->get('/api/removals'), 'Le premier lot');
        self::assertCount(5, $this->get('/api/playlists/liked/tracks'));
    }

    public function testCreatedPlaylistGetsTheTracksInOrder(): void
    {
        $tracks = [self::SONG_C, self::SONG_A, ...array_map(static fn (int $i): string => \sprintf('L%021d', $i), range(1, 100))];

        $this->client->jsonRequest('POST', '/api/playlists', ['name' => 'Top 4 semaines', 'trackIds' => $tracks]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(['id' => 'new'], json_decode((string) $this->client->getResponse()->getContent(), true));
        self::assertSame([
            ['POST me/playlists', ['name' => 'Top 4 semaines', 'description' => 'Créée par B-Side.', 'public' => false]],
            ['POST playlists/new/items', ['uris' => array_map(static fn (string $id): string => 'spotify:track:' . $id, \array_slice($tracks, 0, 100))]],
            ['POST playlists/new/items', ['uris' => array_map(static fn (string $id): string => 'spotify:track:' . $id, \array_slice($tracks, 100))]],
        ], $this->writes, 'Par lots de 100');
        self::assertEquals([new SyncPlaylists($this->userId())], $this->queued(), 'Synchro pour l\'afficher');
    }

    public function testPlaylistIsCopiedToALinkedAccount(): void
    {
        $this->sync();
        $other = $this->linkAccount();

        $this->client->jsonRequest('POST', '/api/playlists/road-trip/copy', ['accountId' => 'other', 'name' => 'Road trip']);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(['id' => 'new'], json_decode((string) $this->client->getResponse()->getContent(), true));
        self::assertSame([
            ['POST me/playlists', ['name' => 'Road trip', 'description' => 'Créée par B-Side.', 'public' => false]],
            ['POST playlists/new/items', ['uris' => ['spotify:track:' . self::SONG_B, 'spotify:track:' . self::SONG_C]]],
        ], $this->writes, 'Titres synchronisés, sans le fichier local');
        self::assertSame(['other-token', 'other-token'], $this->writeTokens, 'Sur l\'autre compte');
        self::assertEquals([new SyncPlaylists((int) $other->getId())], $this->queued(), 'Synchro de l\'autre compte');
    }

    public function testCopyOnlyGoesToALinkedAccountFromAReadablePlaylist(): void
    {
        $this->sync();
        $this->linkAccount();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new User('stranger')->setDisplayName('Stranger')->updateTokens('stranger-token', 'refresh-token', new \DateTimeImmutable('+1 hour')));
        $em->flush();

        $this->client->jsonRequest('POST', '/api/playlists/road-trip/copy', ['accountId' => 'stranger', 'name' => 'Road trip']);
        self::assertResponseStatusCodeSame(404, 'Compte non lié');
        $this->client->jsonRequest('POST', '/api/playlists/discover/copy', ['accountId' => 'other', 'name' => 'Découvertes']);
        self::assertResponseStatusCodeSame(404, 'Contenu inconnu');
        $this->client->jsonRequest('POST', '/api/playlists/road-trip/copy', ['accountId' => 'other', 'name' => ' ']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->writes);
    }

    public function testCreateValidation(): void
    {
        $this->client->jsonRequest('POST', '/api/playlists', ['name' => ' ', 'trackIds' => [self::SONG_A]]);
        self::assertResponseStatusCodeSame(422);
        $this->client->jsonRequest('POST', '/api/playlists', ['name' => 'Top', 'trackIds' => []]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->writes);
    }

    public function testRemoveValidation(): void
    {
        $this->sync();

        $this->client->jsonRequest('POST', '/api/playlists/road-trip/remove', ['tracks' => []]);
        self::assertResponseStatusCodeSame(422);
        $this->client->jsonRequest('POST', '/api/playlists/road-trip/remove', ['tracks' => [['position' => 0, 'id' => '']]]);
        self::assertResponseStatusCodeSame(422);
        $this->client->jsonRequest('POST', '/api/playlists/remove', ['targets' => [['playlistId' => 'road-trip', 'tracks' => []]]]);
        self::assertResponseStatusCodeSame(422);
        $this->client->jsonRequest('POST', '/api/playlists/discover/remove', ['tracks' => self::tracks([0 => self::SONG_A])]);
        self::assertResponseStatusCodeSame(404, 'Contenu inconnu');
        $this->client->jsonRequest('POST', '/api/removals/restore', ['ids' => [-1]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->writes);
    }

    /**
     * @param list<StreamedPlay> $plays
     */
    private function import(array $plays): void
    {
        static::getContainer()->get(ImportPlaysHandler::class)(new ImportPlays($this->userId(), $plays));
    }

    private function play(string $id, string $name, string $artist, string $playedAt, int $msPlayed, bool $skipped): StreamedPlay
    {
        return new StreamedPlay($id, $name, $artist, 'Album', new \DateTimeImmutable($playedAt), $msPlayed, $skipped, 'clickrow', $skipped ? 'fwdbtn' : 'trackdone');
    }

    /**
     * @return int nombre de requêtes à Spotify
     */
    private function sync(): int
    {
        $before = $this->spotify->getRequestsCount();
        static::getContainer()->get(SyncPlaylistsHandler::class)(new SyncPlaylists($this->userId()));

        return $this->spotify->getRequestsCount() - $before;
    }

    private function mockSpotify(): MockHttpClient
    {
        $mock = new MockHttpClient(fn (string $method, string $url, array $options): JsonMockResponse => 'GET' !== $method ? $this->write($method, $url, $options) : match (strtok($url, '?')) {
            'https://api.spotify.com/v1/me/playlists' => $this->page($url, array_map(fn (string $id): array => [
                'id' => $id,
                'name' => ['road-trip' => 'Road trip', 'mix' => 'Mix', 'discover' => 'Découvertes', 'collab' => 'Collab', 'trash' => 'B-Side · Corbeille'][$id],
                'owner' => \in_array($id, ['road-trip', 'mix', 'trash'], true)
                    ? ['id' => 'me', 'display_name' => 'Jane Doe']
                    : ['id' => 'someone', 'display_name' => 'Someone'],
                'collaborative' => 'collab' === $id,
                'snapshot_id' => $this->snapshots[$id],
                'images' => [],
            ], [...array_keys($this->snapshots), ...$this->listedTwice])),
            'https://api.spotify.com/v1/playlists/road-trip/items' => $this->page($url, [
                $this->item(self::SONG_B, 'Song B', 'Artist B', '2026-01-01T10:00:00Z'),
                $this->item(self::SONG_C, 'Song C', 'Artist C', '2026-02-01T10:00:00Z'),
                ['is_local' => true, 'added_at' => '2026-02-01T10:00:00Z', 'item' => ['type' => 'track', 'id' => null, 'name' => 'Démo']],
            ]),
            'https://api.spotify.com/v1/playlists/mix/items' => $this->page($url, [
                $this->item(self::SONG_A_OTHER_ALBUM, 'song a', 'ARTIST A', '2026-01-01T10:00:00Z'),
                $this->item(self::SONG_C, 'Song C', 'Artist C', null),
                ...$this->mixExtra,
            ]),
            'https://api.spotify.com/v1/me/tracks' => null !== $this->likes ? $this->page($url, $this->likes) : $this->forbidden(),
            default => $this->forbidden(),
        }, 'https://api.spotify.com/v1/');
        static::getContainer()->set('spotify.client', $mock);

        return $mock;
    }

    /**
     * Note l'écriture et y répond comme Spotify. La corbeille créée apparaît dans la bibliothèque, les autres playlists créées sont `new`.
     *
     * @param array<string, mixed> $options
     */
    private function write(string $method, string $url, array $options): JsonMockResponse
    {
        $request = $method . ' ' . substr($url, \strlen('https://api.spotify.com/v1/'));
        $this->writes[] = [$request, isset($options['body']) && '' !== $options['body'] ? json_decode($options['body'], true) : null];
        $this->writeTokens[] = substr($options['normalized_headers']['authorization'][0], \strlen('Authorization: Bearer '));

        if (null !== $this->refuse && ($this->refuse)($request)) {
            return $this->forbidden();
        }

        if ('POST me/playlists' === $request) {
            if ('B-Side · Corbeille' !== json_decode($options['body'], true)['name']) {
                return new JsonMockResponse(['id' => 'new'], ['http_code' => 201]);
            }
            $this->snapshots['trash'] = 'v1';

            return new JsonMockResponse(['id' => 'trash'], ['http_code' => 201]);
        }

        return new JsonMockResponse(['snapshot_id' => 'next'], ['http_code' => 'POST' === $method ? 201 : 200]);
    }

    /**
     * Page demandée par `offset` et `limit`.
     *
     * @param list<array<string, mixed>> $items
     */
    private function page(string $url, array $items): JsonMockResponse
    {
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        $offset = (int) ($query['offset'] ?? 0);
        $limit = (int) ($query['limit'] ?? 20);

        return new JsonMockResponse([
            'items' => \array_slice($items, $offset, $limit),
            'next' => $offset + $limit < \count($items) ? 'next-page' : null,
            'total' => \count($items),
        ]);
    }

    private function forbidden(): JsonMockResponse
    {
        return new JsonMockResponse(['error' => ['status' => 403]], ['http_code' => 403]);
    }

    /**
     * @param 'item'|'track' $key `item` dans le contenu d'une playlist, `track` dans les titres likés
     *
     * @return array<string, mixed>
     */
    private function item(string $id, string $name, string $artist, ?string $addedAt, string $key = 'item'): array
    {
        return [
            'added_at' => $addedAt,
            'is_local' => false,
            $key => [
                'type' => 'track',
                'id' => $id,
                'uri' => 'spotify:track:' . $id,
                'name' => $name,
                'artists' => [['id' => 'artist', 'name' => $artist]],
                'album' => ['name' => 'Album', 'artists' => [['name' => $artist]], 'images' => [['url' => 'https://i.scdn.co/image/' . $id]]],
                'duration_ms' => 200000,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function like(int $i): array
    {
        return $this->item(\sprintf('L%021d', $i), 'Like ' . $i, 'Artist', '2026-01-01T10:00:00Z', 'track');
    }

    /**
     * Lie à Jane Doe son autre compte, `other`.
     */
    private function linkAccount(): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $other = new User('other')->setDisplayName('Jane Pro')->updateTokens('other-token', 'refresh-token', new \DateTimeImmutable('+1 hour'));
        $em->persist($other);
        $em->getRepository(User::class)->findOneBy(['spotifyId' => 'me'])?->link($other);
        $em->flush();

        return $other;
    }

    private function userId(): int
    {
        $user = static::getContainer()->get(EntityManagerInterface::class)->getRepository(User::class)->findOneBy(['spotifyId' => 'me']);

        return (int) $user?->getId();
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<mixed>
     */
    private function post(string $url, array $payload): array
    {
        $this->client->jsonRequest('POST', $url, $payload);
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /**
     * @param array<int, string> $tracks id Spotify des titres vus, par position
     *
     * @return array<mixed>
     */
    private function remove(string $playlistId, array $tracks): array
    {
        return $this->post('/api/playlists/' . $playlistId . '/remove', ['tracks' => self::tracks($tracks)]);
    }

    /**
     * @param array<int, string> $tracks
     *
     * @return list<array{position: int, id: string}>
     */
    private static function tracks(array $tracks): array
    {
        return array_map(static fn (int $position, string $id): array => ['position' => $position, 'id' => $id], array_keys($tracks), $tracks);
    }

    /**
     * @return array<object> messages envoyés au worker
     */
    private function queued(): array
    {
        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');

        return array_map(static fn ($envelope) => $envelope->getMessage(), $transport->getSent());
    }

    /**
     * @return array<mixed>
     */
    private function get(string $url): array
    {
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
