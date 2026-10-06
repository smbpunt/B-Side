# B-Side

> 100% vibe coded with Claude Code / Opus 5.5 `<high>`

Personal web app connected to a Spotify account: listening stats, playlists generated from those stats, liked tracks cleanup, similar tracks exploration, audio preview on cover hover.

## Run with Docker

> [!WARNING]
> B-Side is vibe coded and has not been security audited. Keep it on your local network, or reach it through a VPN: do not expose it to the Internet.

No need to clone the repository. Everything lives in one `bside/` folder:

```
bside/
├── compose.yaml
├── bside.env       # your settings
├── caddy/          # certificates
└── database/
```

```bash
mkdir -p bside/caddy/data bside/caddy/config bside/database
cd bside
```

In `bside/`, create `bside.env` with your settings and replace every `CHANGE_ME`:

```dotenv
# Your user and group, given by the `id` command: they own the caddy/ folder
PUID=1000
PGID=1000

# Server IP or domain name
SERVER_NAME=127.0.0.1
# Same value as SERVER_NAME
CADDY_GLOBAL_OPTIONS=default_sni 127.0.0.1

# Database password (letters and digits only), written twice
POSTGRES_PASSWORD=CHANGE_ME
DATABASE_URL=postgresql://app:CHANGE_ME@b-side-db:5432/app?serverVersion=18

# Long random text
APP_SECRET=CHANGE_ME

# From the Spotify app (see Installation below)
SPOTIFY_CLIENT_ID=CHANGE_ME
SPOTIFY_CLIENT_SECRET=CHANGE_ME
```

Then `compose.yaml`, in the same folder, nothing to change:

```yaml
services:
  b-side:
    image: smbpunt/b-side-php-prod:latest
    container_name: b-side
    env_file: bside.env
    volumes:
      - ./caddy/data:/data # certificates
      - ./caddy/config:/config
    ports:
      - 80:80 # HTTP
      - 443:443 # HTTPS
      - 443:443/udp # HTTP/3 (optional)
    depends_on:
      - b-side-db
    restart: unless-stopped

  # Background Spotify sync
  b-side-worker:
    image: smbpunt/b-side-php-prod:latest
    container_name: b-side-worker
    command: php bin/console messenger:consume async --memory-limit=128M
    env_file: bside.env
    depends_on:
      b-side:
        condition: service_healthy
    healthcheck:
      disable: true
    restart: unless-stopped

  b-side-db:
    image: postgres:18-alpine
    container_name: b-side-db
    env_file: bside.env
    environment:
      - POSTGRES_USER=app
      - POSTGRES_DB=app
    volumes:
      - ./database:/var/lib/postgresql
    restart: unless-stopped
```

Then `docker compose up -d` from `bside/` and open `https://<SERVER_NAME>`.

In the Spotify app (see [Installation](#installation)), the redirect URIs use the same address: `https://<SERVER_NAME>/api/auth/callback` and `https://<SERVER_NAME>/api/accounts/link/callback`.

### Other ports

In `compose.yaml`, change the left number only, for example `8443:443`. The address becomes `https://<SERVER_NAME>:8443`, Spotify redirect URIs included. With a domain name and a Let's Encrypt certificate, keep 80 and 443.

### Behind your own reverse proxy

If nginx, Traefik, Nginx Proxy Manager or Synology already handles the certificate, B-Side serves plain HTTP behind it. In `bside.env`:

```dotenv
# HTTP only, no certificate
SERVER_NAME=:80
# Trust the proxy
SYMFONY_TRUSTED_PROXIES=private_ranges
# Remove the CADDY_GLOBAL_OPTIONS line, keep the others
```

In `compose.yaml`, replace the three `ports` lines with `- 8080:80`. The proxy forwards `https://<your domain>` to `http://<server>:8080`.

## Stack

- **Backend**: Symfony 8.1 / PHP 8.5, JSON API under `/api`
- **Front**: Angular 22, Tailwind v4, [spartan/ui](https://spartan.ng) (Angular equivalent of shadcn/ui)
- **Database**: PostgreSQL 18
- **Docker**: [symfony-docker](https://github.com/dunglas/symfony-docker) (FrankenPHP + Caddy)
- **Auth**: Spotify OAuth via `knpuniversity/oauth2-client-bundle`, cookie session (Spotify tokens stay server-side)

Single URL: Caddy routes `/api` to Symfony and everything else to the front.

## Requirements

- Docker
- A Spotify **Premium** account (required for an app in development mode)

## Installation

1. Create an app on the [Spotify dashboard](https://developer.spotify.com/dashboard):
   - Redirect URIs: `https://127.0.0.1/api/auth/callback` and `https://127.0.0.1/api/accounts/link/callback` (linked accounts)
   - API: Web API
2. Create `api/.env.local`:

   ```dotenv
   SPOTIFY_CLIENT_ID=xxx
   SPOTIFY_CLIENT_SECRET=xxx
   ```

3. Start:

   ```bash
   make start
   ```

4. Open <https://127.0.0.1> and accept the local certificate.

Use `127.0.0.1`, not `localhost`: Spotify rejects `localhost` as a redirect URI.

To get rid of the certificate warning (macOS):

```bash
docker compose cp php:/data/caddy/pki/authorities/local/root.crt /tmp/caddy-root.crt && sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain /tmp/caddy-root.crt
```

## Commands

`make help` lists everything. The main ones:

- `make start` / `make up` / `make down`: Docker lifecycle
- `make logs`, `make front-logs`, `make worker-logs`: logs
- `make shell`: shell in the PHP container
- `make sf c="debug:router"`: Symfony console
- `make migration` then `make migrate`: Doctrine migrations
- `make ng c="g component features/stats"`: Angular CLI

## Quality

- `make rector-fix` then `make cs-fix`: automatic fixes (in that order)
- `make phpstan-check`: static analysis (level 8)
- `make db-test` (once) then `make phpunit`: backend tests
- `make front-test`: front tests (Vitest)
- `make ci`: run everything

## Structure

- `api/`: Symfony, `Dockerfile` and FrankenPHP config (`api/frankenphp/`)
- `front/`: Angular, spartan components copied into `front/libs/ui/`
- `compose*.yaml`: `php`, `worker`, `database`, `front` (dev) services
- `make/`: Makefile targets
- `docs/`: decisions and constraints

## Documentation

In French:

- [Spotify API: limits and workarounds](docs/spotify-api.md)
- [Architecture](docs/architecture.md)
- [Roadmap](docs/roadmap.md)
