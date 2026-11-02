<?php

declare(strict_types=1);

namespace Dms\Kit\Payments;

/**
 * Reproduces the registration-name split in RegistrationsController.php L120-L128:
 *
 *   $nameParts = explode(' ', $registration->name);
 *   $firstName = $nameParts[0];
 *   $lastName  = '';
 *   for ($i = 1; $i < count($nameParts); $i++) { $lastName .= $nameParts[$i] . ' '; }
 *
 * Quirks kept on purpose (baseline rule: characterise, do not fix):
 *  - the last name carries a TRAILING SPACE ("Instructor A " for "Test Instructor A");
 *  - double spaces in the input produce empty parts, i.e. extra spaces in the last name.
 * Pass $trim = true to get the cleaned value if the team decides to fix it deliberately.
 */
final class NameSplitter
{
    /** @return array{0:string,1:string} [firstName, lastName] */
    public static function split(string $name, bool $trim = false): array
    {
        $parts = explode(' ', $name);
        $first = $parts[0];
        $last = '';
        $count = count($parts);
        for ($i = 1; $i < $count; $i++) {
            $last .= $parts[$i] . ' ';
        }

        return [$first, $trim ? trim($last) : $last];
    }
}
