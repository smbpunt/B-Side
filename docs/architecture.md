# Architecture

## Une seule URL

Caddy (intégré à FrankenPHP, conteneur `php`) reçoit toutes les requêtes sur `https://127.0.0.1` :

- `/api/*`, `/_profiler*`, `/_wdt*`, `/bundles/*` : Symfony
- tout le reste : Angular
  - en dev : proxy vers le conteneur `front` (`ng serve`, rechargement à chaud)
  - en prod : fichiers statiques compilés dans l'image, dans `/app/front`

Le choix se fait avec la variable `FRONT_MODE` (`dev` ou `prod` par défaut) dans `api/frankenphp/Caddyfile`.

Avantages : pas de configuration CORS, session en cookie HttpOnly, le callback OAuth arrive directement sur Symfony.

## Connexion Spotify

1. Le front redirige vers `/api/auth/login`, et Symfony renvoie vers Spotify avec les scopes.
2. Spotify revient sur `/api/auth/callback`. `App\Security\SpotifyAuthenticator` récupère les tokens, crée ou met à jour l'utilisateur, ouvre la session, puis redirige vers `/`.
3. Le front appelle `/api/me` pour savoir si l'utilisateur est connecté (guards Angular).
4. Les appels à Spotify passent par `App\Spotify\SpotifyApi`. Le token est rafraîchi automatiquement s'il expire dans moins d'une minute (`SpotifyTokenRefresher`).
5. Déconnexion : `/api/auth/logout`, géré par le firewall.

Les tokens Spotify sont stockés en base (`user`) et ne sont jamais envoyés au front.

## Comptes liés

Pour copier une playlist d'un compte Spotify à un autre, l'utilisateur lie son second compte depuis sa session (page « Comptes liés ») :

1. `/api/accounts/link` renvoie vers Spotify avec `show_dialog=true`, qui demande quel compte autoriser.
2. Spotify revient sur `/api/accounts/link/callback` (client knpu `spotify_link`). Le `state` OAuth prouve que la liaison vient de cette session. Le compte est enregistré comme un `User` avec ses tokens, et lié dans les deux sens (`linked_account`). La session ne change pas.
   - Échec (refus, `state` invalide, compte absent des utilisateurs de l'app) : retour sur `/comptes?error=failed`. Même compte que la session : `?error=same`. Plus de session : `/login`.
3. `GET /api/accounts` liste les comptes liés, `DELETE /api/accounts/{id}` en délie un. Le compte délié reste un `User`, comme après une connexion.
4. `POST /api/playlists/{id}/copy` crée une playlist privée sur un compte lié, avec les titres synchronisés (titres likés compris), puis lance la synchro de ce compte. Un compte non lié répond 404.

Le second compte doit faire partie des 5 utilisateurs de l'app dans le dashboard Spotify, sinon Spotify refuse son profil.

## Import de l'historique étendu

1. Le front envoie chaque `Streaming_History_*.json` sur `POST /api/history`, un fichier par requête (32 Mo max, voir `10-app.ini`).
2. `App\History\StreamingHistoryParser` valide le fichier (422 sinon) et ne garde que les titres : pas de podcasts, ni d'IP, de pays ou d'appareil.
3. Les écoutes partent par lots de 1000 dans Messenger (`ImportPlays`). Le worker les insère en une requête par table et ignore les doublons : l'export en contient, et on peut réimporter sans risque.
4. `GET /api/history` résume ce qui est importé.

Tables :

- `track` : partagée entre utilisateurs, clé = id Spotify du titre
- `play` : une écoute, unique sur `(user_id, played_at, track_id)`. `played_at` est la fin de l'écoute (UTC dans l'export).

## Stats

`GET /api/stats/{overview,tracks,artists,timeline,clock}`, calculées en SQL par `App\Stats\PlayStats` sur les écoutes importées.

- Le morceau est défini dans `App\Stats\Songs`, partagé par `PlayStats`, `SongStats` et `PlaylistStats` : un même morceau est reconnu à son nom et à son artiste, sans la casse (clé `Songs::key()`), et une écoute ne compte qu'au-delà de 30 s (`Songs::PLAYS`). `Songs::with()` fournit les ensembles `listened` (écoutes par morceau) et `playlist_songs` (morceaux des playlists lisibles, `copies` hors likes et `liked`). Les artistes sont aussi comptés et regroupés sans la casse.
- Cache (`App\Stats\StatsCache`, pool `stats.cache`, 24 h) : chaque méthode publique de `PlayStats`, `SongStats` et `PlaylistStats` s'y met elle-même, avec tous ses arguments (`func_get_args()`). Import et synchro vident celui de l'utilisateur ; après une modification d'une requête, `cache:pool:clear stats.cache`.
- La clé d'un titre est fixée par la première source qui l'écrit : `TrackRepository` ne met jamais à jour `name` ni `artist_name` (`ON CONFLICT`).

