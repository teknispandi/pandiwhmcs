<?php

/**
 * PANDI EPP registrar module for WHMCS
 *
 * Copyright (C) 2023-2026 PANDI (Pengelola Nama Domain Internet Indonesia) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace WHMCS\Module\Registrar\Pandiwhmcs;

/**
 * Glue between the WHMCS hook functions and the EPP layer: settings, error
 * handling and logging.
 */
class Module
{
    const NAME = 'pandiwhmcs';

    const AUTHINFO_GENERATE = 'generate';
    const AUTHINFO_REGISTRY = 'registry';

    const CONTACTS_ALL = 'all';
    const CONTACTS_TECH = 'tech';
    const CONTACTS_NONE = 'none';

    /** @var string[] values masked in the WHMCS module log */
    private static $secrets = [];

    /**
     * Run one WHMCS hook: catch every error into ['error' => ...] and log the
     * EPP exchange with secrets masked.
     *
     * @param string   $action   hook name, for the logs
     * @param array    $params   WHMCS module parameters
     * @param callable $callback callable(array $params): mixed
     * @param bool     $rethrow  throw instead of returning ['error' => ...] (hooks that must throw)
     *
     * @throws \Exception only when $rethrow is true
     *
     * @return mixed
     */
    public static function execute($action, array $params, callable $callback, $rethrow = false)
    {
        Connection::$transcript = [];
        self::$secrets = [];
        foreach (['pw', 'eppcode', 'cert_passphrase'] as $key) {
            if (!empty($params[$key])) {
                self::addSecret((string) $params[$key]);
            }
        }

        try {
            $result = $callback($params);
        } catch (\Throwable $e) {
            $result = ['error' => $e->getMessage()];
            Logger::error($action, [
                'domain' => isset($params['sld'], $params['tld']) ? $params['sld'] . '.' . $params['tld'] : null,
                'error' => $e->getMessage(),
                'exception' => get_class($e) . ' at ' . $e->getFile() . ':' . $e->getLine(),
                'transcript' => Connection::$transcript,
            ]);
            if ($rethrow) {
                self::log($action, $params, $result);
                throw $e instanceof \Exception ? $e : new \Exception($e->getMessage(), 0, $e);
            }
        }

        self::log($action, $params, $result);

        return $result;
    }

    /**
     * Mask $value in the WHMCS module log of the current call.
     *
     * @param string $value
     */
    public static function addSecret($value)
    {
        if (is_string($value) && $value !== '') {
            self::$secrets[] = $value;
        }
    }

    /**
     * @param string $action
     * @param array  $params
     * @param mixed  $result
     */
    private static function log($action, array $params, $result)
    {
        $requests = [];
        $responses = [];
        foreach (Connection::$transcript as $pair) {
            $requests[] = $pair[0];
            $responses[] = $pair[1];
        }

        if (function_exists('logModuleCall')) {
            logModuleCall(
                self::NAME,
                $action,
                $requests ? implode("\n", $requests) : Helper::redactArray($params),
                $responses ? implode("\n", $responses) : '',
                Helper::redactArray($result),
                array_values(array_unique(self::$secrets))
            );
        }

        if (!empty($params['TestMode'])) {
            Logger::debug($action, ['result' => $result, 'transcript' => Connection::$transcript]);
        }
    }

    /**
     * Record a line in the WHMCS activity log.
     *
     * @param string $message
     */
    public static function activity($message)
    {
        if (function_exists('logActivity')) {
            logActivity('[' . self::NAME . '] ' . $message, 0);
        }
    }

    /**
     * Names to check for a WHMCS domain search: A-label => TLD as WHMCS knows it.
     *
     * @param array $params CheckAvailability / GetDomainSuggestions parameters
     *
     * @return array
     */
    public static function lookupNames(array $params)
    {
        $label = !empty($params['isIdnDomain']) && !empty($params['punyCodeSearchTerm'])
            ? $params['punyCodeSearchTerm']
            : $params['searchTerm'];

        $lookup = [];
        foreach ((array) $params['tldsToInclude'] as $tld) {
            $tld = ltrim(trim((string) $tld), '.');
            if ($tld !== '') {
                $lookup[Helper::hostName($label . '.' . $tld)] = $tld;
            }
        }

        return $lookup;
    }

    /**
     * @param array $params
     *
     * @return string AUTHINFO_GENERATE (RFC 9154, default) or AUTHINFO_REGISTRY
     */
    public static function authInfoMode(array $params)
    {
        return isset($params['authinfo_mode']) && $params['authinfo_mode'] === self::AUTHINFO_REGISTRY
            ? self::AUTHINFO_REGISTRY
            : self::AUTHINFO_GENERATE;
    }

    /**
     * @param array $params
     *
     * @return int length of generated AuthInfo values, 8..64
     */
    public static function authInfoLength(array $params)
    {
        $length = isset($params['authinfo_length']) && is_numeric($params['authinfo_length'])
            ? (int) $params['authinfo_length']
            : Helper::AUTHINFO_DEFAULT_LENGTH;

        return max(8, min(64, $length));
    }

    /**
     * @param array $params
     *
     * @return int days after which a disclosed AuthInfo is invalidated, 0 = never
     */
    public static function authInfoTtlDays(array $params)
    {
        if (!isset($params['authinfo_ttl']) || trim((string) $params['authinfo_ttl']) === '') {
            return 14;
        }

        return max(0, (int) $params['authinfo_ttl']);
    }

    /**
     * Contact roles linked to a new domain besides the registrant.
     *
     * @param array $params
     *
     * @return string[]
     */
    public static function contactRoles(array $params)
    {
        $mode = isset($params['contact_mode']) ? $params['contact_mode'] : self::CONTACTS_ALL;
        if ($mode === self::CONTACTS_NONE) {
            return [];
        }
        if ($mode === self::CONTACTS_TECH) {
            return ['tech'];
        }

        return ['admin', 'tech', 'billing'];
    }

    /**
     * @param array $params
     *
     * @return bool whether <domain:period> is sent with a transfer request
     */
    public static function sendTransferPeriod(array $params)
    {
        return empty($params['transfer_no_period']);
    }
}
