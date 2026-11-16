<?php

/**
 * PANDI EPP registrar module for WHMCS
 *
 * Copyright (C) 2023-2026 PANDI (Pengelola Nama Domain Internet Indonesia) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace WHMCS\Module\Registrar\Pandiwhmcs;

use WHMCS\Database\Capsule;

/**
 * The few WHMCS database reads/writes the module needs.
 */
class Store
{
    /**
     * When an AuthInfo code handed out to a registrant stops being valid
     * (RFC 9154 section 5.2, registrar-defined TTL).
     */
    const AUTHINFO_TABLE = 'mod_pandiwhmcs_authinfo';

    /**
     * WHMCS' expiry date of a domain, null if unknown.
     *
     * @param int|null $domainId
     * @param string   $name
     *
     * @return string|null YYYY-MM-DD
     */
    public static function domainExpiry($domainId, $name)
    {
        $row = null;
        if (!empty($domainId)) {
            $row = Capsule::table('tbldomains')->where('id', $domainId)->first();
        }
        if (!$row) {
            $row = Capsule::table('tbldomains')->whereIn('domain', self::nameVariants($name))->first();
        }
        if (!$row || empty($row->expirydate) || strpos((string) $row->expirydate, '0000') === 0) {
            return null;
        }

        return substr((string) $row->expirydate, 0, 10);
    }

    /**
     * Set the WHMCS status of a domain.
     *
     * @param string $name A-label or U-label
     * @param string $status
     *
     * @return int number of updated rows
     */
    public static function setDomainStatus($name, $status)
    {
        return Capsule::table('tbldomains')->whereIn('domain', self::nameVariants($name))->update(['status' => $status]);
    }

    /**
     * Remember that an AuthInfo code was disclosed for $name and must be
     * invalidated after $ttlDays.
     *
     * @param string $name
     * @param int    $ttlDays 0 disables tracking
     */
    public static function rememberAuthInfo($name, $ttlDays)
    {
        $ttlDays = (int) $ttlDays;
        if ($ttlDays <= 0) {
            return;
        }

        self::ensureAuthInfoTable();
        Capsule::table(self::AUTHINFO_TABLE)->where('domain', $name)->delete();
        Capsule::table(self::AUTHINFO_TABLE)->insert([
            'domain' => $name,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $ttlDays * 86400),
        ]);
    }

    /**
     * @param string $name
     *
     * @return bool true when a disclosed AuthInfo code has passed its TTL
     */
    public static function authInfoExpired($name)
    {
        if (!Capsule::schema()->hasTable(self::AUTHINFO_TABLE)) {
            return false;
        }

        $row = Capsule::table(self::AUTHINFO_TABLE)->where('domain', $name)->first();

        return $row && (string) $row->expires_at <= gmdate('Y-m-d H:i:s');
    }

    /**
     * @param string $name
     */
    public static function forgetAuthInfo($name)
    {
        if (Capsule::schema()->hasTable(self::AUTHINFO_TABLE)) {
            Capsule::table(self::AUTHINFO_TABLE)->where('domain', $name)->delete();
        }
    }

    private static function ensureAuthInfoTable()
    {
        $schema = Capsule::schema();
        if (!$schema->hasTable(self::AUTHINFO_TABLE)) {
            $schema->create(self::AUTHINFO_TABLE, function ($table) {
                $table->string('domain', 255)->primary();
                $table->dateTime('expires_at');
            });
        }
    }

    /**
     * WHMCS may store an IDN in either form.
     *
     * @param string $name
     *
     * @return string[]
     */
    private static function nameVariants($name)
    {
        $names = [$name];
        if (strpos($name, 'xn--') !== false && function_exists('idn_to_utf8')) {
            $unicode = idn_to_utf8($name, IDNA_NONTRANSITIONAL_TO_UNICODE, INTL_IDNA_VARIANT_UTS46);
            if ($unicode !== false && $unicode !== $name) {
                $names[] = $unicode;
            }
        }

        return $names;
    }
}
