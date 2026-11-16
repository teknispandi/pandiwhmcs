<?php

/**
 * Test suite: runs the WHMCS hook functions against an in-process registry
 * simulator and validates every EPP command against the IANA XSDs.
 *
 *   php tests/run.php
 *
 * The XSDs are downloaded from iana.org into tests/xsd/ on first use.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../pandiwhmcs.php';
require __DIR__ . '/FakeRegistry.php';

use PandiwhmcsTest\FakeClient;
use PandiwhmcsTest\FakeRegistry;
use WHMCS\Database\Capsule;
use WHMCS\Domains\DomainLookup\SearchResult;
use WHMCS\Module\Registrar\Pandiwhmcs\Connection;
use WHMCS\Module\Registrar\Pandiwhmcs\Helper;
use WHMCS\Module\Registrar\Pandiwhmcs\Logger;
use WHMCS\Module\Registrar\Pandiwhmcs\Store;

Logger::$directory = sys_get_temp_dir() . '/pandiwhmcs-test-logs';

// ---------------------------------------------------------------- schemas

$xsdDir = __DIR__ . '/xsd';
$schemas = ['eppcom-1.0', 'epp-1.0', 'host-1.0', 'domain-1.0', 'contact-1.0', 'secDNS-1.1', 'rgp-1.0'];
if (!is_dir($xsdDir)) {
    mkdir($xsdDir, 0755, true);
}
$imports = '';
foreach ($schemas as $schema) {
    $file = $xsdDir . '/' . $schema . '.xsd';
    if (!is_file($file)) {
        $content = @file_get_contents('https://www.iana.org/assignments/xml-registry/schema/' . $schema . '.xsd');
        if ($content === false) {
            fwrite(STDERR, "Could not download {$schema}.xsd from iana.org\n");
            exit(2);
        }
        file_put_contents($file, $content);
    }
    $namespace = $schema === 'eppcom-1.0' ? 'urn:ietf:params:xml:ns:eppcom-1.0' : 'urn:ietf:params:xml:ns:' . $schema;
    $imports .= '<import namespace="' . $namespace . '" schemaLocation="' . $schema . '.xsd"/>';
}
$schemaFile = $xsdDir . '/all.xsd';
file_put_contents($schemaFile, '<?xml version="1.0" encoding="UTF-8"?><schema xmlns="http://www.w3.org/2001/XMLSchema">' . $imports . '</schema>');

// ---------------------------------------------------------------- harness

const SECDNS = 'urn:ietf:params:xml:ns:secDNS-1.1';
const RGP = 'urn:ietf:params:xml:ns:rgp-1.0';
const SECURE = 'urn:ietf:params:xml:ns:epp:secure-authinfo-transfer-1.0';

/** @var FakeRegistry $registry */
$registry = null;

function registry(array $extensions = [SECDNS, RGP])
{
    global $registry, $schemaFile;
    $registry = new FakeRegistry($schemaFile, $extensions);
    Connection::$clientFactory = function (array $config) use ($registry) {
        return new FakeClient($config, $registry);
    };
    Capsule::$tables = [
        'tbldomains' => [(object) ['id' => 1, 'domain' => 'contoh.id', 'status' => 'Active', 'expirydate' => '0000-00-00']],
    ];
    $GLOBALS['moduleLog'] = [];
    $GLOBALS['activityLog'] = [];

    return $registry;
}

