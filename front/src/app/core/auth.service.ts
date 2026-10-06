import { HttpClient } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { Observable, catchError, of, tap } from 'rxjs';
import { Me } from './models';

/**
 * Session portée par un cookie Symfony : le front ne voit jamais les tokens Spotify.
 */
@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly http = inject(HttpClient);

  readonly user = signal<Me | null>(null);

  loadUser(): Observable<Me | null> {
    return this.http.get<Me>('/api/me').pipe(
      tap((user) => this.user.set(user)),
      catchError(() => {
        this.user.set(null);
        return of(null);
      }),
    );
  }

  /** `remember` : cookie de 30 jours, sinon la session s'arrête après 1 h d'inactivité ou à la fermeture du navigateur. */
  login(remember = false): void {
    window.location.assign(remember ? '/api/auth/login?remember=1' : '/api/auth/login');
  }

  /** POST via un formulaire : l'API n'accepte plus la déconnexion en GET, et Symfony redirige ensuite vers `/`. */
  logout(): void {
    const form = document.createElement('form');
    form.method = 'post';
    form.action = '/api/auth/logout';
    document.body.append(form);
    form.submit();
  }
}