- Toutes acceptent un `App\Stats\PlayFilter` en query string : `from`, `to` (jours inclus), `artist`, `tz` (fuseau du navigateur, pour les périodes et les heures). Ce même filtre servira à créer des playlists.
- Une écoute ne compte qu'au-delà de 30 secondes, comme chez Spotify. Temps d'écoute et taux d'écoutes passées prennent tout en compte.
- Côté front, le filtre est dans l'URL (`/stats?year=2021&artist=…`) : chaque vue le modifie par un simple lien.
- Pochettes : `track.image_url` (300 px, chargée au défilement), enregistrée à la synchro des playlists et renvoyée avec chaque titre. Pour un titre jamais vu dans une playlist (stats, absents des playlists), `GET /api/artwork/track/{id}` la demande à Spotify puis la garde dans `track` ; à réserver aux listes courtes (`trackArtwork()` côté front).
- Photos d'artistes : `GET /api/artwork/artist?name=…` redirige vers l'image Spotify (404 sans image). Une requête Spotify par image, gardée 30 jours en cache (`App\Stats\Artwork`).
- Extraits au survol : `GET /api/preview/track/{id}` redirige vers le MP3 Deezer (404 sans extrait). Titre retrouvé chez Deezer par son ISRC (pris chez Spotify), sinon par son nom et son artiste, correspondance gardée 30 jours (absence comprise), URL 10 min (`App\Stats\Preview`, `App\Deezer\DeezerApi`). Côté front, `PreviewPlayer` et la directive `appPreview`.

## Playlists

1. `POST /api/playlists/sync` envoie `SyncPlaylists` au worker. `App\Playlist\PlaylistSync` lit `/me/playlists`, puis le contenu des seules playlists dont le `snapshot_id` a changé (50 titres par requête).
2. Spotify ne donne le contenu qu'au propriétaire et aux collaborateurs : les playlists suivies sont gardées avec `readable = false`, sans contenu. Un refus (403) n'est redemandé qu'à la modification suivante.
3. Les titres likés (`GET /me/tracks`) sont rangés comme une playlist à part, `spotify_id = 'liked'` (`Playlist::LIKED`). Sans `snapshot_id`, leur première page (total et 50 derniers likes) sert d'empreinte : une seule requête quand rien n'a changé, sinon relecture complète en la réutilisant. Un 403 ou 404 les passe en `readable = false`.
4. Chaque titre est enregistré avec sa durée, sa pochette (`track.image_url`) et sa sortie (`track.album_type`, `track.album_tracks`), qui sert à garder la version album parmi les doublons.
5. Les playlists retirées de la bibliothèque sont supprimées. `user.playlists_synced_at` change à la fin de chaque synchro : le front s'en sert pour savoir qu'elle est terminée.

Tables :

- `playlist` : une par utilisateur et playlist Spotify, unique sur `(user_id, spotify_id)`
- `playlist_track` : contenu, clé `(playlist_id, position)`. Les titres rejoignent la table `track`, qui garde aussi leur durée.

Stats (`App\Playlist\PlaylistStats`) : `GET /api/playlists`, `/overview`, `/{id}/tracks`, `/duplicates`, et `/missing` (titres les plus écoutés absents des playlists, accepte le `PlayFilter`).

- Un titre de playlist et une écoute sont rapprochés par nom et artiste, sans la casse : un même morceau a souvent plusieurs ids Spotify (single, album, compilation).
- L'historique donne l'artiste de l'album : côté playlist, on prend aussi celui de l'album.
- Comme pour les stats d'écoute, un titre n'est « écouté » qu'au-delà de 30 secondes.
- Les titres likés comptent comme une playlist, sauf dans le nombre de playlists de `/overview` et pour les doublons.

## Nettoyage

`App\Playlist\PlaylistCleanup`, appelé par `POST /api/playlists/remove` (plusieurs playlists, `targets: [{playlistId, tracks}]`), `POST /api/playlists/{id}/remove` (une seule, `tracks`) et `POST /api/removals/restore`.

0. Chaque titre visé arrive avec sa position et son id Spotify (`tracks: [{position, id}]`). Un titre qui n'est plus à cette position (la synchro a renuméroté la playlist) reste en place et compte dans `skipped` : la réponse est `{removed, skipped}`.

1. Rien n'est définitif : un titre retiré est d'abord ajouté à la playlist privée « B-Side · Corbeille », créée au premier retrait (`user.trash_playlist_id`). La synchro l'ignore, et l'oublie si elle a disparu de la bibliothèque : une autre est créée au retrait suivant.
2. Chaque titre retiré est noté dans `removal`, avec sa playlist (id et nom), sa position et sa date d'ajout. Les titres d'un même retrait partagent leur `removed_at`. `GET /api/removals` donne le journal.
3. Traitement par lots de 40 (la limite de `/me/library`) : corbeille, retrait de la source, journal. Après une erreur, chaque lot retiré est dans le journal.
4. Spotify retire toutes les occurrences d'un titre d'une playlist : celles qu'on garde sont remises à leur position.
5. Le contenu en base est mis à jour tout de suite, puis une seule synchro, quel que soit le nombre de playlists, réaligne positions et versions.
6. Remise en place : en tête des likes, ou à la fin de sa playlist si elle existe encore. Spotify ne permet pas de rendre la date d'ajout d'origine. Le titre quitte la corbeille, sauf s'il y est pour un autre retrait.

