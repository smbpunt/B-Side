import { Component, inject, input, signal } from '@angular/core';
import { NgIcon, provideIcons } from '@ng-icons/core';
import { lucideLoaderCircle } from '@ng-icons/lucide';
import { HlmButtonImports } from '@spartan-ng/helm/button';
import { HlmCardImports } from '@spartan-ng/helm/card';
import { HlmCheckboxImports } from '@spartan-ng/helm/checkbox';
import { HlmLabelImports } from '@spartan-ng/helm/label';
import { AuthService } from '../../core/auth.service';

@Component({
  selector: 'app-login-page',
  imports: [NgIcon, HlmButtonImports, HlmCardImports, HlmCheckboxImports, HlmLabelImports],
  viewProviders: [provideIcons({ lucideLoaderCircle })],
  // Retour arrière depuis Spotify : la page revient du cache navigateur avec le bouton encore bloqué.
  host: { '(window:pageshow)': 'connecting.set(false)' },
  template: `
    <main class="grid min-h-dvh place-items-center bg-[radial-gradient(ellipse_at_top,var(--color-emerald-950),transparent_60%)] p-4">
      <section
        hlmCard
        class="w-full max-w-md text-center motion-safe:animate-in motion-safe:fade-in motion-safe:slide-in-from-bottom-4 motion-safe:duration-500"
      >
        <div hlmCardHeader>
          <div class="flex items-center justify-center gap-3">
            <svg
              viewBox="0 0 64 64"
              class="logo size-12 motion-safe:animate-in motion-safe:fade-in motion-safe:zoom-in-75 motion-safe:delay-200 motion-safe:duration-500 motion-safe:[animation-fill-mode:backwards]"
              aria-hidden="true"
            >
              <defs>
                <linearGradient id="login-logo-g" x1="0" y1="0" x2="1" y2="1">
                  <stop offset="0" stop-color="#25e36f" />
                  <stop offset="1" stop-color="#0db14f" />
                </linearGradient>
              </defs>
              <rect width="64" height="64" rx="14" fill="#070b0c" />
              <circle cx="26.8" cy="27.2" r="22.8" fill="url(#login-logo-g)" />
              <g fill="none" stroke="#070b0c" stroke-linecap="round">
                <path d="M13.2 20.5Q27 15.2 42.3 23.9" stroke-width="4.4" />
                <path d="M13.6 27.5Q26 23.8 39.7 30.5" stroke-width="4" />
                <path d="M14.7 34.5Q25 31.8 35.7 37.1" stroke-width="3.6" />
                <path d="M26 54L32 47.4C35 44.5 37.5 44.6 41.2 40.5S46.5 35 50 34S54.5 31.5 58.4 28" stroke-width="6" stroke-linecap="butt" />
              </g>
              <!-- Les barres bougent comme un égaliseur (styles plus bas) -->
              <g fill="url(#login-logo-g)">
                <rect class="bar" x="27.4" y="52.1" width="5.8" height="6.8" rx="1.6" />
                <rect class="bar" x="35.6" y="48.3" width="6" height="10.6" rx="1.6" />
                <rect class="bar" x="43.8" y="43" width="6.1" height="15.9" rx="1.6" />
                <rect class="bar" x="52.3" y="36.8" width="6.1" height="22.1" rx="1.6" />
              </g>
              <g fill="none" stroke="#25e36f" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                <path d="M32 47.4C35 44.5 37.5 44.6 41.2 40.5S46.5 35 50 34S54.5 31.5 58.4 28" />
                <path d="M53.4 28.2L59.3 27.2L58.4 33" />
              </g>
            </svg>
            <h1 hlmCardTitle class="text-3xl font-bold tracking-tight">B-Side</h1>
          </div>
          <p hlmCardDescription>Tes stats Spotify, des playlists générées et un peu de ménage dans tes titres likés.</p>
        </div>
        <div hlmCardContent class="flex flex-col gap-3">
          @if (authError()) {
            <p class="text-destructive text-sm" role="alert">La connexion à Spotify a échoué, réessaie.</p>
          }
          <button
            hlmBtn
            size="lg"
            class="bg-[#1db954] text-black hover:bg-[#1ed760] motion-safe:transition-transform motion-safe:hover:scale-105"
            [disabled]="connecting()"
            [attr.aria-busy]="connecting()"
            (click)="login()"
          >
            @if (connecting()) {
              <ng-icon name="lucideLoaderCircle" class="motion-safe:animate-spin" />
              Redirection vers Spotify…
            } @else {
              Se connecter avec Spotify
            }
          </button>
          <div class="flex items-center justify-center gap-2">
            <hlm-checkbox inputId="remember" [checked]="remember()" (checkedChange)="remember.set($event)" />
            <label hlmLabel for="remember">Se souvenir de moi (30 jours)</label>
          </div>
        </div>
      </section>
    </main>
  `,
  styles: `
    /* Barres du logo : chacune monte et descend à son rythme, depuis le bas, sans dépasser sa hauteur d'origine. */
    .logo .bar {
      transform-box: fill-box;
      transform-origin: bottom;
    }

    @media (prefers-reduced-motion: no-preference) {
      .logo .bar {
        animation: equalize 0.7s ease-in-out infinite alternate;
      }
      .logo .bar:nth-child(2) {
        animation-duration: 0.5s;
        animation-delay: 0.2s;
      }
      .logo .bar:nth-child(3) {
        animation-duration: 0.8s;
        animation-delay: 0.1s;
      }
      .logo .bar:nth-child(4) {
        animation-duration: 0.6s;
        animation-delay: 0.3s;
      }
    }

    @keyframes equalize {
      from {
        transform: scaleY(0.35);
      }
      to {
        transform: scaleY(1);
      }
    }
  `,
})
export class LoginPage {
  protected readonly auth = inject(AuthService);
  protected readonly remember = signal(false);
  protected readonly connecting = signal(false);

  /** Query param ajouté par Symfony quand l'OAuth échoue. */
  readonly authError = input<string>(undefined, { alias: 'auth_error' });

  protected login(): void {
    this.connecting.set(true);
    this.auth.login(this.remember());
  }
}
