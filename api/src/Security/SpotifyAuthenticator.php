<?php

namespace App\Security;

use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Traite le retour de Spotify sur /api/auth/callback : enregistre le compte, l'ouvre en session,
 * puis renvoie vers le front.
 */
class SpotifyAuthenticator extends OAuth2Authenticator implements AuthenticationEntryPointInterface
{
    /** Clé de session : case « Se souvenir de moi » cochée avant de partir chez Spotify. */
    public const string REMEMBER_ME = 'auth.remember_me';

    public function __construct(
        private readonly ClientRegistry $clientRegistry,
        private readonly SpotifyAccounts $accounts,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return 'api_auth_callback' === $request->attributes->get('_route');
    }

    public function authenticate(Request $request): Passport
    {
        $client = $this->clientRegistry->getClient('spotify');
        $accessToken = $this->fetchAccessToken($client);

        $rememberMe = new RememberMeBadge();
        if (true === $request->getSession()->remove(self::REMEMBER_ME)) {
            $rememberMe->enable();
        }

        return new SelfValidatingPassport(
            new UserBadge($accessToken->getToken(), fn () => $this->accounts->save($client, $accessToken)),
            [$rememberMe],
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return new RedirectResponse('/');
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new RedirectResponse('/?auth_error=1');
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new JsonResponse(['error' => 'unauthenticated'], Response::HTTP_UNAUTHORIZED);
    }
}
