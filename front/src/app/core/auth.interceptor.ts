import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { NEVER, catchError, throwError } from 'rxjs';
import { AuthService } from './auth.service';

/**
 * Session expirée en cours d'utilisation : on repasse par Spotify, qui accepte sans clic une app déjà autorisée.
 * Un 401 sans utilisateur connu (arrivée sur l'app, déconnexion) mène à la page de connexion, sans reconnexion forcée.
 */
export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const auth = inject(AuthService);
  return next(req).pipe(
    catchError((error: unknown) => {
      if (error instanceof HttpErrorResponse && error.status === 401 && auth.user()) {
        auth.login();
        // La page s'en va : pas d'erreur à afficher entre-temps
        return NEVER;
      }
      return throwError(() => error);
    }),
  );
};
