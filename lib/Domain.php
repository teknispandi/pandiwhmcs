<?php

/**
 * PANDI EPP registrar module for WHMCS
 *
 * Copyright (C) 2023-2026 PANDI (Pengelola Nama Domain Internet Indonesia) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace WHMCS\Module\Registrar\Pandiwhmcs;

use WHMCS\Module\Registrar\Pandiwhmcs\EPP\Extension\Rgp\Update\Domain as RgpUpdateDomain;
use WHMCS\Module\Registrar\Pandiwhmcs\EPP\Frame\Command\Check\Domain as CheckDomain;
use WHMCS\Module\Registrar\Pandiwhmcs\EPP\Frame\Command\Check\Host as CheckHost;
use WHMCS\Module\Registrar\Pandiwhmcs\EPP\Frame\Command\Create\Domain as CreateDomain;
use WHMCS\Module\Registrar\Pandiwhmcs\EPP\Frame\Command\Create\Host as CreateHost;
use WHMCS\Module\Registrar\Pandiwhmcs\EPP\Frame\Command\Delete\Contact as DeleteContact;
use WHMCS\Module\Registrar\Pandiwhmcs\EPP\Frame\Command\Delete\Domain as DeleteDomain;
use WHMCS\Module\Registrar\Pandiwhmcs\EPP\Frame\Command\Info\Domain as InfoDomain;
use WHMCS\Module\Registrar\Pandiwhmcs\EPP\Frame\Command\Renew\Domain as RenewDomain;
use WHMCS\Module\Registrar\Pandiwhmcs\EPP\Frame\Command\Transfer\Domain as TransferDomain;
use WHMCS\Module\Registrar\Pandiwhmcs\EPP\Frame\Command\Update\Domain as UpdateDomain;

/**
 * Domain object operations, RFC 5731 (plus RFC 3915 restore and RFC 9154 AuthInfo handling).
 *
 * Element order matters: the RFC 5731 schema is a sequence, so frames are always
 * built in schema order (e.g. <add> before <rem> before <chg>, <period> before <authInfo>).
 */
class Domain
{
    /** Statuses set for the WHMCS "registrar lock" */
    const LOCK_STATUSES = ['clientTransferProhibited', 'clientDeleteProhibited'];

    /** RFC 3915 section 4.2.5: the two statements a restore report must carry */
    const RGP_STATEMENTS = [
        'This registrar has not restored the Registered Name in order to assume the rights to use or sell the Registered Name for itself or for any third party.',
        'The information in this report is true to best of this registrar\'s knowledge, and this registrar acknowledges that intentionally supplying false information in this report shall constitute an incurable material breach of the Registry-Registrar Agreement.',
    ];

    /**
     * <domain:check> for several names (RFC 5731 section 3.1.1).
     *
     * @param Connection $connection
     * @param string[]   $names A-labels
     *
     * @return array name => ['avail' => bool, 'reason' => string|null]
     */
    public static function check(Connection $connection, array $names)
    {
        $results = [];
        // servers commonly cap the number of names per <check>
        foreach (array_chunk(array_values(array_unique($names)), 10) as $chunk) {
            $frame = new CheckDomain();
            foreach ($chunk as $name) {
                $frame->addDomain($name);
            }

            $data = $connection->send($frame)->data();
            $cds = isset($data['chkData']['cd']) ? Helper::toList($data['chkData']['cd']) : [];
            foreach ($cds as $cd) {
                if (!is_array($cd) || !isset($cd['name']) || !is_string($cd['name'])) {
                    continue;
                }
                $results[strtolower($cd['name'])] = [
                    'avail' => self::xmlTrue(isset($cd['@name']['avail']) ? $cd['@name']['avail'] : null),
                    'reason' => isset($cd['reason']) && is_string($cd['reason']) ? $cd['reason'] : null,
                ];
            }
        }

        return $results;
    }

