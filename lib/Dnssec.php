<?php

/**
 * PANDI EPP registrar module for WHMCS
 *
 * Copyright (C) 2023-2026 PANDI (Pengelola Nama Domain Internet Indonesia) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace WHMCS\Module\Registrar\Pandiwhmcs;

use WHMCS\Module\Registrar\Pandiwhmcs\EPP\Frame\Command\Update\Domain as UpdateDomain;

/**
 * DS records through the secDNS-1.1 extension (RFC 5910, DS data interface).
 */
class Dnssec
{
    /**
     * DNSKEY algorithms accepted for new DS records. RFC 8624 section 3.1 forbids
     * signing with 1, 3, 6 and 12; 5, 7 and 10 are NOT RECOMMENDED but still valid.
     */
    const ALGORITHMS = [
        13 => 'ECDSAP256SHA256 (recommended)',
        15 => 'ED25519',
        8 => 'RSASHA256',
        14 => 'ECDSAP384SHA384',
        16 => 'ED448',
        10 => 'RSASHA512 (not recommended)',
        7 => 'RSASHA1-NSEC3-SHA1 (not recommended)',
        5 => 'RSASHA1 (not recommended)',
    ];

    /**
     * Digest types accepted for new DS records, with their hex digest length.
     * RFC 8624 section 3.3: SHA-1 (1) MUST NOT be used for new DS records.
     */
    const DIGEST_TYPES = [
        2 => ['SHA-256 (recommended)', 64],
        4 => ['SHA-384', 96],
    ];

    /**
     * DS records of a flattened <domain:infData> (secDNS:infData is merged into it).
     *
     * @param array $info
     *
     * @return array[] list of ['keyTag', 'alg', 'digestType', 'digest']
     */
    public static function dsRecords(array $info)
    {
        $records = [];
        foreach (Helper::toList(isset($info['dsData']) ? $info['dsData'] : null) as $ds) {
            if (!is_array($ds) || !isset($ds['keyTag'], $ds['alg'], $ds['digestType'], $ds['digest'])) {
                continue;
            }
            $records[] = [
                'keyTag' => (int) $ds['keyTag'],
                'alg' => (int) $ds['alg'],
                'digestType' => (int) $ds['digestType'],
                'digest' => strtoupper((string) $ds['digest']),
            ];
        }

        return $records;
    }

    /**
     * Whether the registry exposes DNSSEC data through the key data interface,
     * which this module does not manage.
     *
     * @param array $info
     *
     * @return bool
     */
    public static function usesKeyData(array $info)
    {
        return !empty($info['keyData']);
    }

    /**
     * Validate a DS record submitted by the client.
     *
     * @param array $input with keyTag, alg, digestType, digest
     * @param bool  $forRemoval existing records are removed exactly as the registry returned them
     *
     * @throws \InvalidArgumentException
     *
     * @return array normalised DS record
     */
    public static function validate(array $input, $forRemoval = false)
    {
        $keyTag = isset($input['keyTag']) ? trim((string) $input['keyTag']) : '';
        $alg = isset($input['alg']) ? trim((string) $input['alg']) : '';
        $digestType = isset($input['digestType']) ? trim((string) $input['digestType']) : '';
        $digest = isset($input['digest']) ? strtoupper(preg_replace('/\s+/', '', (string) $input['digest'])) : '';

        if (!ctype_digit($keyTag) || (int) $keyTag > 65535) {
            throw new \InvalidArgumentException('Key tag must be a number between 0 and 65535');
        }
        if (!ctype_digit($alg) || (int) $alg > 255) {
            throw new \InvalidArgumentException('Invalid algorithm');
        }
        if (!ctype_digit($digestType) || (int) $digestType > 255) {
            throw new \InvalidArgumentException('Invalid digest type');
        }
        if ($digest === '' || !ctype_xdigit($digest)) {
            throw new \InvalidArgumentException('Digest must be hexadecimal');
        }

        if (!$forRemoval) {
            if (!array_key_exists((int) $alg, self::ALGORITHMS)) {
                throw new \InvalidArgumentException(sprintf('Algorithm %d must not be used for new DS records (RFC 8624)', $alg));
            }
            if (!array_key_exists((int) $digestType, self::DIGEST_TYPES)) {
                throw new \InvalidArgumentException(sprintf('Digest type %d must not be used for new DS records (RFC 8624)', $digestType));
            }
            if (strlen($digest) !== self::DIGEST_TYPES[(int) $digestType][1]) {
                throw new \InvalidArgumentException(sprintf(
                    'A %s digest is %d hexadecimal characters long',
                    self::DIGEST_TYPES[(int) $digestType][0],
                    self::DIGEST_TYPES[(int) $digestType][1]
                ));
            }
        }

        return ['keyTag' => (int) $keyTag, 'alg' => (int) $alg, 'digestType' => (int) $digestType, 'digest' => $digest];
    }

    /**
     * @param Connection $connection
     * @param string     $domain
     * @param array      $ds validated DS record
     */
    public static function add(Connection $connection, $domain, array $ds)
    {
        self::requireSupport($connection);

        $frame = new UpdateDomain();
        $frame->setDomain($domain);
        $frame->addSecDNSdsData($ds['keyTag'], $ds['alg'], $ds['digestType'], $ds['digest']);
        $connection->send($frame);
    }

    /**
     * @param Connection $connection
     * @param string     $domain
     * @param array      $ds validated DS record
     */
    public static function remove(Connection $connection, $domain, array $ds)
    {
        self::requireSupport($connection);

        $frame = new UpdateDomain();
        $frame->setDomain($domain);
        $frame->removeSecDNSdsData($ds['keyTag'], $ds['alg'], $ds['digestType'], $ds['digest']);
        $connection->send($frame);
    }

    /**
     * RFC 5730 section 2.9.1.1: an extension can only be used when it was negotiated at login.
     *
     * @param Connection $connection
     */
    public static function requireSupport(Connection $connection)
    {
        if (!$connection->supports(Connection::EXT_SECDNS)) {
            throw new EppException('The registry did not announce DNSSEC (secDNS-1.1, RFC 5910) support in its greeting');
        }
    }
}