Côté front :

- Page d'une playlist, bouton « Nettoyer » : des règles présélectionnent les titres, à décocher à la main. Elles sont dans l'URL (`?added=6&never=1&idle=24&skip=60&starts=3`, voir `cleanup-rules.ts`), comme les seuils des titres passés, via `urlState()` (`core/url-state.ts`) : recopiées sans navigation, et ramenées dans les bornes des curseurs. Les durées se comptent jusqu'à la dernière écoute importée, pas jusqu'à aujourd'hui.
- Titres à garder : un titre décoché est enregistré dans `kept_track` (par playlist, `POST /api/playlists/{id}/kept`) et reste décoché aux nettoyages suivants, quelles que soient les règles. Page `/playlists/{id}/a-garder` pour revoir la liste et rendre des titres au nettoyage.
- Page `/playlists/passes` (`GET /api/playlists/skipped`) : les derniers morceaux passés parmi ceux des playlists et des likes, avec leur nombre total de passages, filtrés par deux seuils activables et cumulables, nombre de passages et part des écoutes passées (`SkipFilter`, `?skips=3&rate=60` dans l'URL). Un morceau coché est retiré de toutes les playlists qui le contiennent, toutes versions et likes compris, en une requête.
- Page `/journal` : les retraits, à remettre en place un par un ou en entier.
- Les pages qui retirent passent par `removal()` (`features/playlists/removal.ts`) : après un retrait, cases et bouton restent verrouillés jusqu'à l'arrivée de la liste relue, pour ne jamais viser des positions périmées.

## Doublons

`GET /api/playlists/{id}/versions` regroupe les versions d'un même morceau dans la playlist (`App\Playlist\SongVersions`), testé sur de vraies playlists :

- Même morceau : même titre de base (sans ce qui suit « - » ni ce qui est entre parenthèses, sauf un remix), et même artiste ou même durée à 2 s près. La durée rattrape les collaborations et compilations rangées sous un autre artiste d'album ; sans elle, deux artistes différents sont presque toujours des homonymes.
- Même enregistrement : en plus, même titre aux mentions sans effet près (feat., Original Mix, Radio Edit, Remastered…) et même durée à 2 s près. Un instrumental, un extended ou une autre durée sont une autre version.
- Un remix (Remix, Rework, Bootleg, VIP, « X Mix » hors Original, Extended, Radio…) est un autre morceau que l'original et que les autres remix : deux copies du même remix restent des doublons.

Page `/playlists/{id}/doublons` : une version gardée par groupe (la plus anciennement ajoutée par défaut), les autres versions du même enregistrement cochées pour être retirées, les autres versions aussi sur demande. Le retrait passe par la corbeille et le journal.

## Services Docker

- `php` : FrankenPHP (Symfony en mode worker + Caddy). Au démarrage, il lance `composer install` si besoin et joue les migrations.
- `worker` : même image, `messenger:consume async`, pour les tâches longues comme l'import de l'historique. À redémarrer (`make worker-restart`) après une modification d'un handler.
- `database` : PostgreSQL 18
- `front` : dev uniquement, Node 24 + `ng serve`. Ses `node_modules` sont dans un volume Docker.

## Build de prod

```bash
APP_SECRET=… SPOTIFY_CLIENT_ID=… SPOTIFY_CLIENT_SECRET=… \
  docker compose -f compose.yaml -f compose.prod.yaml build
```

L'étape `front_builder` du `api/Dockerfile` compile Angular à partir du contexte de build nommé `front` (`additional_contexts` dans `compose.prod.yaml`), puis le résultat est copié dans l'image FrankenPHP finale.

### Publication sur Docker Hub

`make release v=1.2.3` crée et pousse le tag (depuis `main` propre et à jour, version jamais publiée). Un tag `v*` lance `.github/workflows/docker.yml` : `make ci` et `make front-test`, puis l'image `b-side-php-prod` (amd64 et arm64) est publiée sur Docker Hub. `latest` ne suit que les versions finales (`v1.2.3`, pas `v1.2.3-rc.1`).

À configurer dans le dépôt GitHub : la variable `DOCKERHUB_USERNAME` et le secret `DOCKERHUB_TOKEN`.

## Choix

- **Angular + spartan/ui** plutôt que React + shadcn : même principe (composants copiés dans le projet et modifiables, Tailwind), spartan est stable depuis la 1.0 de juin 2026.
- **symfony-docker** repris presque tel quel : Mercure et Vulcain retirés, PostgreSQL ajouté, routage front et API dans le Caddyfile.
- **Node 24 dans Docker** : Angular 22 ne supporte pas Node 25.
- **Messenger** dès le départ pour l'import de l'historique étendu ; Scheduler à ajouter pour la relève horaire des derniers titres écoutés.