    /**
     * <domain:info> (RFC 5731 section 3.1.2).
     *
     * @param Connection  $connection
     * @param string      $name
     * @param string|null $authInfo optional, lets a non-sponsoring registrar see full data / verify a code
     * @param string      $hosts    all|del|sub|none
     *
     * @return array flattened <domain:infData>, merged with extension data (secDNS, rgp)
     */
    public static function info(Connection $connection, $name, $authInfo = null, $hosts = 'all')
    {
        $frame = new InfoDomain();
        $frame->setDomain($name, $hosts);
        if ($authInfo !== null && $authInfo !== '') {
            $frame->setAuthInfo($authInfo);
        }

        $data = $connection->send($frame)->data();
        if (empty($data['infData']) || !is_array($data['infData'])) {
            throw new EppException('The registry returned no data for ' . $name);
        }

        return $data['infData'];
    }

    /**
     * Register a domain (RFC 5731 section 3.2.1), creating its contacts first.
     *
     * @param Connection $connection
     * @param array      $params WHMCS module parameters
     *
     * @return array ['code' => int, 'skipped_ns' => string[]]
     */
    public static function create(Connection $connection, array $params)
    {
        $name = Helper::domainName($params);
        $nameservers = Helper::paramNameservers($params);
        $skipped = self::ensureHosts($connection, $name, $nameservers);
        $nameservers = array_values(array_diff($nameservers, $skipped));
        $roles = Module::contactRoles($params);

        $created = [];
        try {
            $registrant = Contact::create($connection, Contact::fromParams($params, ''), $created);
            $other = $roles ? Contact::create($connection, Contact::fromParams($params, 'admin'), $created) : null;

            $frame = new CreateDomain();
            $frame->setDomain($name);
            $frame->setPeriod(self::period($params) . 'y');
            foreach ($nameservers as $host) {
                $frame->addHostObj($host);
            }
            $frame->setRegistrant($registrant);
            if (in_array('admin', $roles, true)) {
                $frame->setAdminContact($other);
            }
            if (in_array('tech', $roles, true)) {
                $frame->setTechContact($other);
            }
            if (in_array('billing', $roles, true)) {
                $frame->setBillingContact($other);
            }
            // RFC 9154 section 5.1: no AuthInfo until a transfer is requested
            $frame->setAuthInfo($connection->supports(Connection::EXT_SECURE_AUTHINFO) ? '' : Helper::authInfo(Module::authInfoLength($params)));

            $response = $connection->send($frame);
        } catch (\Exception $e) {
            // do not leave orphaned contact objects at the registry
            foreach ($created as $id) {
                try {
                    $delete = new DeleteContact();
                    $delete->setId($id);
                    $connection->send($delete);
                } catch (\Exception $ignored) {
                }
            }
            throw $e;
        }

        return ['code' => Connection::code($response), 'skipped_ns' => $skipped];
    }

    /**
     * Renew (RFC 5731 section 3.2.3). Skips the EPP command when the registry
     * already extended the domain (e.g. auto-renew) to the date WHMCS bills for.
     *
     * @param Connection $connection
     * @param array      $params
     *
     * @return array ['renewed' => bool, 'exDate' => string|null, 'code' => int|null]
     */
    public static function renew(Connection $connection, array $params)
    {
        $name = Helper::domainName($params);
        $info = self::info($connection, $name, null, 'none');

        $rgp = Helper::rgpStatuses($info);
        if (in_array('redemptionPeriod', $rgp, true) || in_array('pendingRestore', $rgp, true)) {
            throw new EppException('The domain is in the redemption period; use "Restore Domain (RGP)" instead of a renewal (RFC 3915)');
        }
        if (in_array('pendingDelete', Helper::statuses($info), true)) {
            throw new EppException('The domain is pending deletion at the registry and can no longer be renewed');
        }

        $current = Helper::date(isset($info['exDate']) ? $info['exDate'] : null);
        if ($current === null) {
            throw new EppException('The registry did not return an expiry date for ' . $name);
        }

        $years = self::period($params);
        $local = Store::domainExpiry(isset($params['domainid']) ? $params['domainid'] : null, $name);
        if ($local !== null) {
            $target = (new \DateTime($local, new \DateTimeZone('UTC')))->modify('+' . $years . ' years')->format('Y-m-d');
            if ($current >= $target) {
                return ['renewed' => false, 'exDate' => $current, 'code' => null];
            }
        }

        $frame = new RenewDomain();
        $frame->setDomain($name);
        // <domain:curExpDate> is an xs:date, not a dateTime
        $frame->setCurrentExpirationDate($current);
        $frame->setPeriod($years . 'y');
        $response = $connection->send($frame);
        $data = $response->data();

        return [
            'renewed' => true,
            'exDate' => Helper::date(isset($data['renData']['exDate']) ? $data['renData']['exDate'] : null),
            'code' => Connection::code($response),
        ];
    }

