<?php
declare(strict_types=1);

namespace App\Test\TestCase\Auth;

use App\Auth\KitFactory;
use Cake\TestSuite\TestCase;

/**
 * OIDC users get their groups (owner-approved deviation from Cake 3, handoff doc section 5).
 *
 * The Cake 3 OpenIDConnectService::getGroupsFromUserInfo() never returned its result, so SSO users always ended up
 * with no groups and never saw the admin menus. KitFactory::oidc() builds the kit's OidcService with
 * fixGroupsBug = true, which makes it return the parsed group names.
 */
class OidcGroupsTest extends TestCase
{
    public function testServiceBuiltByTheAppReturnsGroups(): void
    {
        $groups = KitFactory::oidc()->getGroupsFromUserInfo([
            'cn=Calendar Admins,ou=Security,ou=Groups,dc=example,dc=test', // OpenLDAP style
            '/Honorarium Admins',                                         // Keycloak group path style
        ]);

        $this->assertSame(['Calendar Admins', 'Honorarium Admins'], $groups);
    }

    public function testNoGroupsClaimGivesAnEmptyList(): void
    {
        $this->assertSame([], KitFactory::oidc()->getGroupsFromUserInfo(null));
    }
}
