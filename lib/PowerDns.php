<?php

/**
 * PANDI EPP registrar module for WHMCS
 *
 * Copyright (C) 2023-2026 PANDI (Pengelola Nama Domain Internet Indonesia) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace WHMCS\Module\Registrar\Pandiwhmcs;

/**
 * Minimal PowerDNS HTTP API client used by the optional parking / expiry crons.
 */
class PowerDns
{
    const TTL = 300;

    private $url;
    private $apiKey;
    private $server;
    private $nameservers;

    /**
     * @param string   $url         API base URL, e.g. https://pdns.example:8081/api/v1
     * @param string   $apiKey
     * @param string   $server      PowerDNS server id, normally "localhost"
     * @param string[] $nameservers NS records of the parking zones
     */
    public function __construct($url, $apiKey, $server, array $nameservers)
    {
        $this->url = rtrim($url, '/');
        $this->apiKey = $apiKey;
        $this->server = $server;
        $this->nameservers = array_map(function ($ns) {
            return rtrim($ns, '.') . '.';
        }, array_values(array_filter($nameservers, 'strlen')));
    }

    /**
     * Client from the PANDIWHMCS_* constants of config.php.
     *
     * @return self
     */
    public static function fromConfig()
    {
        foreach (['PANDIWHMCS_PDNS_URL', 'PANDIWHMCS_PDNS_API_KEY', 'PANDIWHMCS_PARKING_NS1'] as $name) {
            if (!defined($name) || (string) constant($name) === '') {
                throw new \RuntimeException($name . ' is not configured (see config.local.php.example)');
            }
        }

        return new self(
            PANDIWHMCS_PDNS_URL,
            PANDIWHMCS_PDNS_API_KEY,
            PANDIWHMCS_PDNS_SERVER,
            [PANDIWHMCS_PARKING_NS1, PANDIWHMCS_PARKING_NS2]
        );
    }

    /**
     * Point the apex and www of $domain at $ip, creating the zone if needed.
     *
     * @param string $domain
     * @param string $ip
     */
    public function pointTo($domain, $ip)
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new \InvalidArgumentException(sprintf('"%s" is not an IP address', $ip));
        }

        $zone = rtrim($domain, '.') . '.';
        $type = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 'AAAA' : 'A';
        $records = [
            $this->rrset($zone, $type, [$ip]),
            $this->rrset('www.' . $zone, $type, [$ip]),
        ];

        if ($this->zoneExists($zone)) {
            $records[] = $this->rrset($zone, 'NS', $this->nameservers);
            $this->request('PATCH', '/zones/' . rawurlencode($zone), ['rrsets' => $records], [204]);
        } else {
            $this->request('POST', '/zones', [
                'name' => $zone,
                'kind' => 'Native',
                'nameservers' => $this->nameservers,
                'rrsets' => $records,
            ], [201]);
        }
    }

    /**
     * @param string $zone fully qualified, with trailing dot
     *
     * @return bool
     */
    public function zoneExists($zone)
    {
        return $this->request('GET', '/zones/' . rawurlencode($zone), null, [200, 404]) === 200;
    }

    private function rrset($name, $type, array $contents)
    {
        return [
            'name' => $name,
            'type' => $type,
            'ttl' => self::TTL,
            'changetype' => 'REPLACE',
            'records' => array_map(function ($content) {
                return ['content' => $content, 'disabled' => false];
            }, $contents),
        ];
    }

    /**
     * @param string     $method
     * @param string     $path relative to /servers/{server}
     * @param array|null $body
     * @param int[]      $expected accepted HTTP status codes
     *
     * @throws \RuntimeException on transport errors or unexpected status codes
     *
     * @return int HTTP status code
     */
    private function request($method, $path, $body, array $expected)
    {
        $ch = curl_init($this->url . '/servers/' . rawurlencode($this->server) . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'X-API-Key: ' . $this->apiKey],
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException(sprintf('PowerDNS %s %s failed: %s', $method, $path, $error));
        }
        if (!in_array($status, $expected, true)) {
            throw new \RuntimeException(sprintf('PowerDNS %s %s returned HTTP %d: %s', $method, $path, $status, substr((string) $response, 0, 300)));
        }

        return $status;
    }
}