    /**
     * Replace the delegation with the nameservers in $params, sending only the difference.
     *
     * @param Connection $connection
     * @param array      $params
     *
     * @return bool false when nothing had to change
     */
    public static function updateNameservers(Connection $connection, array $params)
    {
        $name = Helper::domainName($params);
        $wanted = Helper::paramNameservers($params);
        if (!$wanted) {
            throw new EppException('At least one nameserver is required');
        }

        $current = Helper::nameservers(self::info($connection, $name, null, 'del'));
        $add = array_values(array_diff($wanted, $current));
        $remove = array_values(array_diff($current, $wanted));
        if (!$add && !$remove) {
            return false;
        }

        $missing = self::ensureHosts($connection, $name, $add);
        if ($missing) {
            throw new EppException(sprintf(
                'Nameserver %s does not exist yet. Register it as a child nameserver with its IP address first.',
                implode(', ', $missing)
            ));
        }

        $frame = new UpdateDomain();
        $frame->setDomain($name);
        foreach ($add as $host) {
            $frame->addHostObj($host);
        }
        foreach ($remove as $host) {
            $frame->removeHostObj($host);
        }
        $connection->send($frame);

        return true;
    }

    /**
     * Add/remove client statuses, sending only what actually changes.
     *
     * @param Connection $connection
     * @param string     $name
     * @param string[]   $add
     * @param string[]   $remove
     *
     * @return bool false when nothing had to change
     */
    public static function updateStatuses(Connection $connection, $name, array $add, array $remove)
    {
        $current = Helper::statuses(self::info($connection, $name, null, 'none'));
        $add = array_values(array_diff($add, $current));
        $remove = array_values(array_intersect($remove, $current));
        if (!$add && !$remove) {
            return false;
        }

        $frame = new UpdateDomain();
        $frame->setDomain($name);
        foreach ($add as $status) {
            $frame->addStatus($status, '');
        }
        foreach ($remove as $status) {
            $frame->removeStatus($status);
        }
        $connection->send($frame);

        return true;
    }

    /**
     * Set (or, with '', unset) the domain AuthInfo (RFC 5731 <domain:chg>, RFC 9154 section 5.2).
     *
     * @param Connection $connection
     * @param string     $name
     * @param string     $authInfo
     */
    public static function changeAuthInfo(Connection $connection, $name, $authInfo)
    {
        $frame = new UpdateDomain();
        $frame->setDomain($name);
        $frame->changeAuthInfo((string) $authInfo);
        $connection->send($frame);
    }