function params(array $extra = [])
{
    return array_merge([
        'host' => 'epp.test', 'port' => '700', 'registrar_id' => 'REG1', 'pw' => 'secretpw',
        'sld' => 'contoh', 'tld' => 'id', 'domainid' => 1, 'regperiod' => 1,
        'firstname' => 'Ahmad', 'lastname' => 'Rofaï', 'fullname' => 'Ahmad Rofaï', 'companyname' => 'PT Contoh & Rekan',
        'email' => 'ahmad@example.id', 'address1' => 'Jl. Merdeka No. 1', 'address2' => '', 'city' => 'Jakarta',
        'state' => 'DKI Jakarta', 'postcode' => '10110', 'countrycode' => 'ID', 'phonenumber' => '081234567890',
        'phonecc' => '62', 'fullphonenumber' => '+62.81234567890',
        'adminfirstname' => 'Budi', 'adminlastname' => 'Santoso', 'admincompanyname' => '', 'adminemail' => 'budi@example.id',
        'adminaddress1' => 'Jl. Sudirman 2', 'adminaddress2' => '', 'admincity' => 'Bandung', 'adminstate' => 'Jawa Barat',
        'adminpostcode' => '40111', 'admincountry' => 'ID', 'adminphonenumber' => '0221234567', 'adminfullphonenumber' => '+62.221234567',
        'ns1' => 'ns1.cloudflare.com', 'ns2' => 'NS2.Cloudflare.com.', 'ns3' => '', 'ns4' => '', 'ns5' => '',
    ], $extra);
}

function ok($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException('assertion failed: ' . $message);
    }
}

function same($expected, $actual, $message)
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf("%s\n  expected: %s\n  actual:   %s", $message, var_export($expected, true), var_export($actual, true)));
    }
}

function noError($result, $message)
{
    if (is_array($result) && isset($result['error'])) {
        throw new RuntimeException($message . ': ' . $result['error']);
    }
}

/** Register contoh.id for REG1 through the module */
function registered(array $extra = [])
{
    $result = pandiwhmcs_RegisterDomain(params($extra));
    noError($result, 'RegisterDomain');
}

$tests = [];

// ---------------------------------------------------------------- session

$tests['login negotiates only announced services; epp-1.0 is not an objURI'] = function () {
    $registry = registry([SECDNS]);
    pandiwhmcs_GetRegistrarLock(params()) ;
    $login = $registry->logins[0];
    ok(strpos($login, '<objURI>urn:ietf:params:xml:ns:epp-1.0</objURI>') === false, 'epp-1.0 must not be requested as object');
    ok(strpos($login, SECDNS) !== false, 'secDNS requested');
    ok(strpos($login, RGP) === false, 'rgp not announced, so not requested');
    ok(strpos($login, SECURE) === false, 'secure-authinfo not announced, so not requested');
};

$tests['every command logs out again'] = function () {
    $registry = registry();
    registered();
    pandiwhmcs_GetNameservers(params());
    $log = $GLOBALS['moduleLog'][count($GLOBALS['moduleLog']) - 1];
    ok(strpos($log['request'], '<logout/>') !== false, 'logout sent');
};

$tests['wrong password gives a clear error'] = function () {
    registry();
    $result = pandiwhmcs_GetNameservers(params(['pw' => 'wrong-password']));
    ok(isset($result['error']) && strpos($result['error'], '2200') !== false, 'EPP 2200 reported: ' . json_encode($result));
};

// ---------------------------------------------------------------- check

$tests['availability check handles several TLDs (was broken for >1 TLD)'] = function () {
    $registry = registry();
    registered();
    $results = pandiwhmcs_CheckAvailability([
        'host' => 'epp.test', 'registrar_id' => 'REG1', 'pw' => 'secretpw',
        'searchTerm' => 'contoh', 'tldsToInclude' => ['.id', '.co.id', 'web.id'], 'isIdnDomain' => false,
    ]);
    $statuses = [];
    foreach ($results as $result) {
        $statuses[$result->tld] = $result->status;
    }
    same(['id' => SearchResult::STATUS_REGISTERED, 'co.id' => SearchResult::STATUS_NOT_REGISTERED, 'web.id' => SearchResult::STATUS_NOT_REGISTERED], $statuses, 'statuses');
};

$tests['availability check with a single TLD and an IDN label'] = function () {
    $registry = registry();
    $results = pandiwhmcs_CheckAvailability([
        'host' => 'epp.test', 'registrar_id' => 'REG1', 'pw' => 'secretpw',
        'searchTerm' => 'bücher', 'punyCodeSearchTerm' => 'xn--bcher-kva', 'isIdnDomain' => true, 'tldsToInclude' => ['.id'],
    ]);
    same(1, count($results), 'one result');
    same(SearchResult::STATUS_NOT_REGISTERED, $results[0]->status, 'available');
    ok(strpos($registry->lastFrame('domain:check'), 'xn--bcher-kva.id') !== false, 'A-label sent');
};

