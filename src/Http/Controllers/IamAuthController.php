<?php

declare(strict_types=1);

namespace Nagaland\IamClient\Http\Controllers;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Str;
use Nagaland\IamClient\Events\UserAuthenticated;
use Nagaland\IamClient\Services\NagalandIamManager;
use Nagaland\IamClient\Services\OAuthIamClient;
use Nagaland\IamClient\Services\UserSynchronizer;
use Nagaland\IamClient\Support\Pkce;

final class IamAuthController extends Controller
{
    public function __construct(
        private readonly OAuthIamClient $client,
        private readonly Pkce $pkce,
        private readonly SessionManager $session,
        private readonly AuthFactory $auth,
        private readonly UserSynchronizer $users,
        private readonly ConfigRepository $config,
    ) {}

    public function redirect(): RedirectResponse
    {
        $verifier = $this->pkce->verifier();
        $state = Str::random(40);

        $this->session->put($this->sessionKey('pkce_verifier'), $verifier);
        $this->session->put($this->sessionKey('state'), $state);

        return redirect()->away($this->client->authorizeUrl([
            'response_type' => 'code',
            'client_id' => (string) $this->config->get('nagaland-iam.client_id'),
            'redirect_uri' => (string) $this->config->get('nagaland-iam.redirect_uri'),
            'scope' => 'openid profile email',
            'state' => $state,
            'code_challenge' => $this->pkce->challenge($verifier),
            'code_challenge_method' => 'S256',
        ]));
    }

    public function callback(Request $request): RedirectResponse
    {
        if ($request->string('state')->toString() !== $this->session->pull($this->sessionKey('state'))) {
            $this->session->forget($this->sessionKey('pkce_verifier'));

            return redirect()->route('iam.login')
                ->with('iam_error', 'The IAM login session expired. Please try signing in again.');
        }

        $verifier = (string) $this->session->pull($this->sessionKey('pkce_verifier'));
        $tokens = $this->client->exchangeAuthorizationCode($request->string('code')->toString(), $verifier);
        $iamUser = $this->client->userInfo($tokens);
        $user = $this->users->sync($iamUser);

        $this->session->put($this->sessionKey('tokens'), $tokens->toSession());
        $this->session->put($this->sessionKey('user'), $iamUser->attributes);
        $this->auth->guard()->login($user);

        event(new UserAuthenticated($user, $iamUser));

        return redirect()->intended('/dashboard');
    }

    public function logout(NagalandIamManager $iam): RedirectResponse
    {
        $iam->logout();

        return redirect()->route('home');
    }

    private function sessionKey(string $key): string
    {
        return (string) $this->config->get("nagaland-iam.session.{$key}");
    }
}