    /**
     * Invalidate a disclosed AuthInfo code once its lifetime is over (RFC 9154
     * section 5.2): unset it when the registry follows RFC 9154, otherwise
     * replace it with a random value nobody knows. Never throws.
     *
     * @param Connection $connection
     * @param array      $params
     * @param string     $name
     * @param string[]   $statuses current domain statuses
     */
    public static function expireAuthInfo(Connection $connection, array $params, $name, array $statuses)
    {
        if (Module::authInfoMode($params) !== Module::AUTHINFO_GENERATE || in_array('pendingTransfer', $statuses, true)) {
            return;
        }

        try {
            if (!Store::authInfoExpired($name)) {
                return;
            }
            $replacement = $connection->supports(Connection::EXT_SECURE_AUTHINFO) ? '' : Helper::authInfo(Module::authInfoLength($params));
            self::changeAuthInfo($connection, $name, $replacement);
            Store::forgetAuthInfo($name);
            Module::activity(sprintf('AuthInfo code of %s expired and was invalidated', $name));
        } catch (\Exception $e) {
            Logger::error('expireAuthInfo', $name . ': ' . $e->getMessage());
        }
    }

    /**
     * @param Connection $connection
     * @param string     $name
     *
     * @return int result code (1001 when the registry starts a pending delete)
     */
    public static function delete(Connection $connection, $name)
    {
        $frame = new DeleteDomain();
        $frame->setDomain($name);

        return Connection::code($connection->send($frame));
    }

    /**
     * Transfer request (RFC 5731 section 3.2.4).
     *
     * @param Connection $connection
     * @param array      $params
     *
     * @return int result code (1001 = pending)
     */
    public static function transferRequest(Connection $connection, array $params)
    {
        $authInfo = self::eppCode($params);
        if ($authInfo === '') {
            throw new EppException('The EPP/auth code is required to request a transfer');
        }

        $frame = new TransferDomain();
        $frame->setOperation('request');
        $frame->setDomain(Helper::domainName($params));
        if (Module::sendTransferPeriod($params)) {
            $frame->setPeriod(self::period($params) . 'y');
        }
        $frame->setAuthInfo($authInfo);

        return Connection::code($connection->send($frame));
    }

    /**
     * Transfer query (RFC 5731 section 3.1.3).
     *
     * @param Connection $connection
     * @param array      $params
     *
     * @return array flattened <domain:trnData>
     */
    public static function transferQuery(Connection $connection, array $params)
    {
        $frame = new TransferDomain();
        $frame->setOperation('query');
        $frame->setDomain(Helper::domainName($params));
        $authInfo = self::eppCode($params);
        if ($authInfo !== '') {
            $frame->setAuthInfo($authInfo);
        }

        $data = $connection->send($frame)->data();

        return isset($data['trnData']) && is_array($data['trnData']) ? $data['trnData'] : [];
    }

