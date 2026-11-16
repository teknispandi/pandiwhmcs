<?php

/**
 * In-process EPP registry simulator for the test suite.
 *
 * It is deliberately strict: every command is validated against the IANA XSDs
 * (a schema violation is answered with 2001, like a validating server), host
 * objects must exist before delegation, adding a status/nameserver that is
 * already present or removing one that is absent is a 2306, and extensions
 * must have been negotiated at login.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace PandiwhmcsTest;

use WHMCS\Module\Registrar\Pandiwhmcs\EPP\AbstractClient;
use WHMCS\Module\Registrar\Pandiwhmcs\EPP\Frame\Command\Logout;
use WHMCS\Module\Registrar\Pandiwhmcs\EPP\FrameInterface;

const NS_EPP = 'urn:ietf:params:xml:ns:epp-1.0';
const NS_DOMAIN = 'urn:ietf:params:xml:ns:domain-1.0';
const NS_CONTACT = 'urn:ietf:params:xml:ns:contact-1.0';
const NS_HOST = 'urn:ietf:params:xml:ns:host-1.0';
const NS_SECDNS = 'urn:ietf:params:xml:ns:secDNS-1.1';
const NS_RGP = 'urn:ietf:params:xml:ns:rgp-1.0';
const NS_SECURE_AUTHINFO = 'urn:ietf:params:xml:ns:epp:secure-authinfo-transfer-1.0';

function h($value)
{
    return htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

class Session
{
    public $registrar;
    public $loggedIn = false;
    public $extensions = [];
}

class FakeRegistry
{
    public $credentials = ['REG1' => 'secretpw', 'REG2' => 'otherpw'];
    public $tlds = ['id', 'co.id', 'web.id'];
    public $extensions;
    public $requireRestoreReport = true;
    public $authInfoMinLength = 8;

    public $domains = [];
    public $contacts = [];
    public $hosts = [];
    public $transfers = [];
    public $queues = [];

    /** @var array[] [registrar, command name, request XML] */
    public $frames = [];
    /** @var array[] schema violations: ['xml' => ..., 'errors' => [...]] */
    public $schemaErrors = [];
    /** @var array[] login frames */
    public $logins = [];

    private $schema;
    private $clTRID = '';
    private $serial = 0;

    public function __construct($schema, array $extensions)
    {
        $this->schema = $schema;
        $this->extensions = $extensions;
    }

    public function greeting()
    {
        $ext = '';
        foreach ($this->extensions as $uri) {
            $ext .= '<extURI>' . h($uri) . '</extURI>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="no"?>'
            . '<epp xmlns="' . NS_EPP . '"><greeting><svID>Fake Registry</svID><svDate>' . gmdate('Y-m-d\TH:i:s\Z') . '</svDate>'
            . '<svcMenu><version>1.0</version><lang>en</lang>'
            . '<objURI>' . NS_DOMAIN . '</objURI><objURI>' . NS_CONTACT . '</objURI><objURI>' . NS_HOST . '</objURI>'
            . ($ext !== '' ? '<svcExtension>' . $ext . '</svcExtension>' : '')
            . '</svcMenu><dcp><access><all/></access><statement><purpose><admin/><prov/></purpose>'
            . '<recipient><ours/></recipient><retention><stated/></retention></statement></dcp></greeting></epp>';
    }

    /** Commands (not login/logout/poll) received so far, e.g. "domain:update" */
    public function commands()
    {
        $names = [];
        foreach ($this->frames as $frame) {
            $names[] = $frame[1];
        }

        return $names;
    }

    public function lastFrame($command)
    {
        for ($i = count($this->frames) - 1; $i >= 0; --$i) {
            if ($this->frames[$i][1] === $command) {
                return $this->frames[$i][2];
            }
        }

        return null;
    }

    public function handle(Session $session, $xml)
    {
        $doc = new \DOMDocument();
        if (!@$doc->loadXML($xml)) {
            return $this->result(2001, 'XML is not well-formed');
        }

        $xpath = new \DOMXPath($doc);
        foreach (['e' => NS_EPP, 'd' => NS_DOMAIN, 'c' => NS_CONTACT, 'h' => NS_HOST, 's' => NS_SECDNS, 'r' => NS_RGP] as $prefix => $uri) {
            $xpath->registerNamespace($prefix, $uri);
        }
        $this->clTRID = $xpath->evaluate('string(/e:epp/e:command/e:clTRID)');

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $valid = $doc->schemaValidate($this->schema);
        $errors = array_map(function ($error) {
            return trim($error->message);
        }, libxml_get_errors());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$valid) {
            $this->schemaErrors[] = ['xml' => $xml, 'errors' => $errors];

            return $this->result(2001, 'Command syntax error: ' . (isset($errors[0]) ? $errors[0] : 'schema'));
        }

        $command = $xpath->query('/e:epp/e:command/*[not(self::e:extension) and not(self::e:clTRID)]')->item(0);
        if (!$command) {
            return $this->result(2001, 'No command');
        }

        if ($command->localName === 'login') {
            $this->logins[] = $xml;

            return $this->login($session, $xpath);
        }
        if (!$session->loggedIn) {
            return $this->result(2002, 'Command use error: not logged in');
        }
        if ($command->localName === 'logout') {
            $session->loggedIn = false;

            return $this->result(1500, 'Command completed successfully; ending session');
        }

        foreach ($xpath->query('/e:epp/e:command/e:extension/*') as $extension) {
            if (!in_array($extension->namespaceURI, $session->extensions, true)) {
                return $this->result(2002, 'Extension was not negotiated at login: ' . $extension->namespaceURI);
            }
        }

        if ($command->localName === 'poll') {
            $this->frames[] = [$session->registrar, 'poll', $xml];

            return $this->poll($session, $command);
        }

        $object = null;
        foreach ($command->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                $object = $child;
                break;
            }
        }
        $prefix = [NS_DOMAIN => 'domain', NS_CONTACT => 'contact', NS_HOST => 'host'][$object->namespaceURI];
        $name = $prefix . ':' . $command->localName;
        $this->frames[] = [$session->registrar, $name, $xml];

        $method = $prefix . ucfirst($command->localName);

        return $this->$method($session, $xpath, $object, $command);
    }

    // ------------------------------------------------------------------ session

    private function login(Session $session, \DOMXPath $xpath)
    {
        $clID = $xpath->evaluate('string(//e:login/e:clID)');
        $pw = $xpath->evaluate('string(//e:login/e:pw)');
        if (!isset($this->credentials[$clID]) || $this->credentials[$clID] !== $pw) {
            return $this->result(2200, 'Authentication error');
        }
        foreach ($xpath->query('//e:login/e:svcs/e:objURI') as $node) {
            if (!in_array($node->textContent, [NS_DOMAIN, NS_CONTACT, NS_HOST], true)) {
                return $this->result(2307, 'Unimplemented object service: ' . $node->textContent);
            }
        }
        $extensions = [];
        foreach ($xpath->query('//e:login/e:svcs/e:svcExtension/e:extURI') as $node) {
            if (!in_array($node->textContent, $this->extensions, true)) {
                return $this->result(2307, 'Unimplemented extension: ' . $node->textContent);
            }
            $extensions[] = $node->textContent;
        }

        $session->registrar = $clID;
        $session->loggedIn = true;
        $session->extensions = $extensions;

        return $this->result(1000, 'Command completed successfully');
    }

    private function secure(Session $session)
    {
        return in_array(NS_SECURE_AUTHINFO, $session->extensions, true);
    }

    // ------------------------------------------------------------------ domain

    private function domainCheck(Session $session, \DOMXPath $xpath, \DOMElement $object)
    {
        $cds = '';
        foreach ($xpath->query('d:name', $object) as $node) {
            $name = strtolower($node->textContent);
            $avail = !isset($this->domains[$name]);
            $cds .= '<domain:cd><domain:name avail="' . ($avail ? 1 : 0) . '">' . h($name) . '</domain:name>'
                . ($avail ? '' : '<domain:reason>In use</domain:reason>') . '</domain:cd>';
        }

        return $this->result(1000, 'Command completed successfully', '<domain:chkData xmlns:domain="' . NS_DOMAIN . '">' . $cds . '</domain:chkData>');
    }

    private function domainInfo(Session $session, \DOMXPath $xpath, \DOMElement $object)
    {
        $name = strtolower($xpath->evaluate('string(d:name)', $object));
        $hosts = $xpath->evaluate('string(d:name/@hosts)', $object) ?: 'all';
        if (!isset($this->domains[$name])) {
            return $this->result(2303, 'Object does not exist');
        }
        $domain = $this->domains[$name];
        $pw = $xpath->evaluate('string(d:authInfo/d:pw)', $object);
        $sponsor = $domain['clID'] === $session->registrar;
        if (!$sponsor) {
            if ($pw === '') {
                return $this->result(2201, 'Authorization error');
            }
            if ($pw !== $domain['authInfo']) {
                return $this->result(2202, 'Invalid authorization information');
            }
        }

        $xml = '<domain:infData xmlns:domain="' . NS_DOMAIN . '"><domain:name>' . h($name) . '</domain:name><domain:roid>' . h($domain['roid']) . '</domain:roid>';
        foreach ($domain['status'] ?: ['ok'] as $status) {
            $xml .= '<domain:status s="' . h($status) . '"/>';
        }
        $xml .= '<domain:registrant>' . h($domain['registrant']) . '</domain:registrant>';
        foreach ($domain['contacts'] as $type => $ids) {
            foreach ($ids as $id) {
                $xml .= '<domain:contact type="' . $type . '">' . h($id) . '</domain:contact>';
            }
        }
        if (in_array($hosts, ['all', 'del'], true) && $domain['ns']) {
            $xml .= '<domain:ns>';
            foreach ($domain['ns'] as $ns) {
                $xml .= '<domain:hostObj>' . h($ns) . '</domain:hostObj>';
            }
            $xml .= '</domain:ns>';
        }
        if (in_array($hosts, ['all', 'sub'], true)) {
            foreach ($this->hosts as $hostName => $host) {
                if ($host['parent'] === $name) {
                    $xml .= '<domain:host>' . h($hostName) . '</domain:host>';
                }
            }
        }
        $xml .= '<domain:clID>' . h($domain['clID']) . '</domain:clID><domain:crID>' . h($domain['crID']) . '</domain:crID>'
            . '<domain:crDate>' . $domain['crDate'] . '</domain:crDate>';
        if (!empty($domain['upDate'])) {
            $xml .= '<domain:upDate>' . $domain['upDate'] . '</domain:upDate>';
        }
        $xml .= '<domain:exDate>' . $domain['exDate'] . '</domain:exDate>';
        if ($sponsor) {
            // RFC 9154 section 5.3: an RFC 9154 server never returns the value
            $xml .= '<domain:authInfo><domain:pw>' . ($this->secure($session) ? '' : h($domain['authInfo'])) . '</domain:pw></domain:authInfo>';
        }
        $xml .= '</domain:infData>';

        $ext = '';
        if ($domain['ds'] && in_array(NS_SECDNS, $session->extensions, true)) {
            $ext .= '<secDNS:infData xmlns:secDNS="' . NS_SECDNS . '">';
            foreach ($domain['ds'] as $ds) {
                $ext .= '<secDNS:dsData><secDNS:keyTag>' . $ds['keyTag'] . '</secDNS:keyTag><secDNS:alg>' . $ds['alg'] . '</secDNS:alg>'
                    . '<secDNS:digestType>' . $ds['digestType'] . '</secDNS:digestType><secDNS:digest>' . $ds['digest'] . '</secDNS:digest></secDNS:dsData>';
            }
            $ext .= '</secDNS:infData>';
        }
        if ($domain['rgp'] && in_array(NS_RGP, $session->extensions, true)) {
            $ext .= '<rgp:infData xmlns:rgp="' . NS_RGP . '">';
            foreach ($domain['rgp'] as $status) {
                $ext .= '<rgp:rgpStatus s="' . h($status) . '"/>';
            }
            $ext .= '</rgp:infData>';
        }

        return $this->result(1000, 'Command completed successfully', $xml, $ext);
    }

    private function domainCreate(Session $session, \DOMXPath $xpath, \DOMElement $object)
    {
        $name = strtolower($xpath->evaluate('string(d:name)', $object));
        if (isset($this->domains[$name])) {
            return $this->result(2302, 'Object exists');
        }
        $ns = [];
        foreach ($xpath->query('d:ns/d:hostObj', $object) as $node) {
            $host = strtolower($node->textContent);
            if (!isset($this->hosts[$host])) {
                return $this->result(2303, 'Host ' . $host . ' does not exist');
            }
            $ns[] = $host;
        }
        $registrant = $xpath->evaluate('string(d:registrant)', $object);
        if (!$this->contactUsable($registrant, $session)) {
            return $this->result(2303, 'Registrant does not exist');
        }
        $contacts = ['admin' => [], 'tech' => [], 'billing' => []];
        foreach ($xpath->query('d:contact', $object) as $node) {
            if (!$this->contactUsable($node->textContent, $session)) {
                return $this->result(2303, 'Contact does not exist');
            }
            $contacts[$node->getAttribute('type')][] = $node->textContent;
        }
        $pw = $xpath->evaluate('string(d:authInfo/d:pw)', $object);
        if (!$this->secure($session) && strlen($pw) < $this->authInfoMinLength) {
            return $this->result(2306, 'Parameter value policy error: authInfo too short');
        }
        $years = (int) ($xpath->evaluate('string(d:period)', $object) ?: 1);

        $this->domains[$name] = [
            'roid' => 'D' . (++$this->serial) . '-FAKE',
            'status' => [],
            'registrant' => $registrant,
            'contacts' => $contacts,
            'ns' => $ns,
            'clID' => $session->registrar,
            'crID' => $session->registrar,
            'crDate' => gmdate('Y-m-d\TH:i:s.0\Z'),
            'exDate' => gmdate('Y-m-d\TH:i:s.0\Z', strtotime('+' . $years . ' years')),
            'authInfo' => $pw,
            'ds' => [],
            'rgp' => [],
        ];

        return $this->result(1000, 'Command completed successfully', '<domain:creData xmlns:domain="' . NS_DOMAIN . '"><domain:name>' . h($name)
            . '</domain:name><domain:crDate>' . $this->domains[$name]['crDate'] . '</domain:crDate><domain:exDate>'
            . $this->domains[$name]['exDate'] . '</domain:exDate></domain:creData>');
    }

    private function domainUpdate(Session $session, \DOMXPath $xpath, \DOMElement $object)
    {
        $name = strtolower($xpath->evaluate('string(d:name)', $object));
        if (!isset($this->domains[$name])) {
            return $this->result(2303, 'Object does not exist');
        }
        $domain = $this->domains[$name];
        if ($domain['clID'] !== $session->registrar) {
            return $this->result(2201, 'Authorization error');
        }

        foreach ($xpath->query('d:add/d:ns/d:hostObj', $object) as $node) {
            $host = strtolower($node->textContent);
            if (!isset($this->hosts[$host])) {
                return $this->result(2303, 'Host ' . $host . ' does not exist');
            }
            if (in_array($host, $domain['ns'], true)) {
                return $this->result(2306, 'Nameserver ' . $host . ' is already delegated');
            }
            $domain['ns'][] = $host;
        }
        foreach ($xpath->query('d:add/d:contact', $object) as $node) {
            if (!$this->contactUsable($node->textContent, $session)) {
                return $this->result(2303, 'Contact does not exist');
            }
            $domain['contacts'][$node->getAttribute('type')][] = $node->textContent;
        }
        foreach ($xpath->query('d:add/d:status', $object) as $node) {
            $status = $node->getAttribute('s');
            if (in_array($status, $domain['status'], true)) {
                return $this->result(2306, 'Status ' . $status . ' is already set');
            }
            $domain['status'][] = $status;
        }
        foreach ($xpath->query('d:rem/d:ns/d:hostObj', $object) as $node) {
            $host = strtolower($node->textContent);
            if (!in_array($host, $domain['ns'], true)) {
                return $this->result(2306, 'Nameserver ' . $host . ' is not delegated');
            }
            $domain['ns'] = array_values(array_diff($domain['ns'], [$host]));
        }
        foreach ($xpath->query('d:rem/d:contact', $object) as $node) {
            $type = $node->getAttribute('type');
            if (!in_array($node->textContent, $domain['contacts'][$type], true)) {
                return $this->result(2306, 'Contact is not linked');
            }
            $domain['contacts'][$type] = array_values(array_diff($domain['contacts'][$type], [$node->textContent]));
        }
        foreach ($xpath->query('d:rem/d:status', $object) as $node) {
            $status = $node->getAttribute('s');
            if (!in_array($status, $domain['status'], true)) {
                return $this->result(2306, 'Status ' . $status . ' is not set');
            }
            $domain['status'] = array_values(array_diff($domain['status'], [$status]));
        }
        if ($xpath->query('d:chg/d:registrant', $object)->length) {
            $registrant = $xpath->evaluate('string(d:chg/d:registrant)', $object);
            if (!$this->contactUsable($registrant, $session)) {
                return $this->result(2303, 'Registrant does not exist');
            }
            $domain['registrant'] = $registrant;
        }
        if ($xpath->query('d:chg/d:authInfo', $object)->length) {
            $pw = $xpath->evaluate('string(d:chg/d:authInfo/d:pw)', $object);
            if ($pw === '' && !$this->secure($session)) {
                return $this->result(2306, 'Parameter value policy error: authInfo required');
            }
            if ($pw !== '' && strlen($pw) < $this->authInfoMinLength) {
                return $this->result(2306, 'Parameter value policy error: authInfo too short');
            }
            $domain['authInfo'] = $pw;
        }

        foreach ($xpath->query('/e:epp/e:command/e:extension/s:update/s:rem/s:dsData') as $node) {
            $ds = $this->dsFromNode($xpath, $node);
            $key = array_search($ds, $domain['ds']);
            if ($key === false) {
                return $this->result(2306, 'DS record not found');
            }
            unset($domain['ds'][$key]);
            $domain['ds'] = array_values($domain['ds']);
        }
        foreach ($xpath->query('/e:epp/e:command/e:extension/s:update/s:add/s:dsData') as $node) {
            $domain['ds'][] = $this->dsFromNode($xpath, $node);
        }

        $restore = $xpath->query('/e:epp/e:command/e:extension/r:update/r:restore')->item(0);
        if ($restore) {
            if ($xpath->query('d:chg', $object)->length !== 1) {
                return $this->result(2003, 'RGP restore requires an empty <domain:chg>');
            }
            if ($restore->getAttribute('op') === 'request') {
                if (!in_array('redemptionPeriod', $domain['rgp'], true)) {
                    return $this->result(2304, 'Domain is not in redemptionPeriod');
                }
                if ($this->requireRestoreReport) {
                    $domain['rgp'] = ['pendingRestore'];
                } else {
                    $domain['rgp'] = [];
                    $domain['status'] = array_values(array_diff($domain['status'], ['pendingDelete']));
                }
            } else {
                if (!in_array('pendingRestore', $domain['rgp'], true)) {
                    return $this->result(2304, 'No restore pending');
                }
                if ($xpath->query('r:report/r:statement', $restore)->length !== 2) {
                    return $this->result(2003, 'Two statements are required');
                }
                $domain['rgp'] = [];
                $domain['status'] = array_values(array_diff($domain['status'], ['pendingDelete']));
            }
        }

        $domain['upDate'] = gmdate('Y-m-d\TH:i:s.0\Z');
        $this->domains[$name] = $domain;

        return $this->result(1000, 'Command completed successfully');
    }

    private function domainRenew(Session $session, \DOMXPath $xpath, \DOMElement $object)
    {
        $name = strtolower($xpath->evaluate('string(d:name)', $object));
        if (!isset($this->domains[$name])) {
            return $this->result(2303, 'Object does not exist');
        }
        $domain = &$this->domains[$name];
        if ($domain['clID'] !== $session->registrar) {
            return $this->result(2201, 'Authorization error');
        }
        if ($xpath->evaluate('string(d:curExpDate)', $object) !== substr($domain['exDate'], 0, 10)) {
            return $this->result(2306, 'Parameter value policy error: curExpDate does not match');
        }
        $years = (int) ($xpath->evaluate('string(d:period)', $object) ?: 1);
        $domain['exDate'] = gmdate('Y-m-d\TH:i:s.0\Z', strtotime($domain['exDate'] . ' +' . $years . ' years'));

        return $this->result(1000, 'Command completed successfully', '<domain:renData xmlns:domain="' . NS_DOMAIN . '"><domain:name>' . h($name)
            . '</domain:name><domain:exDate>' . $domain['exDate'] . '</domain:exDate></domain:renData>');
    }

    private function domainDelete(Session $session, \DOMXPath $xpath, \DOMElement $object)
    {
        $name = strtolower($xpath->evaluate('string(d:name)', $object));
        if (!isset($this->domains[$name])) {
            return $this->result(2303, 'Object does not exist');
        }
        $domain = &$this->domains[$name];
        if ($domain['clID'] !== $session->registrar) {
            return $this->result(2201, 'Authorization error');
        }
        if (in_array('clientDeleteProhibited', $domain['status'], true)) {
            return $this->result(2304, 'Object status prohibits operation');
        }
        $domain['status'] = ['pendingDelete'];
        $domain['rgp'] = ['redemptionPeriod'];
        $domain['upDate'] = gmdate('Y-m-d\TH:i:s.0\Z');

        return $this->result(1001, 'Command completed successfully; action pending');
    }

    private function domainTransfer(Session $session, \DOMXPath $xpath, \DOMElement $object, \DOMElement $command)
    {
        $name = strtolower($xpath->evaluate('string(d:name)', $object));
        $op = $command->getAttribute('op');
        if (!isset($this->domains[$name])) {
            return $this->result(2303, 'Object does not exist');
        }
        $domain = &$this->domains[$name];

        if ($op === 'query') {
            if (!isset($this->transfers[$name]) || !in_array($session->registrar, [$this->transfers[$name]['reID'], $this->transfers[$name]['acID']], true)) {
                return $this->result(2301, 'Object not pending transfer');
            }

            return $this->result(1000, 'Command completed successfully', $this->trnData($name));
        }

        if ($op === 'request') {
            if ($domain['clID'] === $session->registrar) {
                return $this->result(2106, 'Object is not eligible for transfer');
            }
            if (in_array('clientTransferProhibited', $domain['status'], true)) {
                return $this->result(2304, 'Object status prohibits operation');
            }
            $pw = $xpath->evaluate('string(d:authInfo/d:pw)', $object);
            if ($pw === '' || $pw !== $domain['authInfo']) {
                return $this->result(2202, 'Invalid authorization information');
            }
            $this->transfers[$name] = [
                'trStatus' => 'pending',
                'reID' => $session->registrar,
                'reDate' => gmdate('Y-m-d\TH:i:s.0\Z'),
                'acID' => $domain['clID'],
                'acDate' => gmdate('Y-m-d\TH:i:s.0\Z', strtotime('+5 days')),
            ];
            $domain['status'][] = 'pendingTransfer';
            $this->enqueue($domain['clID'], 'Transfer requested.', $this->trnData($name));

            return $this->result(1001, 'Command completed successfully; action pending', $this->trnData($name));
        }

        return $this->result(2102, 'Unimplemented command');
    }

    /** Simulator control: the losing registrar approves the pending transfer */
    public function approveTransfer($name)
    {
        $transfer = &$this->transfers[$name];
        $domain = &$this->domains[$name];
        $transfer['trStatus'] = 'clientApproved';
        $domain['clID'] = $transfer['reID'];
        $domain['status'] = array_values(array_diff($domain['status'], ['pendingTransfer']));
        $domain['exDate'] = gmdate('Y-m-d\TH:i:s.0\Z', strtotime($domain['exDate'] . ' +1 year'));
        $domain['authInfo'] = '';
        $this->enqueue($transfer['reID'], 'Transfer approved.', $this->trnData($name));
        $this->enqueue($transfer['acID'], 'Transfer approved.', $this->trnData($name));
    }

    private function trnData($name)
    {
        $t = $this->transfers[$name];

        return '<domain:trnData xmlns:domain="' . NS_DOMAIN . '"><domain:name>' . h($name) . '</domain:name><domain:trStatus>' . $t['trStatus']
            . '</domain:trStatus><domain:reID>' . h($t['reID']) . '</domain:reID><domain:reDate>' . $t['reDate'] . '</domain:reDate><domain:acID>'
            . h($t['acID']) . '</domain:acID><domain:acDate>' . $t['acDate'] . '</domain:acDate><domain:exDate>' . $this->domains[$name]['exDate']
            . '</domain:exDate></domain:trnData>';
    }

    // ------------------------------------------------------------------ poll

    public function enqueue($registrar, $message, $resData = '')
    {
        $this->queues[$registrar][] = ['id' => (string) (++$this->serial), 'msg' => $message, 'resData' => $resData, 'qDate' => gmdate('Y-m-d\TH:i:s.0\Z')];
    }

    private function poll(Session $session, \DOMElement $command)
    {
        $queue = isset($this->queues[$session->registrar]) ? $this->queues[$session->registrar] : [];
        if ($command->getAttribute('op') === 'ack') {
            foreach ($queue as $index => $message) {
                if ($message['id'] === $command->getAttribute('msgID')) {
                    array_splice($this->queues[$session->registrar], $index, 1);

                    return $this->result(1000, 'Command completed successfully', '', '', '<msgQ count="' . count($this->queues[$session->registrar]) . '" id="' . $message['id'] . '"/>');
                }
            }

            return $this->result(2303, 'Message does not exist');
        }

        if (!$queue) {
            return $this->result(1300, 'Command completed successfully; no messages');
        }
        $message = $queue[0];

        return $this->result(1301, 'Command completed successfully; ack to dequeue', $message['resData'], '', '<msgQ count="' . count($queue) . '" id="' . $message['id']
            . '"><qDate>' . $message['qDate'] . '</qDate><msg>' . h($message['msg']) . '</msg></msgQ>');
    }

    // ------------------------------------------------------------------ contact

    private function contactUsable($id, Session $session)
    {
        return isset($this->contacts[$id]) && $this->contacts[$id]['clID'] === $session->registrar;
    }

    private function contactCreate(Session $session, \DOMXPath $xpath, \DOMElement $object)
    {
        $id = $xpath->evaluate('string(c:id)', $object);
        if (isset($this->contacts[$id])) {
            return $this->result(2302, 'Object exists');
        }
        $this->contacts[$id] = $this->contactData($xpath, $object) + ['clID' => $session->registrar, 'authInfo' => $xpath->evaluate('string(c:authInfo/c:pw)', $object)];

        return $this->result(1000, 'Command completed successfully', '<contact:creData xmlns:contact="' . NS_CONTACT . '"><contact:id>' . h($id)
            . '</contact:id><contact:crDate>' . gmdate('Y-m-d\TH:i:s.0\Z') . '</contact:crDate></contact:creData>');
    }

    private function contactData(\DOMXPath $xpath, \DOMElement $base)
    {
        $postal = $xpath->query('c:postalInfo[@type="loc"]', $base)->item(0) ?: $xpath->query('c:postalInfo[@type="int"]', $base)->item(0);
        $int = $xpath->query('c:postalInfo[@type="int"]', $base)->item(0);
        $streets = [];
        foreach ($xpath->query('c:addr/c:street', $postal) as $node) {
            $streets[] = $node->textContent;
        }

        return [
            'name' => $xpath->evaluate('string(c:name)', $postal),
            'intName' => $int ? $xpath->evaluate('string(c:name)', $int) : '',
            'org' => $xpath->evaluate('string(c:org)', $postal),
            'street' => $streets,
            'city' => $xpath->evaluate('string(c:addr/c:city)', $postal),
            'sp' => $xpath->evaluate('string(c:addr/c:sp)', $postal),
            'pc' => $xpath->evaluate('string(c:addr/c:pc)', $postal),
            'cc' => $xpath->evaluate('string(c:addr/c:cc)', $postal),
            'voice' => $xpath->evaluate('string(c:voice)', $base),
            'fax' => $xpath->evaluate('string(c:fax)', $base),
            'email' => $xpath->evaluate('string(c:email)', $base),
        ];
    }

    private function contactInfo(Session $session, \DOMXPath $xpath, \DOMElement $object)
    {
        $id = $xpath->evaluate('string(c:id)', $object);
        if (!isset($this->contacts[$id])) {
            return $this->result(2303, 'Object does not exist');
        }
        $c = $this->contacts[$id];
        if ($c['clID'] !== $session->registrar) {
            return $this->result(2201, 'Authorization error');
        }

        $postal = function ($type, $name) use ($c) {
            $xml = '<contact:postalInfo type="' . $type . '"><contact:name>' . h($name) . '</contact:name>';
            if ($c['org'] !== '') {
                $xml .= '<contact:org>' . h($c['org']) . '</contact:org>';
            }
            $xml .= '<contact:addr>';
            foreach ($c['street'] as $street) {
                $xml .= '<contact:street>' . h($street) . '</contact:street>';
            }
            $xml .= '<contact:city>' . h($c['city']) . '</contact:city>';
            if ($c['sp'] !== '') {
                $xml .= '<contact:sp>' . h($c['sp']) . '</contact:sp>';
            }
            if ($c['pc'] !== '') {
                $xml .= '<contact:pc>' . h($c['pc']) . '</contact:pc>';
            }

            return $xml . '<contact:cc>' . h($c['cc']) . '</contact:cc></contact:addr></contact:postalInfo>';
        };

        $xml = '<contact:infData xmlns:contact="' . NS_CONTACT . '"><contact:id>' . h($id) . '</contact:id><contact:roid>C' . crc32($id) . '-FAKE</contact:roid>'
            . '<contact:status s="ok"/>' . $postal('int', $c['intName'] ?: $c['name']) . $postal('loc', $c['name']);
        if ($c['voice'] !== '') {
            $xml .= '<contact:voice>' . h($c['voice']) . '</contact:voice>';
        }
        if ($c['fax'] !== '') {
            $xml .= '<contact:fax>' . h($c['fax']) . '</contact:fax>';
        }
        $xml .= '<contact:email>' . h($c['email']) . '</contact:email><contact:clID>' . h($c['clID']) . '</contact:clID><contact:crID>'
            . h($c['clID']) . '</contact:crID><contact:crDate>2024-01-01T00:00:00.0Z</contact:crDate></contact:infData>';

        return $this->result(1000, 'Command completed successfully', $xml);
    }

    private function contactUpdate(Session $session, \DOMXPath $xpath, \DOMElement $object)
    {
        $id = $xpath->evaluate('string(c:id)', $object);
        if (!isset($this->contacts[$id])) {
            return $this->result(2303, 'Object does not exist');
        }
        if ($this->contacts[$id]['clID'] !== $session->registrar) {
            return $this->result(2201, 'Authorization error');
        }
        $chg = $xpath->query('c:chg', $object)->item(0);
        if ($chg) {
            $this->contacts[$id] = $this->contactData($xpath, $chg) + $this->contacts[$id];
        }

        return $this->result(1000, 'Command completed successfully');
    }

    private function contactDelete(Session $session, \DOMXPath $xpath, \DOMElement $object)
    {
        $id = $xpath->evaluate('string(c:id)', $object);
        if (!isset($this->contacts[$id])) {
            return $this->result(2303, 'Object does not exist');
        }
        unset($this->contacts[$id]);

        return $this->result(1000, 'Command completed successfully');
    }

    // ------------------------------------------------------------------ host

    private function superordinate($host)
    {
        foreach (array_keys($this->domains) as $domain) {
            if (substr($host, -strlen('.' . $domain)) === '.' . $domain) {
                return $domain;
            }
        }

        return null;
    }

    private function hostCheck(Session $session, \DOMXPath $xpath, \DOMElement $object)
    {
        $cds = '';
        foreach ($xpath->query('h:name', $object) as $node) {
            $name = strtolower($node->textContent);
            $cds .= '<host:cd><host:name avail="' . (isset($this->hosts[$name]) ? 0 : 1) . '">' . h($name) . '</host:name></host:cd>';
        }

        return $this->result(1000, 'Command completed successfully', '<host:chkData xmlns:host="' . NS_HOST . '">' . $cds . '</host:chkData>');
    }

    private function hostCreate(Session $session, \DOMXPath $xpath, \DOMElement $object)
    {
        $name = strtolower($xpath->evaluate('string(h:name)', $object));
        if (isset($this->hosts[$name])) {
            return $this->result(2302, 'Object exists');
        }
        $addresses = [];
        foreach ($xpath->query('h:addr', $object) as $node) {
            $addresses[] = $node->textContent;
        }
        $parent = $this->superordinate($name);
        if ($parent !== null) {
            if ($this->domains[$parent]['clID'] !== $session->registrar) {
                return $this->result(2201, 'Authorization error');
            }
            if (!$addresses) {
                return $this->result(2003, 'Required parameter missing: addr');
            }
        } elseif ($addresses) {
            return $this->result(2306, 'External hosts must not have addresses');
        }

        $this->hosts[$name] = ['addr' => $addresses, 'parent' => $parent, 'clID' => $session->registrar];

        return $this->result(1000, 'Command completed successfully', '<host:creData xmlns:host="' . NS_HOST . '"><host:name>' . h($name)
            . '</host:name><host:crDate>' . gmdate('Y-m-d\TH:i:s.0\Z') . '</host:crDate></host:creData>');
    }

    private function hostInfo(Session $session, \DOMXPath $xpath, \DOMElement $object)
    {
        $name = strtolower($xpath->evaluate('string(h:name)', $object));
        if (!isset($this->hosts[$name])) {
            return $this->result(2303, 'Object does not exist');
        }
        $host = $this->hosts[$name];
        $xml = '<host:infData xmlns:host="' . NS_HOST . '"><host:name>' . h($name) . '</host:name><host:roid>H' . crc32($name) . '-FAKE</host:roid><host:status s="ok"/>';
        foreach ($host['addr'] as $address) {
            $xml .= '<host:addr ip="' . (strpos($address, ':') !== false ? 'v6' : 'v4') . '">' . h($address) . '</host:addr>';
        }
        $xml .= '<host:clID>' . h($host['clID']) . '</host:clID><host:crID>' . h($host['clID']) . '</host:crID><host:crDate>2024-01-01T00:00:00.0Z</host:crDate></host:infData>';

        return $this->result(1000, 'Command completed successfully', $xml);
    }

    private function hostUpdate(Session $session, \DOMXPath $xpath, \DOMElement $object)
    {
        $name = strtolower($xpath->evaluate('string(h:name)', $object));
        if (!isset($this->hosts[$name])) {
            return $this->result(2303, 'Object does not exist');
        }
        $host = &$this->hosts[$name];
        foreach ($xpath->query('h:add/h:addr', $object) as $node) {
            if (in_array($node->textContent, $host['addr'], true)) {
                return $this->result(2306, 'Address already set');
            }
            $host['addr'][] = $node->textContent;
        }
        foreach ($xpath->query('h:rem/h:addr', $object) as $node) {
            if (!in_array($node->textContent, $host['addr'], true)) {
                return $this->result(2306, 'Address not set');
            }
            $host['addr'] = array_values(array_diff($host['addr'], [$node->textContent]));
        }

        return $this->result(1000, 'Command completed successfully');
    }

    private function hostDelete(Session $session, \DOMXPath $xpath, \DOMElement $object)
    {
        $name = strtolower($xpath->evaluate('string(h:name)', $object));
        if (!isset($this->hosts[$name])) {
            return $this->result(2303, 'Object does not exist');
        }
        foreach ($this->domains as $domain) {
            if (in_array($name, $domain['ns'], true)) {
                return $this->result(2305, 'Object association prohibits operation');
            }
        }
        unset($this->hosts[$name]);

        return $this->result(1000, 'Command completed successfully');
    }

    // ------------------------------------------------------------------ helpers

    private function dsFromNode(\DOMXPath $xpath, \DOMElement $node)
    {
        return [
            'keyTag' => (int) $xpath->evaluate('string(s:keyTag)', $node),
            'alg' => (int) $xpath->evaluate('string(s:alg)', $node),
            'digestType' => (int) $xpath->evaluate('string(s:digestType)', $node),
            'digest' => strtoupper($xpath->evaluate('string(s:digest)', $node)),
        ];
    }

    private function result($code, $message, $resData = '', $extension = '', $msgQ = '')
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="no"?>'
            . '<epp xmlns="' . NS_EPP . '"><response><result code="' . $code . '"><msg>' . h($message) . '</msg></result>'
            . $msgQ
            . ($resData !== '' ? '<resData>' . $resData . '</resData>' : '')
            . ($extension !== '' ? '<extension>' . $extension . '</extension>' : '')
            . '<trID>' . ($this->clTRID !== '' ? '<clTRID>' . h($this->clTRID) . '</clTRID>' : '') . '<svTRID>SV-' . (++$this->serial) . '</svTRID></trID>'
            . '</response></epp>';
    }
}

/**
 * The real EPP client stack (login, service negotiation, clTRID, response
 * parsing) with the TLS socket replaced by the simulator.
 */
class FakeClient extends AbstractClient
{
    private $registry;
    private $session;
    private $pending;

    public function __construct(array $config, FakeRegistry $registry)
    {
        parent::__construct($config);
        $this->registry = $registry;
        $this->session = new Session();
    }

    public function connect($newPassword = false)
    {
        $greeting = $this->registry->greeting();
        $this->negotiateServices($greeting);
        $this->login($newPassword);

        return $greeting;
    }

    public function close()
    {
        if ($this->session->loggedIn) {
            $this->request(new Logout());
        }

        return true;
    }

    protected function log($message)
    {
    }

    public function sendFrame(FrameInterface $frame)
    {
        $this->pending = $this->registry->handle($this->session, (string) $frame);
    }

    public function getFrame()
    {
        $frame = $this->pending;
        $this->pending = null;

        return $frame;
    }
}
