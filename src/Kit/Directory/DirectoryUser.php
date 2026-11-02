<?php

declare(strict_types=1);

namespace Dms\Kit\Directory;

/**
 * A directory user as the app's auth layer sees it (AdAuthenticate::findAdUser return value).
 */
final class DirectoryUser
{
    /**
     * @param array<string,list<string>> $attributes raw LdapRecord attributes (keys lower-cased, values always lists)
     * @param list<string> $groups group `cn` values (first value of each group's cn)
     */
    public function __construct(
        public readonly string $dn,
        public readonly array $attributes,
        public readonly array $groups,
    ) {
    }

    /**
     * The exact array AdAuthenticate::findAdUser() returned and Cake stored in the session:
     *  - every attribute, single-valued ones flattened to a scalar, multi-valued ones left as arrays;
     *  - `ssologin` = false and `groups` = list of cn strings.
     *
     * Beware: the old code copies EVERY attribute, so on the dev OpenLDAP this includes `userpassword` (the hash), which then lands in
     * the session. Pass $excludeAttributes = ['userpassword'] to drop it (real AD never returns the password).
     *
     * @param list<string> $excludeAttributes lower-case attribute names to omit
     * @return array<string,mixed>
     */
    public function toAuthArray(array $excludeAttributes = []): array
    {
        $result = ['ssologin' => false];
        foreach ($this->attributes as $key => $value) {
            if (in_array($key, $excludeAttributes, true)) {
                continue;
            }
            $result[$key] = (is_array($value) && count($value) === 1) ? $value[0] : $value;
        }
        $result['groups'] = $this->groups;

        return $result;
    }
}
