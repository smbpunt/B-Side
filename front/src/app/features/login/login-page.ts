import { Component, inject, input, signal } from '@angular/core';
import { HlmButtonImports } from '@spartan-ng/helm/button';
import { HlmCardImports } from '@spartan-ng/helm/card';
import { HlmCheckboxImports } from '@spartan-ng/helm/checkbox';
import { HlmLabelImports } from '@spartan-ng/helm/label';
import { AuthService } from '../../core/auth.service';

@Component({
  selector: 'app-login-page',
  imports: [HlmButtonImports, HlmCardImports, HlmCheckboxImports, HlmLabelImports],
  template: `
    <main class="grid min-h-dvh place-items-center bg-[radial-gradient(ellipse_at_top,var(--color-emerald-950),transparent_60%)] p-4">
      <section hlmCard class="w-full max-w-sm text-center">
        <div hlmCardHeader>
          <h1 hlmCardTitle class="text-3xl font-bold tracking-tight">B-Side</h1>
          <p hlmCardDescription>Tes stats Spotify, des playlists générées et un peu de ménage dans tes titres likés.</p>
        </div>
        <div hlmCardContent class="flex flex-col gap-3">
          @if (authError()) {
            <p class="text-destructive text-sm" role="alert">La connexion à Spotify a échoué, réessaie.</p>
          }
          <button hlmBtn size="lg" class="bg-[#1db954] text-black hover:bg-[#1ed760]" (click)="auth.login(remember())">
            Se connecter avec Spotify
          </button>
          <div class="flex items-center justify-center gap-2">
            <hlm-checkbox inputId="remember" [checked]="remember()" (checkedChange)="remember.set($event)" />
            <label hlmLabel for="remember">Se souvenir de moi (30 jours)</label>
          </div>
        </div>
      </section>
    </main>
  `,
})
export class LoginPage {
  protected readonly auth = inject(AuthService);
  protected readonly remember = signal(false);

  /** Query param ajouté par Symfony quand l'OAuth échoue. */
  readonly authError = input<string>(undefined, { alias: 'auth_error' });
}
