<?php

/**
 * PANDI EPP registrar module for WHMCS
 *
 * Copyright (C) 2023-2026 PANDI (Pengelola Nama Domain Internet Indonesia) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace WHMCS\Module\Registrar\Pandiwhmcs;

use WHMCS\Module\Registrar\Pandiwhmcs\EPP\Frame\Command\Create\Host as CreateHost;
use WHMCS\Module\Registrar\Pandiwhmcs\EPP\Frame\Command\Delete\Host as DeleteHost;
use WHMCS\Module\Registrar\Pandiwhmcs\EPP\Frame\Command\Info\Host as InfoHost;
use WHMCS\Module\Registrar\Pandiwhmcs\EPP\Frame\Command\Update\Host as UpdateHost;

/**
 * Host object operations (child nameservers / glue), RFC 5732.
 */
class Host
{
    /**
     * @param Connection $connection
     * @param string     $host
     * @param string[]   $addresses IPv4/IPv6 glue (required for hosts inside the domain)
     */
    public static function create(Connection $connection, $host, array $addresses)
    {
        $frame = new CreateHost();
        $frame->setHost(Helper::hostName($host));
        foreach ($addresses as $address) {
            $frame->addAddr($address);
        }
        $connection->send($frame);
    }

    /**
     * Replace one glue address with another, sending only the difference.
     *
     * @param Connection $connection
     * @param string     $host
     * @param string     $currentAddress may be empty
     * @param string     $newAddress
     *
     * @return bool false when nothing had to change
     */
    public static function changeAddress(Connection $connection, $host, $currentAddress, $newAddress)
    {
        $host = Helper::hostName($host);
        $existing = self::info($connection, $host)['addr'];

        $add = self::containsAddress($existing, $newAddress) ? [] : [$newAddress];
        $remove = [];
        if ($currentAddress !== '' && self::containsAddress($existing, $currentAddress) && !self::sameAddress($currentAddress, $newAddress)) {
            $remove[] = $currentAddress;
        }
        if (!$add && !$remove) {
            return false;
        }

        $frame = new UpdateHost();
        $frame->setHost($host);
        foreach ($add as $address) {
            $frame->addAddr($address);
        }
        foreach ($remove as $address) {
            $frame->removeAddr($address);
        }
        $connection->send($frame);

        return true;
    }

    /**
     * @param Connection $connection
     * @param string     $host
     */
    public static function delete(Connection $connection, $host)
    {
        $frame = new DeleteHost();
        $frame->setHost(Helper::hostName($host));
        $connection->send($frame);
    }

    /**
     * @param Connection $connection
     * @param string     $host
     *
     * @return array ['name' => string, 'addr' => string[]]
     */
    public static function info(Connection $connection, $host)
    {
        $frame = new InfoHost();
        $frame->setHost($host);
        $data = $connection->send($frame)->data();
        $inf = isset($data['infData']) && is_array($data['infData']) ? $data['infData'] : [];

        $addresses = [];
        foreach (Helper::toList(isset($inf['addr']) ? $inf['addr'] : null) as $address) {
            if (is_string($address) && $address !== '') {
                $addresses[] = $address;
            }
        }

        return [
            'name' => isset($inf['name']) && is_string($inf['name']) ? $inf['name'] : $host,
            'addr' => $addresses,
        ];
    }

    /**
     * Hosts subordinate to a domain (its child nameservers) with their addresses.
     *
     * @param Connection $connection
     * @param string     $domain
     *
     * @return array[] list of ['name' => string, 'addr' => string[]]
     */
    public static function subordinates(Connection $connection, $domain)
    {
        $info = Domain::info($connection, $domain, null, 'sub');

        $hosts = [];
        foreach (Helper::toList(isset($info['host']) ? $info['host'] : null) as $host) {
            if (is_string($host) && $host !== '') {
                $hosts[] = self::info($connection, $host);
            }
        }

        return $hosts;
    }

    /**
     * Split the WHMCS IP address field (one address, or several separated by
     * commas/spaces) and validate each address.
     *
     * @param string $value
     *
     * @throws \InvalidArgumentException
     *
     * @return string[]
     */
    public static function parseAddresses($value)
    {
        $addresses = array_values(array_filter(preg_split('/[\s,;]+/', trim((string) $value)), 'strlen'));
        if (!$addresses) {
            throw new \InvalidArgumentException('An IP address is required');
        }
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new \InvalidArgumentException(sprintf('"%s" is not a public IPv4 or IPv6 address', $address));
            }
        }

        return $addresses;
    }

    /**
     * @param string[] $list
     * @param string   $address
     *
     * @return bool
     */
    private static function containsAddress(array $list, $address)
    {
        foreach ($list as $item) {
            if (self::sameAddress($item, $address)) {
                return true;
            }
        }

        return false;
    }

    /**
     * IPv6 addresses have many textual forms, so compare binary values.
     *
     * @param string $a
     * @param string $b
     *
     * @return bool
     */
    private static function sameAddress($a, $b)
    {
        $binaryA = @inet_pton($a);
        $binaryB = @inet_pton($b);

        return $binaryA !== false && $binaryA === $binaryB;
    }
}