$tests['availability check failure is thrown, as WHMCS expects'] = function () {
    registry();
    try {
        pandiwhmcs_CheckAvailability(['host' => 'epp.test', 'registrar_id' => 'REG1', 'pw' => 'wrong-password', 'searchTerm' => 'x', 'tldsToInclude' => ['.id']]);
    } catch (Exception $e) {
        return;
    }
    throw new RuntimeException('no exception thrown');
};

// ---------------------------------------------------------------- register

$tests['register creates host objects, valid contacts and a strong authInfo'] = function () {
    $registry = registry();
    registered();

    ok(isset($registry->hosts['ns1.cloudflare.com'], $registry->hosts['ns2.cloudflare.com']), 'external host objects created');
    $domain = $registry->domains['contoh.id'];
    same(['ns1.cloudflare.com', 'ns2.cloudflare.com'], $domain['ns'], 'delegation normalised');
    same(20, strlen($domain['authInfo']), 'authInfo length');

    $registrant = $registry->contacts[$domain['registrant']];
    ok(strlen($domain['registrant']) <= 16 && strlen($domain['registrant']) >= 3, 'contact id within clIDType');
    same('Ahmad Rofaï', $registrant['name'], 'loc name keeps UTF-8');
    same('Ahmad Rofai', $registrant['intName'], 'int name is ASCII');
    same('PT Contoh & Rekan', $registrant['org'], 'ampersand round-trips');
    same(['Jl. Merdeka No. 1'], $registrant['street'], 'empty street line omitted');
    same('+62.81234567890', $registrant['voice'], 'E.164 voice');
    ok($registrant['authInfo'] !== '', 'contact authInfo sent');

    $tech = $domain['contacts']['tech'][0];
    same([$tech], $domain['contacts']['admin'], 'admin = tech contact');
    same([$tech], $domain['contacts']['billing'], 'billing = tech contact');
    same('+62.221234567', $registry->contacts[$tech]['voice'], 'admin phone');
};

$tests['register with RFC 9154 registry sends empty authInfo'] = function () {
    $registry = registry([SECDNS, RGP, SECURE]);
    registered();
    same('', $registry->domains['contoh.id']['authInfo'], 'domain authInfo unset');
    ok(strpos($registry->logins[0], SECURE) !== false, 'secure-authinfo negotiated');
};

$tests['register with "registrant only" contact mode'] = function () {
    $registry = registry();
    registered(['contact_mode' => 'none']);
    same(['admin' => [], 'tech' => [], 'billing' => []], $registry->domains['contoh.id']['contacts'], 'no other contacts');
    same(1, count($registry->contacts), 'only the registrant contact exists');
};

$tests['failed registration removes the contacts it created'] = function () {
    $registry = registry();
    $registry->authInfoMinLength = 30;
    $result = pandiwhmcs_RegisterDomain(params());
    ok(isset($result['error']) && strpos($result['error'], '2306') !== false, 'policy error reported: ' . json_encode($result));
    same([], $registry->contacts, 'no orphaned contacts');
};

$tests['in-bailiwick nameserver without glue is skipped on register'] = function () {
    $registry = registry();
    registered(['ns1' => 'ns1.contoh.id', 'ns2' => 'ns2.cloudflare.com']);
    same(['ns2.cloudflare.com'], $registry->domains['contoh.id']['ns'], 'only resolvable NS delegated');
    ok((bool) preg_grep('/ns1\.contoh\.id/', $GLOBALS['activityLog']), 'activity log explains the skipped NS');
};

