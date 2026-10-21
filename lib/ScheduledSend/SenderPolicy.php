<?php

namespace ScheduledSend;

/**
 * Decides which sender addresses a user may schedule messages from.
 *
 * Neither Plesk's sendmail wrapper nor its default Postfix setup checks that the
 * sender belongs to the submitting user, so the plugin has to do it.
 */
class SenderPolicy
{
    /** Only the login address (the mailbox the user signed in with). */
    public const LOGIN = 'login';

    /** Any of the user's Roundcube identities. Only safe with a restrictive identities_level. */
    public const IDENTITIES = 'identities';

    /**
     * @param string   $mode       One of the class constants
     * @param string   $login      Login address of the user
     * @param string[] $identities Email addresses of the user's identities
     *
     * @return string[] Allowed addresses, lower-cased
     */
    public static function allowed(string $mode, string $login, array $identities = []): array
    {
        $allowed = [$login];

        if ($mode === self::IDENTITIES) {
            $allowed = array_merge($allowed, $identities);
        }
        else if ($mode !== self::LOGIN) {
            throw new \InvalidArgumentException("Unknown sender policy: $mode");
        }

        return array_values(array_unique(array_map([self::class, 'normalize'], $allowed)));
    }

    /**
     * True if every given address is allowed. An empty address list is never allowed.
     *
     * @param string[] $addresses
     * @param string[] $allowed
     */
    public static function permits(array $addresses, array $allowed): bool
    {
        if (empty($addresses)) {
            return false;
        }

        foreach ($addresses as $address) {
            if (!in_array(self::normalize($address), $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    public static function normalize(string $address): string
    {
        return strtolower(trim($address));
    }
}
