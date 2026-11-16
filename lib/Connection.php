<?php

/**
 * PANDI EPP registrar module for WHMCS
 *
 * Copyright (C) 2023-2026 PANDI (Pengelola Nama Domain Internet Indonesia) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace WHMCS\Module\Registrar\Pandiwhmcs;

use WHMCS\Module\Registrar\Pandiwhmcs\EPP\Client;
use WHMCS\Module\Registrar\Pandiwhmcs\EPP\FrameInterface;
use WHMCS\Module\Registrar\Pandiwhmcs\EPP\Frame\Response;

/**
 * One EPP session (RFC 5730 / RFC 5734) for the duration of one WHMCS module call.
 */
class Connection
{
    const OBJ_DOMAIN = 'urn:ietf:params:xml:ns:domain-1.0';
    const OBJ_CONTACT = 'urn:ietf:params:xml:ns:contact-1.0';
    const OBJ_HOST = 'urn:ietf:params:xml:ns:host-1.0';

    const EXT_SECDNS = 'urn:ietf:params:xml:ns:secDNS-1.1';
    const EXT_RGP = 'urn:ietf:params:xml:ns:rgp-1.0';
    const EXT_SECURE_AUTHINFO = 'urn:ietf:params:xml:ns:epp:secure-authinfo-transfer-1.0';

    /**
     * Test seam: callable(array $config) returning an object with the EPP\Client
     * methods used here. Null means a real TLS client.
     *
     * @var callable|null
     */
    public static $clientFactory;

    /**
     * Redacted [request, response] XML pairs exchanged during the current module call
     *
     * @var array[]
     */
    public static $transcript = [];

    /** @var Client */
    private $client;

    /** @var string */
    private $registrarId;

    private function __construct($client, $registrarId)
    {
        $this->client = $client;
        $this->registrarId = $registrarId;
    }

    /**
     * Open a session, run $callback(Connection) and always log out afterwards.
     *
     * @param array    $params   WHMCS module parameters
     * @param callable $callback
     *
     * @return mixed whatever $callback returns
     */
    public static function run(array $params, callable $callback)
    {
        $connection = self::open($params);
        try {
            return $callback($connection);
        } finally {
            $connection->close();
        }
    }

    /**
     * Connect, read the greeting and log in.
     *
     * @param array $params WHMCS module parameters
     *
     * @throws EppException
     *
     * @return self
     */
    public static function open(array $params)
    {
        foreach (['host' => 'EPP Server', 'registrar_id' => 'Client ID', 'pw' => 'Password'] as $key => $label) {
            if (!isset($params[$key]) || trim((string) $params[$key]) === '') {
                throw new EppException(sprintf('Module setting "%s" is empty', $label));
            }
        }

        $extensions = [self::EXT_SECDNS, self::EXT_RGP];
        // only claim the RFC 9154 practice when this module actually follows it
        if (Module::authInfoMode($params) === Module::AUTHINFO_GENERATE) {
            $extensions[] = self::EXT_SECURE_AUTHINFO;
        }

        $config = [
            'host' => trim($params['host']),
            'port' => !empty($params['port']) ? (int) $params['port'] : 700,
            'username' => $params['registrar_id'],
            'password' => $params['pw'],
            'services' => [self::OBJ_DOMAIN, self::OBJ_CONTACT, self::OBJ_HOST],
            'serviceExtensions' => $extensions,
            'ssl' => true,
            'local_cert' => self::certificatePath($params),
            'pk_cert' => !empty($params['key_path']) ? trim($params['key_path']) : null,
            'passphrase' => !empty($params['cert_passphrase']) ? $params['cert_passphrase'] : null,
            'ca_cert' => !empty($params['ca_file']) ? trim($params['ca_file']) : null,
            'verify_peer' => empty($params['tls_insecure']),
            'debug' => false,
        ];

        $factory = self::$clientFactory;
        if ($factory === null) {
            $factory = function (array $config) {
                return new Client($config);
            };
        }

        try {
            $client = $factory($config);
            $client->setFrameObserver(function ($request, $response) {
                self::$transcript[] = [Helper::redactXml($request), Helper::redactXml($response)];
            });
            $client->connect();
        } catch (\Exception $e) {
            $code = $e->getCode() >= 2000 ? $e->getCode() : 0;
            throw new EppException('EPP login failed: ' . $e->getMessage(), $code, null, $e);
        }

        return new self($client, (string) $params['registrar_id']);
    }

    /**
     * Client certificate to present (RFC 5734 section 9). An absolute path in the
     * "Certificate Path" setting wins over a file picked from lib/certificate/.
     *
     * @param array $params
     *
     * @return string|null
     */
    private static function certificatePath(array $params)
    {
        if (!empty($params['cert_path'])) {
            return trim($params['cert_path']);
        }
        if (!empty($params['local_cert'])) {
            return __DIR__ . '/certificate/' . basename($params['local_cert']);
        }

        return null;
    }

    /**
     * Send a frame and return the parsed response, whatever its result code.
     *
     * @param FrameInterface $frame
     *
     * @throws EppException on transport errors or unparseable responses
     *
     * @return Response
     */
    public function request(FrameInterface $frame)
    {
        try {
            $response = $this->client->request($frame);
        } catch (\Exception $e) {
            throw new EppException('EPP transport error: ' . $e->getMessage(), 0, null, $e);
        }

        if (!$response instanceof Response || count($response->results()) === 0) {
            throw new EppException('Invalid or empty response from the EPP server');
        }

        return $response;
    }

    /**
     * Send a frame and require a successful (1xxx) result, RFC 5730 section 3.
     *
     * @param FrameInterface $frame
     *
     * @throws EppException on any 2xxx result
     *
     * @return Response
     */
    public function send(FrameInterface $frame)
    {
        $response = $this->request($frame);
        $result = $response->results()[0];
        $code = (int) $result->code();

        if ($code < 1000 || $code >= 2000) {
            throw new EppException((string) $result->message(), $code, self::reason($result->extValues()));
        }

        return $response;
    }

    /**
     * Result code of the first <result> of a response.
     *
     * @param Response $response
     *
     * @return int
     */
    public static function code(Response $response)
    {
        $results = $response->results();

        return isset($results[0]) ? (int) $results[0]->code() : 0;
    }

    /**
     * Whether an extension was negotiated at login (announced by the server and requested by us).
     *
     * @param string $uri
     *
     * @return bool
     */
    public function supports($uri)
    {
        return in_array($uri, $this->client->getServiceExtensions(), true);
    }

    /**
     * @return string the registrar's EPP client identifier (clID)
     */
    public function registrarId()
    {
        return $this->registrarId;
    }

    /**
     * Log out (RFC 5730 section 2.9.1.2) and close the socket. Never throws.
     */
    public function close()
    {
        try {
            $this->client->close();
        } catch (\Exception $e) {
            // the server may already have closed the session (2500-series result)
        }
    }

    /**
     * <reason> text(s) of <extValue> elements.
     *
     * @param array $extValues
     *
     * @return string|null
     */
    private static function reason(array $extValues)
    {
        $reasons = [];
        foreach (Helper::toList(isset($extValues['reason']) ? $extValues['reason'] : null) as $reason) {
            if (is_string($reason) && trim($reason) !== '') {
                $reasons[] = trim($reason);
            }
        }

        return $reasons ? implode('; ', $reasons) : null;
    }
}
