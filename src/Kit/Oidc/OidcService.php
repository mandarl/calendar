<?php

declare(strict_types=1);

namespace Dms\Kit\Oidc;

use Jumbojett\OpenIDConnectClient;
use Jumbojett\OpenIDConnectClientException;

/**
 * Port of src/Auth/OpenIDConnectService.php (whole file; used by UsersController::ssoLogin L141 / oidcCallback L152-L165 and
 * AppController L257-L258) to jumbojett/openid-connect-php 1.0.2 on PHP 8.4.
 *
 * Differences from the Cake-bound original, none of them behavioural:
 *  - no Configure:: / Request / Session objects: config comes from OidcConfig, the access-token store is a callable and the
 *    stored token is passed in as a string;
 *  - an already-built client can be injected (tests do that to capture the redirect that the real client turns into
 *    header()+exit);
 *  - `$userInfo->groups` / `->name` / `->email` use `?? null` (PHP 8 warns "Undefined property" where PHP 7.4 gave a notice).
 */
final class OidcService
{
    private OpenIDConnectClient $oidc;

    /**
     * @param bool $fixGroupsBug false (default) reproduces the baseline: getGroupsFromUserInfo() never returns the groups,
     *                           so SSO users never get admin menus. true returns the parsed list. See the TODO below.
     */
    public function __construct(
        private readonly OidcConfig $config,
        ?OpenIDConnectClient $client = null,
        private readonly bool $fixGroupsBug = false,
    ) {
        self::loadClientQuietly();
        $this->oidc = $client ?? new CapturingOidcClient($config->providerUrl, $config->clientId, $config->clientSecret);
        $this->oidc->setRedirectURL($config->redirectUri);
        $this->oidc->providerConfigParam(['userinfo_endpoint' => $config->userInfoEndpoint]);
    }

    /**
     * openid-connect-php 1.0.2 declares ~12 `Type $x = null` parameters, which PHP 8.4 reports as E_DEPRECATED ("Implicitly
     * marking parameter ... as nullable") when the class file is compiled. The report is harmless but, in a Cake 5 app with
     * debug on, it would be rendered on the first SSO request. Load the class once with those (and only those) deprecations
     * swallowed. Remove this when the library ships explicit nullable types (not the case in 1.0.2, the latest release).
     */
    public static function loadClientQuietly(): void
    {
        if (class_exists(OpenIDConnectClient::class, false)) {
            return;
        }
        set_error_handler(
            static fn (int $no, string $msg, string $file): bool => $no === E_DEPRECATED
                && str_contains(str_replace(chr(92), '/', $file), '/openid-connect-php/'),
        );
        try {
            class_exists(OpenIDConnectClient::class);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Start the login: the library builds the authorization URL and stores state+nonce in $_SESSION.
     *
     * With the default client (CapturingOidcClient) the URL is RETURNED and the caller redirects, e.g.
     * `return $this->redirect($service->authenticate());`. If a stock OpenIDConnectClient was injected, the library calls
     * header('Location: ...') and exit() itself (old behaviour) and this method never returns.
     */
    public function authenticate(): ?string
    {
        $this->oidc->addScope(['openid', 'profile', 'email']);
        $this->oidc->authenticate();

        return $this->oidc instanceof CapturingOidcClient ? $this->oidc->redirectUrl : null;
    }

    /**
     * OIDC callback: exchange ?code=, verify state, id_token signature (RS256 via phpseclib) and claims.
     *
     * @param callable(string):void $storeAccessToken persists the access token for later group refreshes
     *                                                (old code: $request->getSession()->write('oidc_token', $token))
     * @throws OpenIDConnectClientException on any verification failure (a library TypeError on a mismatching scalar `aud` is converted)
     */
    public function authenticateCallback(callable $storeAccessToken): bool
    {
        try {
            $authenticated = $this->oidc->authenticate();
        } catch (\TypeError $e) {
            // openid-connect-php 1.0.2 verifyJWTClaims(): in_array($clientId, $claims->aud) throws a TypeError when the IdP sends
            // a scalar `aud` that does not equal our client id (PHP 7.4 only warned). UsersController::oidcCallback catches
            // `Exception`, not `Error`, so turn it into the library's own exception type.
            throw new OpenIDConnectClientException('Unable to verify JWT claims', 0, $e);
        }
        if ($authenticated) {
            $storeAccessToken((string)$this->oidc->getAccessToken());
        }

        return $authenticated;
    }

    /**
     * @return array<string,mixed>|null the Auth user array, or null when the user info is empty or has no preferred_username
     */
    public function getUserData(): ?array
    {
        $userInfo = $this->oidc->requestUserInfo();
        if (!$userInfo) {
            return null;
        }

        // If preferred_username is empty, the user is not authenticated
        if (empty($userInfo->preferred_username)) {
            return null;
        }

        return [
            'ssologin' => true,
            'ssoprofile' => $this->config->providerUrl . 'account',
            'samaccountname' => $userInfo->preferred_username,
            'name' => $userInfo->name ?? null,
            'email' => $userInfo->email ?? null,
            'groups' => $this->getGroupsFromUserInfo($userInfo->groups ?? null),
        ];
    }

    /**
     * Extract group names from the userinfo `groups` claim, e.g. openLDAP "cn=Members,ou=Security,ou=Groups,dc=dms,dc=local"
     * or Keycloak "/Members".
     *
     * KNOWN BUG, preserved on purpose (baseline rule: characterise, do not fix): the original builds $result and then never
     * returns it, so every caller gets null and SSO users never see the admin menus (handoff section 5, "OIDC users always
     * get no groups"; golden/assertion in the baseline suite).
     * TODO(handoff section 5): once the owner decides to fix this, construct the service with $fixGroupsBug = true (or delete the
     * flag) and also review AppController L257-L262, where a null group list makes the whole permission check return false.
     *
     * @param iterable<string>|null $userInfoGroups
     * @return list<string>|null
     */
    public function getGroupsFromUserInfo(?iterable $userInfoGroups): ?array
    {
        $result = self::parseGroupNames($userInfoGroups);

        return $this->fixGroupsBug ? $result : null;
    }

    /**
     * The intended parsing (what the original loop computed before discarding it).
     *
     * @param iterable<string>|null $userInfoGroups
     * @return list<string>
     */
    public static function parseGroupNames(?iterable $userInfoGroups): array
    {
        $result = [];
        foreach ($userInfoGroups ?? [] as $group) {
            $matches = [];
            preg_match('/(cn=|\/)([^,]+)/', (string)$group, $matches);
            if (isset($matches[2])) {
                $result[] = $matches[2];
            }
        }

        return $result;
    }

    /**
     * AppController L257-L258: re-fetch groups with the stored access token. Always null in baseline mode (same bug).
     *
     * @return list<string>|null
     */
    public function updateGroups(?string $storedAccessToken): ?array
    {
        $this->oidc->setAccessToken((string)$storedAccessToken);
        $groups = $this->oidc->requestUserInfo('groups');

        return $this->getGroupsFromUserInfo(is_iterable($groups) ? $groups : null);
    }
}
