<?php
declare(strict_types=1);

namespace App\Auth;

use Cake\Core\Configure;
use Dms\Kit\Directory\LdapConfig;
use Dms\Kit\Directory\LdapDirectory;
use Dms\Kit\Oidc\OidcConfig;
use Dms\Kit\Oidc\OidcService;
use Dms\Kit\Sms\TwilioConfig;
use Dms\Kit\Sms\TwilioSmsSender;
use Cake\Log\Log;
use Psr\Log\LoggerInterface;

/**
 * Builds the libkit services (src/Kit, namespace Dms\Kit) from the app's `ActiveDirectory` and `OIDC` config blocks,
 * which themselves come from the unchanged AD_* / OIDC_* / BASE_URL environment variables.
 *
 * LdapDirectory replaces the three inline LdapRecord connections of the Cake 3 build (AdAuthenticate,
 * AppController::_refreshAdGroups, the AD group assignment in EventsController); OidcService replaces
 * OpenIDConnectService.
 */
class KitFactory
{
    /**
     * @return \Dms\Kit\Directory\LdapDirectory
     */
    public static function directory(): LdapDirectory
    {
        $cfg = Configure::read('ActiveDirectory');

        return new LdapDirectory(
            new LdapConfig(
                hosts: $cfg['domain_controllers'],
                baseDn: $cfg['base_dn'],
                bindUsername: $cfg['admin_username'],
                bindPassword: $cfg['admin_password'],
                useStartTls: (bool)$cfg['use_tls'],
                usernameField: $cfg['username_field'],
            ),
            self::logger(),
        );
    }

    /**
     * @return \Dms\Kit\Oidc\OidcService
     */
    public static function oidc(): OidcService
    {
        $cfg = Configure::read('OIDC');

        // fixGroupsBug: true is a deliberate, owner-approved deviation from the Cake 3 build (handoff doc section 5).
        // Cake 3's getGroupsFromUserInfo() never returned its result, so SSO users had no groups and never saw the
        // admin menus; now they get the groups parsed from the IdP's userinfo.
        return new OidcService(
            new OidcConfig(
            providerUrl: $cfg['url_authorize'],
            clientId: $cfg['client_id'],
            clientSecret: $cfg['client_secret'],
            redirectUri: $cfg['redirect_uri'],
            userInfoEndpoint: $cfg['url_resource_owner_details'],
            ),
            fixGroupsBug: true,
        );
    }

    /**
     * Single construction site for the Twilio SMS sender, shared by the
     * cron __sendText and moderation sendCancellationText helpers (fix-loop 1
     * dedup: previously byte-identical fromConfig blocks in both traits).
     */
    public static function smsSender(): TwilioSmsSender
    {
        return TwilioSmsSender::fromConfig(new TwilioConfig(
            (string)Configure::read('Twilio.accountSid'),
            (string)Configure::read('Twilio.authToken'),
            (string)Configure::read('Twilio.phone'),
        ));
    }

    /**
     * PSR-3 logger that writes through Cake's Log.
     *
     * @return \Psr\Log\LoggerInterface
     */
    protected static function logger(): LoggerInterface
    {
        return new class implements LoggerInterface {
            use \Psr\Log\LoggerTrait;

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                Log::write((string)$level, (string)$message);
            }
        };
    }
}
