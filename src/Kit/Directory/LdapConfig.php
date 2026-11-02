<?php

declare(strict_types=1);

namespace Dms\Kit\Directory;

/**
 * The 'ActiveDirectory' block of config/app.default.php (L371-L381):
 *
 *   account_suffix  AD_SUFFIX     (unused by the LDAP code; kept for completeness)
 *   admin_username  AD_BIND_UN    service-account DN used for lookups
 *   admin_password  AD_BIND_PW
 *   username_field  'sAMAccountName'
 *   use_tls         AD_USE_TLS    (default true)  => StartTLS on the plain LDAP port, NOT ldaps://
 *   base_dn         AD_BASE
 *   domain_controllers [AD_SERVER]
 *
 * IMPORTANT (LdapRecord 4): the meaning of the `use_tls` connection option changed there (it now means ldaps://, and
 * StartTLS became `use_starttls`). This kit pins LdapRecord ^3.8 where `use_tls` still means StartTLS, and
 * LdapDirectoryTest::testStartTlsIsActuallyNegotiated guards against a silent semantic change on a future major bump.
 */
final class LdapConfig
{
    /**
     * @param list<string> $hosts
     */
    public function __construct(
        public readonly array $hosts,
        public readonly string $baseDn,
        public readonly string $bindUsername,
        public readonly string $bindPassword,
        public readonly bool $useStartTls = true,
        public readonly string $usernameField = 'sAMAccountName',
        public readonly bool $verifyServerCertificate = false,
        public readonly ?int $port = null,
        public readonly int $timeout = 5,
    ) {
    }

    /**
     * @param array<string,string|false|null> $env AD_SERVER, AD_BASE, AD_BIND_UN, AD_BIND_PW, AD_USE_TLS
     */
    public static function fromEnv(array $env): self
    {
        $useTls = $env['AD_USE_TLS'] ?? 'true';

        return new self(
            [(string)($env['AD_SERVER'] ?? 'dms.local')],
            (string)($env['AD_BASE'] ?? 'DC=dev,DC=com'),
            (string)($env['AD_BIND_UN'] ?? ''),
            (string)($env['AD_BIND_PW'] ?? ''),
            filter_var($useTls, FILTER_VALIDATE_BOOLEAN),
        );
    }

    /**
     * The array passed to `new \LdapRecord\Connection()`. `options` reproduces the old code: certificate verification
     * is disabled (LDAP_OPT_X_TLS_REQUIRE_CERT => LDAP_OPT_X_TLS_NEVER) unless $verifyServerCertificate is set.
     *
     * @return array<string,mixed>
     */
    public function toConnectionArray(): array
    {
        $options = [
            LDAP_OPT_X_TLS_REQUIRE_CERT => $this->verifyServerCertificate ? LDAP_OPT_X_TLS_DEMAND : LDAP_OPT_X_TLS_NEVER,
        ];

        $config = [
            'hosts' => $this->hosts,
            'base_dn' => $this->baseDn,
            'username' => $this->bindUsername,
            'password' => $this->bindPassword,
            'use_tls' => $this->useStartTls, // LdapRecord 2.x/3.x: StartTLS
            'version' => 3,
            'timeout' => $this->timeout,
            'options' => $options,
        ];
        if ($this->port !== null) {
            $config['port'] = $this->port;
        }

        return $config;
    }
}