$tests['invalid phone number is rejected before contacting the registry'] = function () {
    $registry = registry();
    $result = pandiwhmcs_RegisterDomain(params(['fullphonenumber' => '', 'phonenumber' => '+44 20 7946 0000', 'phonecc' => '62']));
    ok(isset($result['error']) && strpos($result['error'], '+CC.') !== false, 'phone error: ' . json_encode($result));
    same([], $registry->domains, 'nothing registered');
};

// ---------------------------------------------------------------- nameservers

$tests['nameservers are read and only the difference is sent (rem/add order valid)'] = function () {
    $registry = registry();
    registered();
    same(['ns1' => 'ns1.cloudflare.com', 'ns2' => 'ns2.cloudflare.com', 'ns3' => '', 'ns4' => '', 'ns5' => ''], pandiwhmcs_GetNameservers(params()), 'GetNameservers');

    noError(pandiwhmcs_SaveNameservers(params(['ns2' => 'ns3.cloudflare.com'])), 'SaveNameservers');
    same(['ns1.cloudflare.com', 'ns3.cloudflare.com'], $registry->domains['contoh.id']['ns'], 'delegation after save');

    $updates = count(array_keys($registry->commands(), 'domain:update'));
    noError(pandiwhmcs_SaveNameservers(params(['ns2' => 'ns3.cloudflare.com'])), 'SaveNameservers unchanged');
    same($updates, count(array_keys($registry->commands(), 'domain:update')), 'no update when unchanged');
};

$tests['single nameserver response does not break GetNameservers'] = function () {
    $registry = registry();
    registered(['ns2' => '']);
    same('ns1.cloudflare.com', pandiwhmcs_GetNameservers(params())['ns1'], 'ns1');
};

// ---------------------------------------------------------------- lock & hold

$tests['registrar lock reads and writes statuses idempotently'] = function () {
    $registry = registry();
    registered();
    same('unlocked', pandiwhmcs_GetRegistrarLock(params()), 'initially unlocked');
    noError(pandiwhmcs_SaveRegistrarLock(params(['lockenabled' => 'locked'])), 'lock');
    same('locked', pandiwhmcs_GetRegistrarLock(params()), 'locked after save');
    noError(pandiwhmcs_SaveRegistrarLock(params(['lockenabled' => 'locked'])), 'lock again is a no-op');
    noError(pandiwhmcs_SaveRegistrarLock(params(['lockenabled' => 'unlocked'])), 'unlock');
    same([], $registry->domains['contoh.id']['status'], 'statuses removed');
};

$tests['client hold on/off'] = function () {
    $registry = registry();
    registered();
    noError(pandiwhmcs_OnHoldDomain(params()), 'hold');
    same(['clientHold'], $registry->domains['contoh.id']['status'], 'clientHold set');
    noError(pandiwhmcs_UnHoldDomain(params()), 'unhold');
    same([], $registry->domains['contoh.id']['status'], 'clientHold removed');
    same('0000-00-00', Capsule::$tables['tbldomains'][0]->expirydate, 'domain notes/fields untouched');
};

// ---------------------------------------------------------------- authInfo

$tests['EPP code is generated, set at the registry and expires after its TTL'] = function () {
    $registry = registry();
    registered();
    $before = $registry->domains['contoh.id']['authInfo'];
    $result = pandiwhmcs_GetEPPCode(params());
    noError($result, 'GetEPPCode');
    same(20, strlen($result['eppcode']), 'code length');
    ok($result['eppcode'] !== $before, 'new code');
    same($result['eppcode'], $registry->domains['contoh.id']['authInfo'], 'code set at registry');
    same(1, count(Capsule::$tables[Store::AUTHINFO_TABLE]), 'lifetime recorded');

    Capsule::$tables[Store::AUTHINFO_TABLE][0]->expires_at = '2000-01-01 00:00:00';
    noError(pandiwhmcs_Sync(params()), 'Sync');
    ok($registry->domains['contoh.id']['authInfo'] !== $result['eppcode'], 'disclosed code invalidated');
    same(0, count(Capsule::$tables[Store::AUTHINFO_TABLE]), 'lifetime record removed');
};

