# Ouvrir l'app à d'autres utilisateurs

Piste étudiée, pas encore lancée.

## Problème

En mode développement, une app Spotify accepte 5 utilisateurs maximum, ajoutés à la main, et toutes les apps d'un même compte développeur partagent un seul quota (voir `spotify-api.md`). Avec notre client ID, on ne peut donc pas partager l'app.

## Idée : chaque utilisateur apporte sa propre app Spotify

L'utilisateur crée sa propre app dans le dashboard Spotify et nous donne son **client ID**. B-Side l'utilise pour toutes ses requêtes. Home Assistant fait la même chose pour son intégration Spotify.

- **Plus de limite à 5** : chaque utilisateur est seul sur son app.
- **Quota individuel** : le quota dépend du compte développeur, donc les `429` d'un utilisateur ne touchent plus les autres.
- **Premium** : l'obligation porte sur le propriétaire de l'app, c'est-à-dire l'utilisateur lui-même.

Les endpoints supprimés restent supprimés : chaque app reste en mode développement.

## Client ID seul, avec PKCE

Avec le flux OAuth PKCE, Spotify n'exige pas le client secret, ni à la connexion ni au rafraîchissement du token. Le client ID n'est pas un secret.

- Aucun secret de tiers stocké en base.
- L'utilisateur ne partage rien de confidentiel (les Developer Terms demandent de garder le secret pour soi).
- Un champ de moins à l'inscription.

## Parcours utilisateur

1. Création d'un compte B-Side : email et mot de passe.
2. Guide pas à pas : créer une app sur [developer.spotify.com](https://developer.spotify.com/dashboard), cocher Web API et Web Playback SDK, coller l'URL de redirection affichée par B-Side.
3. Saisie du client ID.
4. Bouton « Lier mon compte Spotify » : OAuth PKCE avec ce client ID.

Spotify devient un compte lié, plus le moyen de se connecter : il faut savoir quel client ID utiliser avant de lancer l'OAuth.

## Changements techniques

- **`User`** : ajout de l'email, du mot de passe hashé et du client ID Spotify. `spotifyId` devient nullable tant que le compte n'est pas lié.
- **`security.yaml`** : connexion par formulaire (`json_login`), provider sur l'email.
- **`SpotifyAuthenticator`** : remplacé par un contrôleur de liaison (`/api/spotify/connect` et `/api/spotify/callback`) qui rattache les tokens à l'utilisateur déjà connecté.
- **`knpu_oauth2_client.yaml`** : plus de client ID global en variable d'environnement. Le provider est construit à la volée avec le client ID de l'utilisateur et PKCE activé (`pkceMethod: S256`).
- **`SpotifyTokenRefresher`** : rafraîchit avec le client ID de l'utilisateur.
- **Front** : pages d'inscription, de connexion, de guide et de liaison ; les guards vérifient aussi que Spotify est lié.
- **Caches** (titres, pochettes, extraits) : restent communs, ce sont des données publiques du catalogue.

## Risques

- **Inscription lourde** : environ 5 minutes avec le guide. Faisable pour un cercle de personnes motivées, bloquant pour le grand public.
- **Conditions de Spotify** : elles interdisent de contourner les limites. Un service qui centralise les apps de plusieurs utilisateurs est en zone grise : relire les [Developer Terms](https://developer.spotify.com/terms) avant d'ouvrir largement.
- **Règles changeantes** : Spotify a déjà durci le mode développement plusieurs fois (novembre 2024, mai 2025, février 2026).

## Alternative

Chacun héberge sa propre instance avec sa propre app (Docker Compose déjà en place). Aucun changement de code, mais il faut un serveur.
