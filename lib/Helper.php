<?php

/**
 * PANDI EPP registrar module for WHMCS
 *
 * Copyright (C) 2023-2026 PANDI (Pengelola Nama Domain Internet Indonesia) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace WHMCS\Module\Registrar\Pandiwhmcs;

/**
 * Stateless helpers for turning WHMCS input into RFC-conformant EPP values and
 * flattened EPP responses back into predictable PHP arrays.
 */
class Helper
{
    /**
     * Printable ASCII (RFC 9154 section 4.1) minus characters that tend to break
     * copy/paste, HTML forms or naive registry validators: space " ' < > & \ `
     * 87 characters, so 20 characters carry ~129 bits of entropy.
     */
    const AUTHINFO_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!#$%()*+,-./:;=?@[]^_{|}~';

    const AUTHINFO_DEFAULT_LENGTH = 20;

    /**
     * Domain name to send to the registry: the A-label (punycode) form for IDNs
     * (RFC 5891), lower case, no trailing dot.
     *
     * @param array $params WHMCS module parameters
     *
     * @return string
     */
    public static function domainName(array $params)
    {
        if (!empty($params['is_idn']) && !empty($params['domain_punycode'])) {
            return self::hostName($params['domain_punycode']);
        }

        return self::hostName($params['sld'] . '.' . ltrim((string) $params['tld'], '.'));
    }

    /**
     * Normalise a host or domain name: trim, drop trailing dot, convert U-labels
     * to A-labels, lower case.
     *
     * @param string $name
     *
     * @return string
     */
    public static function hostName($name)
    {
        $name = rtrim(trim((string) $name), '.');
        if ($name !== '' && preg_match('/[^\x00-\x7F]/', $name) && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($name, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
            if ($ascii !== false) {
                $name = $ascii;
            }
        }

        return strtolower($name);
    }

    /**
     * Nameservers from WHMCS params (ns1..ns5), normalised and de-duplicated.
     *
     * @param array $params
     *
     * @return string[]
     */
    public static function paramNameservers(array $params)
    {
        $hosts = [];
        for ($i = 1; $i <= 5; ++$i) {
            if (!empty($params['ns' . $i])) {
                $hosts[] = self::hostName($params['ns' . $i]);
            }
        }

        return array_values(array_unique(array_filter($hosts)));
    }

    /**
     * Wrap a flattened EPP value into a list. DOMTools returns a scalar or an
     * associative array for a single element and a list for repeated elements.
     *
     * @param mixed $value
     *
     * @return array
     */
    public static function toList($value)
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }
        if (!is_array($value)) {
            return [$value];
        }
        if (array_keys($value) === range(0, count($value) - 1)) {
            return array_values($value);
        }