$tests['EPP code expiry unsets authInfo on an RFC 9154 registry'] = function () {
    $registry = registry([SECDNS, RGP, SECURE]);
    registered();
    $result = pandiwhmcs_GetEPPCode(params());
    same($result['eppcode'], $registry->domains['contoh.id']['authInfo'], 'code set');
    Capsule::$tables[Store::AUTHINFO_TABLE][0]->expires_at = '2000-01-01 00:00:00';
    pandiwhmcs_Sync(params());
    same('', $registry->domains['contoh.id']['authInfo'], 'authInfo unset');
};

$tests['legacy AuthInfo mode returns the stored code'] = function () {
    $registry = registry();
    registered();
    $result = pandiwhmcs_GetEPPCode(params(['authinfo_mode' => 'registry']));
    same($registry->domains['contoh.id']['authInfo'], $result['eppcode'], 'stored code');
};

// ---------------------------------------------------------------- renew

$tests['renew sends curExpDate as a date and advances the expiry'] = function () {
    $registry = registry();
    registered();
    $exDate = substr($registry->domains['contoh.id']['exDate'], 0, 10);
    Capsule::$tables['tbldomains'][0]->expirydate = $exDate;

    noError(pandiwhmcs_RenewDomain(params()), 'RenewDomain');
    ok(strpos($registry->lastFrame('domain:renew'), '<domain:curExpDate>' . $exDate . '</domain:curExpDate>') !== false, 'xs:date curExpDate');
    same((string) ((int) substr($exDate, 0, 4) + 1), substr($registry->domains['contoh.id']['exDate'], 0, 4), 'one year added');
};

$tests['renew is skipped when the registry already extended the domain'] = function () {
    $registry = registry();
    registered();
    $exDate = substr($registry->domains['contoh.id']['exDate'], 0, 10);
    Capsule::$tables['tbldomains'][0]->expirydate = date('Y-m-d', strtotime($exDate . ' -1 year'));

    noError(pandiwhmcs_RenewDomain(params()), 'RenewDomain');
    ok(!in_array('domain:renew', $registry->commands(), true), 'no renew command');
    same($exDate, substr($registry->domains['contoh.id']['exDate'], 0, 10), 'expiry unchanged');
};

$tests['renew of a deleted domain points to RGP restore'] = function () {
    $registry = registry();
    registered();
    noError(pandiwhmcs_RequestDelete(params()), 'RequestDelete');
    $result = pandiwhmcs_RenewDomain(params());
    ok(isset($result['error']) && strpos($result['error'], 'Restore') !== false, 'restore hint: ' . json_encode($result));
};

// ---------------------------------------------------------------- delete & restore

$tests['delete (pending, 1001) and RGP restore with report'] = function () {
    $registry = registry();
    registered();
    noError(pandiwhmcs_RequestDelete(params()), 'RequestDelete accepts 1001');
    same(['redemptionPeriod'], $registry->domains['contoh.id']['rgp'], 'in redemption');

    $sync = pandiwhmcs_Sync(params());
    same(true, $sync['cancelled'], 'Sync reports cancelled');

    noError(pandiwhmcs_RestoreDomain(params()), 'RestoreDomain');
    same([], $registry->domains['contoh.id']['rgp'], 'restored');
    same([], $registry->domains['contoh.id']['status'], 'pendingDelete cleared');
};

$tests['restore without RGP extension is refused clearly'] = function () {
    registry([SECDNS]);
    registered();
    $result = pandiwhmcs_RestoreDomain(params());
    ok(isset($result['error']) && strpos($result['error'], 'RFC 3915') !== false, 'error: ' . json_encode($result));
};

// ---------------------------------------------------------------- contacts

