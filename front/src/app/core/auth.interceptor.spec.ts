import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { authInterceptor } from './auth.interceptor';
import { AuthService } from './auth.service';

describe('authInterceptor', () => {
  function setup(connected: boolean) {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withInterceptors([authInterceptor])),
        provideHttpClientTesting(),
      ],
    });
    const auth = TestBed.inject(AuthService);
    auth.user.set(connected ? { id: 'me', displayName: 'Jane Doe', avatarUrl: null } : null);
    const login = vi.spyOn(auth, 'login').mockImplementation(() => undefined);
    const http = TestBed.inject(HttpTestingController);
    const error = vi.fn();
    TestBed.inject(HttpClient).get('/api/stats').subscribe({ error });
    http.expectOne('/api/stats').flush(null, { status: 401, statusText: 'Unauthorized' });
    return { login, error };
  }

  it('repasse par Spotify quand la session expire en cours de route', () => {
    const { login, error } = setup(true);

    expect(login).toHaveBeenCalledWith();
    expect(error).not.toHaveBeenCalled();
  });

  it('laisse passer le 401 sans utilisateur connu (arrivée, déconnexion)', () => {
    const { login, error } = setup(false);

    expect(login).not.toHaveBeenCalled();
    expect(error).toHaveBeenCalled();
  });
});
