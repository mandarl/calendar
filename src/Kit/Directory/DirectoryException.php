<?php

declare(strict_types=1);

namespace Dms\Kit\Directory;

use RuntimeException;

/**
 * Directory unreachable / bind failure of the SERVICE account / TLS failure. The old AdAuthenticate intended to throw
 * `RuntimeException('Failed to bind to LDAP server. Check Auth configuration settings.')`, but its `catch (Exception $ex)`
 * sat in namespace App\Auth without `use Exception`, so it never matched and raw LdapRecord exceptions escaped instead.
 */
final class DirectoryException extends RuntimeException
{
}