$tests['contact details round-trip with standard WHMCS labels and Postcode'] = function () {
    $registry = registry();
    registered();
    $details = pandiwhmcs_GetContactDetails(params());
    noError($details, 'GetContactDetails');
    same(['Registrant', 'Technical'], array_keys($details), 'one block per distinct contact');
    same('Ahmad', $details['Registrant']['First Name'], 'first name');
    same('Rofaï', $details['Registrant']['Last Name'], 'last name');
    same('Budi', $details['Technical']['First Name'], 'technical is the tech contact, not the registrant');

    $details['Registrant']['Postcode'] = '12190';
    $details['Registrant']['Phone Number'] = '0811 2233 4455';
    noError(pandiwhmcs_SaveContactDetails(params(['contactdetails' => $details])), 'SaveContactDetails');
    $registrant = $registry->contacts[$registry->domains['contoh.id']['registrant']];
    same('12190', $registrant['pc'], 'postcode saved (was lost before)');
    same('+62.81122334455', $registrant['voice'], 'national number converted to E.164');
};

$tests['contacts of another registrar are replaced after a transfer'] = function () {
    $registry = registry();
    registered();
    $oldRegistrant = $registry->domains['contoh.id']['registrant'];
    foreach ($registry->contacts as $id => $contact) {
        $registry->contacts[$id]['clID'] = 'REG2';
    }

    $details = [
        'Registrant' => ['First Name' => 'Citra', 'Last Name' => 'Dewi', 'Company Name' => '', 'Email Address' => 'citra@example.id',
            'Address 1' => 'Jl. Asia Afrika 8', 'Address 2' => '', 'City' => 'Bandung', 'State' => 'Jawa Barat', 'Postcode' => '40111',
            'Country' => 'ID', 'Phone Number' => '+62.8111111111', 'Fax Number' => ''],
    ];
    noError(pandiwhmcs_SaveContactDetails(params(['contactdetails' => $details])), 'SaveContactDetails');
    $newRegistrant = $registry->domains['contoh.id']['registrant'];
    ok($newRegistrant !== $oldRegistrant, 'registrant replaced');
    same('Citra Dewi', $registry->contacts[$newRegistrant]['name'], 'new registrant data');
};

// ---------------------------------------------------------------- hosts

$tests['child nameservers: create, list, modify, delete'] = function () {
    $registry = registry();
    registered();
    noError(pandiwhmcs_RegisterNameserver(params(['nameserver' => 'ns1.contoh.id', 'ipaddress' => '103.10.10.10, 2404:6800::10'])), 'RegisterNameserver');
    same(['103.10.10.10', '2404:6800::10'], $registry->hosts['ns1.contoh.id']['addr'], 'glue');

    $list = pandiwhmcs_listHost(params());
    same('manageListHost', $list['templatefile'], 'template');
    same([['name' => 'ns1.contoh.id', 'ip' => '103.10.10.10, 2404:6800::10']], $list['vars']['HostRecordsList'], 'listed');

    noError(pandiwhmcs_ModifyNameserver(params(['nameserver' => 'ns1.contoh.id', 'currentipaddress' => '103.10.10.10', 'newipaddress' => '103.10.10.20'])), 'Modify');
    same(['2404:6800::10', '103.10.10.20'], $registry->hosts['ns1.contoh.id']['addr'], 'address replaced');

    noError(pandiwhmcs_DeleteNameserver(params(['nameserver' => 'ns1.contoh.id'])), 'Delete');
    ok(!isset($registry->hosts['ns1.contoh.id']), 'deleted');
};

$tests['private glue address is rejected'] = function () {
    registry();
    registered();
    $result = pandiwhmcs_RegisterNameserver(params(['nameserver' => 'ns1.contoh.id', 'ipaddress' => '192.168.1.1']));
    ok(isset($result['error']) && strpos($result['error'], 'public') !== false, 'error: ' . json_encode($result));
};

// ---------------------------------------------------------------- DNSSEC

