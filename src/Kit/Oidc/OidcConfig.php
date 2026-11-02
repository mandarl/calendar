<?php

declare(strict_types=1);

namespace Dms\Kit\Oidc;

/**
 * The 'OIDC' block of config/app.default.php (L380-L387):
 *
 *   client_id                  OIDC_CLIENT_ID            default 'calendar'
 *   client_secret              OIDC_CLIENT_SECRET
 *   redirect_uri               BASE_URL . '/users/oidc-callback'
 *   url_authorize              OIDC_URL_AUTHORIZE        NOTE: this is the PROVIDER (realm) URL, e.g. https://idp.example.test/realms/DMS/ ;
 *                                                        the library appends /.well-known/openid-configuration to it
 *   url_access_token           OIDC_URL_ACCESS_TOKEN     defined but never read by the service
 *   url_resource_owner_details OIDC_URL_RESOURCE_OWNER   forced as `userinfo_endpoint` via providerConfigParam()
 */
final class OidcConfig
{
    public function __construct(
        public readonly string $providerUrl,
        public readonly string $clientId,
        public readonly string $clientSecret,
        public readonly string $redirectUri,
        public readonly string $userInfoEndpoint,
    ) {
    }

    /** @param array<string,string|false|null> $env */
    public static function fromEnv(array $env): self
    {
        return new self(
            (string)($env['OIDC_URL_AUTHORIZE'] ?? 'http://keycloak:8080/realms/DMS/'),
            (string)($env['OIDC_CLIENT_ID'] ?? 'calendar'),
            (string)($env['OIDC_CLIENT_SECRET'] ?? 'dummy-secret-for-dev-mode'),
            (string)($env['BASE_URL'] ?? 'http://localhost:8000') . '/users/oidc-callback',
            (string)($env['OIDC_URL_RESOURCE_OWNER'] ?? 'http://keycloak:8080/realms/DMS/protocol/openid-connect/userinfo'),
        );
    }
}