    /**
     * Restore a deleted domain during the redemption grace period (RFC 3915
     * sections 4.2.5 and 4.2.6): restore request, then a restore report when the
     * registry requires one (rgpStatus pendingRestore).
     *
     * @param Connection $connection
     * @param array      $params
     *
     * @return string 'restored' or 'reported'
     */
    public static function restore(Connection $connection, array $params)
    {
        if (!$connection->supports(Connection::EXT_RGP)) {
            throw new EppException('The registry did not announce the RGP extension (RFC 3915) in its greeting');
        }

        $name = Helper::domainName($params);
        $info = self::info($connection, $name, null, 'none');
        $rgp = Helper::rgpStatuses($info);

        if (in_array('redemptionPeriod', $rgp, true)) {
            $frame = new RgpUpdateDomain();
            $frame->changeDomain($name);
            $frame->restoreRequest();
            $connection->send($frame);

            $info = self::info($connection, $name, null, 'none');
            $rgp = Helper::rgpStatuses($info);
            if (!in_array('pendingRestore', $rgp, true)) {
                return 'restored';
            }
        }

        if (!in_array('pendingRestore', $rgp, true)) {
            throw new EppException(sprintf(
                'The domain is not in the redemption period (rgpStatus: %s)',
                $rgp ? implode(', ', $rgp) : 'none'
            ));
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $snapshot = self::rgpSnapshot($name, $info);

        $frame = new RgpUpdateDomain();
        $frame->changeDomain($name);
        $frame->restoreReport();
        $frame->setPreData($snapshot);
        $frame->setPostData($snapshot);
        $frame->setDelTime(!empty($info['upDate']) ? $info['upDate'] : $now);
        $frame->setResTime($now);
        $frame->setResReason('Registrant requested restoration of the domain name.');
        foreach (self::RGP_STATEMENTS as $statement) {
            $frame->addStatement($statement);
        }
        $connection->send($frame);

        return 'reported';
    }

    /**
     * Transfer code entered by the customer. With APIVersion 1.1 (see
     * pandiwhmcs_MetaData) WHMCS passes it raw, not HTML-encoded.
     *
     * @param array $params
     *
     * @return string
     */
    public static function eppCode(array $params)
    {
        return isset($params['eppcode']) ? trim((string) $params['eppcode']) : '';
    }

    /**
     * Make sure every host used in a delegation exists as a host object (RFC 5731
     * section 1.1, RFC 5732): external hosts are created without addresses.
     * Hosts subordinate to $domain need glue and are returned instead.
     *
     * @param Connection $connection
     * @param string     $domain
     * @param string[]   $hosts
     *
     * @return string[] subordinate hosts that do not exist yet
     */
    public static function ensureHosts(Connection $connection, $domain, array $hosts)
    {
        if (!$hosts) {
            return [];
        }

        $frame = new CheckHost();
        foreach ($hosts as $host) {
            $frame->addHost($host);
        }
        $data = $connection->send($frame)->data();

        $skipped = [];
        foreach (Helper::toList(isset($data['chkData']['cd']) ? $data['chkData']['cd'] : null) as $cd) {
            if (!is_array($cd) || !isset($cd['name']) || !self::xmlTrue(isset($cd['@name']['avail']) ? $cd['@name']['avail'] : null)) {
                continue;
            }

            $host = Helper::hostName($cd['name']);
            if (self::isSubordinate($host, $domain)) {
                $skipped[] = $host;
                continue;
            }

            $create = new CreateHost();
            $create->setHost($host);
            $connection->send($create);
        }

        return $skipped;
    }

    /**
     * Registration period in years, within the RFC 5731 periodType range (1-99).
     *
     * @param array $params
     *
     * @return int
     */
    public static function period(array $params)
    {
        $years = isset($params['regperiod']) ? (int) $params['regperiod'] : 1;

        return max(1, min(99, $years));
    }

    /**
     * @param string $host
     * @param string $domain
     *
     * @return bool
     */
    public static function isSubordinate($host, $domain)
    {
        $suffix = '.' . $domain;

        return strlen($host) > strlen($suffix) && substr($host, -strlen($suffix)) === $suffix;
    }

    /**
     * @param mixed $value xs:boolean
     *
     * @return bool
     */
    private static function xmlTrue($value)
    {
        return in_array((string) $value, ['1', 'true'], true);
    }

    /**
     * Registration data for <rgp:preData>/<rgp:postData>.
     *
     * @param string $name
     * @param array  $info
     *
     * @return string
     */
    private static function rgpSnapshot($name, array $info)
    {
        $lines = ['Domain: ' . $name];
        if (!empty($info['registrant']) && is_string($info['registrant'])) {
            $lines[] = 'Registrant: ' . $info['registrant'];
        }
        foreach (['admin', 'tech', 'billing'] as $type) {
            foreach (Helper::contactIds($info, $type) as $id) {
                $lines[] = ucfirst($type) . ' contact: ' . $id;
            }
        }
        foreach (Helper::nameservers($info) as $host) {
            $lines[] = 'Nameserver: ' . $host;
        }
        foreach (['crDate' => 'Created', 'exDate' => 'Expires'] as $key => $label) {
            if (!empty($info[$key]) && is_string($info[$key])) {
                $lines[] = $label . ': ' . $info[$key];
            }
        }

        return implode("\n", $lines);
    }
}