$tests['DS records: add, list, remove (remove was unreachable before)'] = function () {
    $registry = registry();
    registered();
    $digest = str_repeat('AB', 32);

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['command' => 'secDNSadd', 'keyTag' => '12345', 'alg' => '13', 'digestType' => '2', 'digest' => strtolower($digest)];
    $page = pandiwhmcs_manageDNSSECDSRecords(params());
    same('', $page['vars']['errorMessage'], 'no error');
    same([['keyTag' => 12345, 'alg' => 13, 'digestType' => 2, 'digest' => $digest]], $page['vars']['DSRecordslist'], 'listed after add');
    ok($GLOBALS['tokenChecks'] > 0, 'CSRF token checked');

    $_POST = ['command' => 'secDNSrem', 'keyTag' => '12345', 'alg' => '13', 'digestType' => '2', 'digest' => $digest];
    $page = pandiwhmcs_manageDNSSECDSRecords(params());
    same('', $page['vars']['errorMessage'], 'no error on remove');
    same([], $registry->domains['contoh.id']['ds'], 'removed at registry');
    $_POST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
};

$tests['DS validation follows RFC 8624'] = function () {
    $registry = registry();
    registered();
    $_SERVER['REQUEST_METHOD'] = 'POST';
    foreach ([
        ['alg' => '13', 'digestType' => '1', 'digest' => str_repeat('A', 40)],
        ['alg' => '5', 'digestType' => '2', 'digest' => str_repeat('A', 63)],
        ['alg' => '12', 'digestType' => '2', 'digest' => str_repeat('A', 64)],
    ] as $case) {
        $_POST = ['command' => 'secDNSadd', 'keyTag' => '1'] + $case;
        $page = pandiwhmcs_manageDNSSECDSRecords(params());
        ok($page['vars']['errorMessage'] !== '', 'rejected: ' . json_encode($case));
    }
    same([], $registry->domains['contoh.id']['ds'], 'nothing added');
    $_POST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
};

$tests['DNSSEC page without secDNS support'] = function () {
    registry([RGP]);
    registered();
    $page = pandiwhmcs_manageDNSSECDSRecords(params());
    same(false, $page['vars']['dnssecSupported'], 'unsupported flag');
};

// ---------------------------------------------------------------- transfers & poll

$tests['inbound transfer: request, pending, completed via TransferSync'] = function () {
    $registry = registry();
    $registry->domains['pindah.id'] = [
        'roid' => 'D9-FAKE', 'status' => [], 'registrant' => 'X', 'contacts' => ['admin' => [], 'tech' => [], 'billing' => []],
        'ns' => [], 'clID' => 'REG2', 'crID' => 'REG2', 'crDate' => '2020-01-01T00:00:00.0Z', 'exDate' => '2027-01-01T00:00:00.0Z',
        'authInfo' => 'Tr4nsfer&Code#1', 'ds' => [], 'rgp' => [],
    ];
    $p = params(['sld' => 'pindah', 'eppcode' => ' Tr4nsfer&Code#1 ']);

    noError(pandiwhmcs_TransferDomain($p), 'TransferDomain');
    same([], pandiwhmcs_TransferSync($p), 'pending');

    $registry->approveTransfer('pindah.id');
    $sync = pandiwhmcs_TransferSync($p);
    same(true, $sync['completed'], 'completed');
    same('2028-01-01', $sync['expirydate'], 'expiry from registry');
    ok(empty($registry->queues['REG1']), 'poll messages acknowledged');

    foreach ($GLOBALS['moduleLog'] as $entry) {
        unset($entry['replaceVars']);
        ok(strpos(json_encode($entry), 'Tr4nsfer') === false, 'EPP code never logged');
    }
};

$tests['wrong EPP code reports 2202'] = function () {
    $registry = registry();
    $registry->domains['pindah.id'] = [
        'roid' => 'D9-FAKE', 'status' => [], 'registrant' => 'X', 'contacts' => ['admin' => [], 'tech' => [], 'billing' => []],
        'ns' => [], 'clID' => 'REG2', 'crID' => 'REG2', 'crDate' => '2020-01-01T00:00:00.0Z', 'exDate' => '2027-01-01T00:00:00.0Z',
        'authInfo' => 'right-code-123', 'ds' => [], 'rgp' => [],
    ];
    $result = pandiwhmcs_TransferDomain(params(['sld' => 'pindah', 'eppcode' => 'wrong-code-123']));
    ok(isset($result['error']) && strpos($result['error'], '2202') !== false, 'error: ' . json_encode($result));
};