        return [$value];
    }

    /**
     * Status values (RFC 5731 section 2.3) of a flattened <domain:infData>.
     *
     * @param array $infData
     *
     * @return string[]
     */
    public static function statuses(array $infData)
    {
        $statuses = [];
        foreach (self::toList(isset($infData['status']) ? $infData['status'] : null) as $status) {
            if (is_string($status) && $status !== '') {
                $statuses[] = $status;
            }
        }

        return $statuses;
    }

    /**
     * RGP status values (RFC 3915 section 3.1) of a flattened <domain:infData>.
     *
     * @param array $infData
     *
     * @return string[]
     */
    public static function rgpStatuses(array $infData)
    {
        $statuses = [];
        foreach (self::toList(isset($infData['rgpStatus']) ? $infData['rgpStatus'] : null) as $status) {
            if (is_string($status) && $status !== '') {
                $statuses[] = $status;
            }
        }

        return $statuses;
    }

    /**
     * Delegated nameservers of a flattened <domain:infData>, supporting both the
     * host object and the host attribute model (RFC 5731 section 1.1).
     *
     * @param array $infData
     *
     * @return string[]
     */
    public static function nameservers(array $infData)
    {
        if (empty($infData['ns']) || !is_array($infData['ns'])) {
            return [];
        }

        $hosts = [];
        foreach (self::toList(isset($infData['ns']['hostObj']) ? $infData['ns']['hostObj'] : null) as $host) {
            $hosts[] = self::hostName($host);
        }
        foreach (self::toList(isset($infData['ns']['hostAttr']) ? $infData['ns']['hostAttr'] : null) as $attr) {
            if (is_array($attr) && !empty($attr['hostName'])) {
                $hosts[] = self::hostName($attr['hostName']);
            }
        }

        return array_values(array_unique(array_filter($hosts)));
    }

    /**
     * Contact IDs of one type (admin/tech/billing) of a flattened <domain:infData>.
     *
     * @param array  $infData
     * @param string $type
     *
     * @return string[]
     */
    public static function contactIds(array $infData, $type)
    {
        $key = 'contact@' . $type;

        return array_values(array_filter(self::toList(isset($infData[$key]) ? $infData[$key] : null), 'is_string'));
    }

    /**
     * Calendar date (YYYY-MM-DD) of an EPP dateTime. RFC 5730 section 2.4 requires
     * UTC, so the date part of the string is the registry's date.
     *
     * @param string $dateTime
     *
     * @return string|null
     */
    public static function date($dateTime)
    {
        $dateTime = trim((string) $dateTime);
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $dateTime, $matches)) {
            return $matches[1];
        }
        if ($dateTime === '') {
            return null;
        }

        try {
            $date = new \DateTime($dateTime, new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            return null;
        }

        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
    }

    /**
     * Secure random authorization information (RFC 9154 section 4.1), containing
     * at least one upper case letter, lower case letter, digit and symbol because
     * many registries enforce that.
     *
     * @param int $length 8..64, default 20 (~129 bits)
     *
     * @return string
     */
    public static function authInfo($length = self::AUTHINFO_DEFAULT_LENGTH)
    {
        $length = max(8, min(64, (int) $length));
        $chars = self::AUTHINFO_CHARS;
        $max = strlen($chars) - 1;

        do {
            $pw = '';
            for ($i = 0; $i < $length; ++$i) {
                $pw .= $chars[random_int(0, $max)];
            }
        } while (!preg_match('/[A-Z]/', $pw) || !preg_match('/[a-z]/', $pw) || !preg_match('/[0-9]/', $pw) || !preg_match('/[^A-Za-z0-9]/', $pw));

        return $pw;
    }

    /**
     * New contact ID within the RFC 5733 clIDType limits (3-16 characters):
     * up to 8 characters of the registrar ID followed by random characters.
     *
     * @param string $registrarId
     *
     * @return string
     */
    public static function contactId($registrarId)
    {
        $id = substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $registrarId), 0, 8);
        $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
        while (strlen($id) < 16) {
            $id .= $chars[random_int(0, 35)];
        }

        return $id;
    }

    /**
     * Phone number in the RFC 5733 e164StringType format: +CC.NNNNNNNNNN
     *
     * @param string      $phone     number as entered or as formatted by WHMCS
     * @param string|null $defaultCc country calling code to use for national numbers
     *
     * @throws \InvalidArgumentException when the number can not be converted safely
     *
     * @return string empty string when $phone is empty
     */
    public static function phone($phone, $defaultCc = null)
    {
        $phone = trim((string) $phone);
        if ($phone === '') {
            return '';
        }

        $compact = preg_replace('/[\s\-()\/]/', '', $phone);
        if (preg_match('/^\+(\d{1,3})\.(\d{1,14})$/', $compact, $matches)) {
            return '+' . $matches[1] . '.' . $matches[2];
        }

        $digits = preg_replace('/\D/', '', $compact);
        $cc = preg_replace('/\D/', '', (string) $defaultCc);
        if ($cc !== '' && $digits !== '') {
            if (strpos($compact, '+') === 0 || strpos($compact, '00') === 0) {
                if (strpos($compact, '00') === 0) {
                    $digits = substr($digits, 2);
                }
                $digits = strpos($digits, $cc) === 0 ? substr($digits, strlen($cc)) : '';
            } else {
                $digits = ltrim($digits, '0');
            }

            if ($digits !== '' && strlen($digits) <= 14) {
                return '+' . $cc . '.' . $digits;
            }
        }

        throw new \InvalidArgumentException(sprintf('Phone number "%s" must use the format +CC.NNNNNNNNNN, e.g. +62.81234567890', $phone));
    }

    /**
     * Split a full name into WHMCS "First Name" / "Last Name" fields. Joining the
     * two parts with a space gives the original (whitespace-normalised) name.
     *
     * @param string $name
     *
     * @return string[] [first, last]
     */
    public static function splitName($name)
    {
        $parts = preg_split('/\s+/', trim((string) $name), 2);

        return [isset($parts[0]) ? $parts[0] : '', isset($parts[1]) ? $parts[1] : ''];
    }

    /**
     * Mask authorization information and passwords inside EPP XML.
     *
     * @param string $xml
     *
     * @return string
     */
    public static function redactXml($xml)
    {
        return preg_replace(
            '#(<(?:[A-Za-z0-9_.-]+:)?(?:pw|newPW)\b[^>]*>)(.*?)(</(?:[A-Za-z0-9_.-]+:)?(?:pw|newPW)>)#s',
            '$1*****$3',
            (string) $xml
        );
    }

    /**
     * Mask secrets in a (nested) array before it is written to a log.
     *
     * @param mixed $data
     *
     * @return mixed
     */
    public static function redactArray($data)
    {
        if (!is_array($data)) {
            return is_string($data) ? self::redactXml($data) : $data;
        }

        $secretKeys = ['pw', 'newpw', 'password', 'eppcode', 'cert_passphrase', 'authinfo', 'transfersecret'];
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $secretKeys, true) && $value !== '' && $value !== null) {
                $data[$key] = '*****';
            } else {
                $data[$key] = self::redactArray($value);
            }
        }

        return $data;
    }
}
