<?php

namespace App\Controller;

use App\Security\SpotifyAuthenticator;
use App\Spotify\SpotifyScopes;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/auth', name: 'api_auth_')]
class AuthController extends AbstractController
{
    #[Route('/login', name: 'login', methods: ['GET'])]
    public function login(Request $request, ClientRegistry $clientRegistry): RedirectResponse
    {
        // Le retour de Spotify ne porte pas la case cochée : SpotifyAuthenticator la relit en session
        $request->getSession()->set(SpotifyAuthenticator::REMEMBER_ME, $request->query->getBoolean('remember'));

        // Spotify attend des scopes séparés par des espaces (league/oauth2-client joint avec des virgules)
        return $clientRegistry->getClient('spotify')->redirect([], ['scope' => implode(' ', SpotifyScopes::ALL)]);
    }

    /**
     * Intercepté par SpotifyAuthenticator : ce code n'est jamais exécuté.
     */
    #[Route('/callback', name: 'callback', methods: ['GET'])]
    public function callback(): never
    {
        throw new \LogicException('Handled by SpotifyAuthenticator.');
    }

    /**
     * Intercepté par le firewall (logout). POST : avec les cookies en SameSite=Lax, un lien ou une image
     * sur un autre site ne peut pas déconnecter l'utilisateur.
     */
    #[Route('/logout', name: 'logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('Handled by the security firewall.');
    }
}