$tests['outbound transfer: poll marks Transferred Away, Sync reports it'] = function () {
    $registry = registry();
    registered();
    $registry->domains['contoh.id']['authInfo'] = 'out-going-code';
    $registry->credentials['REG2'] = 'otherpw';
    $gaining = params(['registrar_id' => 'REG2', 'pw' => 'otherpw', 'eppcode' => 'out-going-code']);
    noError(pandiwhmcs_TransferDomain($gaining), 'gaining registrar requests');
    $registry->approveTransfer('contoh.id');

    $sync = pandiwhmcs_Sync(params());
    same(true, $sync['transferredAway'], 'transferred away');
    same('Transferred Away', Capsule::$tables['tbldomains'][0]->status, 'poll updated WHMCS status');
    ok(empty($registry->queues['REG1']), 'queue drained');
};

$tests['sync of an active domain'] = function () {
    $registry = registry();
    registered();
    $sync = pandiwhmcs_Sync(params());
    noError($sync, 'Sync');
    same(substr($registry->domains['contoh.id']['exDate'], 0, 10), $sync['expirydate'], 'expiry');
    same(true, $sync['active'], 'active');
    same(false, $sync['expired'], 'not expired');
    same(false, $sync['transferredAway'], 'still ours (was always false before)');
};

// ---------------------------------------------------------------- logging

$tests['module log masks passwords and AuthInfo'] = function () {
    registry();
    registered();
    $code = pandiwhmcs_GetEPPCode(params())['eppcode'];
    $log = json_encode(array_map(function ($entry) {
        unset($entry['replaceVars']); // the values WHMCS masks, never stored as such

        return $entry;
    }, $GLOBALS['moduleLog']));
    ok(strpos($log, 'secretpw') === false, 'EPP password not in log');
    ok(strpos($log, json_encode($code)) === false && strpos($log, htmlspecialchars($code, ENT_XML1)) === false, 'AuthInfo not in log');
    $last = $GLOBALS['moduleLog'][count($GLOBALS['moduleLog']) - 1];
    ok(in_array('secretpw', $last['replaceVars'], true) && in_array($code, $last['replaceVars'], true), 'secrets passed as replaceVars');
};

// ---------------------------------------------------------------- helpers

$tests['helper conversions'] = function () {
    same('+62.81234567890', Helper::phone('0812-3456-7890', '62'), 'national');
    same('+62.81234567890', Helper::phone('+62 812 3456 7890', '62'), 'international with spaces');
    same('+62.81234567890', Helper::phone('+62.81234567890'), 'already E.164');
    same('xn--bcher-kva.id', Helper::hostName('Bücher.ID.'), 'IDN to A-label');
    same('2026-06-05', Helper::date('2026-06-05T23:59:59.0Z'), 'date part');
    same(['Siti', 'Nur Aisyah'], Helper::splitName('Siti  Nur Aisyah'), 'split name');
    same(16, strlen(Helper::contactId('REGISTRAR-WITH-A-LONG-ID')), 'contact id length');
    ok(preg_match('/[A-Z]/', Helper::authInfo(8)) && preg_match('/[0-9]/', Helper::authInfo(8)), 'authInfo classes');
};

// ---------------------------------------------------------------- run

$failed = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        global $registry;
        if ($registry && $registry->schemaErrors) {
            $error = $registry->schemaErrors[0];
            throw new RuntimeException("schema violation:\n  " . implode("\n  ", $error['errors']) . "\n" . $error['xml']);
        }
        echo "  ok   {$name}\n";
    } catch (Throwable $e) {
        ++$failed;
        echo "  FAIL {$name}\n       " . str_replace("\n", "\n       ", $e->getMessage()) . "\n       at " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

printf("\n%d tests, %d failed\n", count($tests), $failed);
exit($failed > 0 ? 1 : 0);
