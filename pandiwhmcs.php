<?php

/**
 * PANDI EPP registrar module for WHMCS
 *
 * WHMCS registrar module for registries that speak EPP (RFC 5730-5734), with
 * DNSSEC (RFC 5910), registry grace period restore (RFC 3915) and secure
 * authorization information for transfer (RFC 9154).
 *
 * Copyright (C) 2023-2026 PANDI (Pengelola Nama Domain Internet Indonesia) and contributors
 * Original author: Ahmad Rofai <rofai@pandi.id>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/init.php';

use WHMCS\Domains\DomainLookup\ResultsList;
use WHMCS\Domains\DomainLookup\SearchResult;
use WHMCS\Module\Registrar\Pandiwhmcs\Connection;
use WHMCS\Module\Registrar\Pandiwhmcs\Contact;
use WHMCS\Module\Registrar\Pandiwhmcs\Dnssec;
use WHMCS\Module\Registrar\Pandiwhmcs\Domain;
use WHMCS\Module\Registrar\Pandiwhmcs\EppException;
use WHMCS\Module\Registrar\Pandiwhmcs\Helper;
use WHMCS\Module\Registrar\Pandiwhmcs\Host;
use WHMCS\Module\Registrar\Pandiwhmcs\Logger;
use WHMCS\Module\Registrar\Pandiwhmcs\Module;
use WHMCS\Module\Registrar\Pandiwhmcs\Poll;
use WHMCS\Module\Registrar\Pandiwhmcs\Store;

/**
 * APIVersion 1.1: WHMCS passes parameters raw instead of HTML-encoded.
 *
 * @return array
 */
function pandiwhmcs_MetaData()
{
    return [
        'DisplayName' => 'PANDI EPP Registrar',
        'APIVersion' => '1.1',
    ];
}

/**
 * @return array
 */
function pandiwhmcs_getConfigArray()
{
    $certificates = [];
    $dir = __DIR__ . '/lib/certificate';
    if (is_dir($dir)) {
        foreach (scandir($dir) as $entry) {
            if ($entry[0] !== '.' && is_file($dir . '/' . $entry) && preg_match('/\.(pem|crt|cer)$/i', $entry)) {
                $certificates[] = $entry;
            }
        }
    }

    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'PANDI EPP Registrar',
        ],
        'Description' => [
            'Type' => 'System',
            'Value' => 'EPP (RFC 5730-5734) registrar module with DNSSEC (RFC 5910), RGP restore (RFC 3915) and secure AuthInfo (RFC 9154).',
        ],
        'host' => [
            'FriendlyName' => 'EPP Server',
            'Type' => 'text',
            'Size' => '40',
            'Description' => 'Host name of the registry EPP server.',
        ],
        'port' => [
            'FriendlyName' => 'Server Port',
            'Type' => 'text',
            'Size' => '5',
            'Default' => '700',
            'Description' => 'Port 700 is assigned by IANA for EPP over TLS (RFC 5734).',
        ],
        'registrar_id' => [
            'FriendlyName' => 'Client ID',
            'Type' => 'text',
            'Size' => '20',
            'Description' => 'EPP client identifier (clID).',
        ],
        'pw' => [
            'FriendlyName' => 'Password',
            'Type' => 'password',
            'Size' => '20',
            'Description' => 'EPP password.',
        ],
        'local_cert' => [
            'FriendlyName' => 'Certificate',
            'Type' => 'dropdown',
            'Options' => implode(',', $certificates),
            'Description' => 'PEM client certificate (with its private key) from lib/certificate/.',
        ],
        'cert_path' => [
            'FriendlyName' => 'Certificate Path',
            'Type' => 'text',
            'Size' => '60',
            'Description' => 'Absolute path to the PEM client certificate. Overrides the dropdown; recommended, keep it outside the web root.',
        ],
        'key_path' => [
            'FriendlyName' => 'Private Key Path',
            'Type' => 'text',
            'Size' => '60',
            'Description' => 'Absolute path to the private key, only if it is not inside the certificate file.',
        ],
        'cert_passphrase' => [
            'FriendlyName' => 'Key Passphrase',
            'Type' => 'password',
            'Size' => '20',
            'Description' => 'Passphrase of the private key, if any.',
        ],
        'ca_file' => [
            'FriendlyName' => 'CA Bundle',
            'Type' => 'text',
            'Size' => '60',
            'Description' => 'PEM file of the CA that issued the registry server certificate. Empty uses the system CA store.',
        ],
        'tls_insecure' => [
            'FriendlyName' => 'Skip TLS Verification',
            'Type' => 'yesno',
            'Description' => 'Do not verify the registry server certificate. Test environments only: RFC 5734 requires verification.',
        ],
        'authinfo_mode' => [
            'FriendlyName' => 'AuthInfo Mode',
            'Type' => 'dropdown',
            'Options' => [
                Module::AUTHINFO_GENERATE => 'Generate a new code on request (RFC 9154)',
                Module::AUTHINFO_REGISTRY => 'Show the code stored at the registry (legacy)',
            ],
            'Default' => Module::AUTHINFO_GENERATE,
            'Description' => 'How the EPP/transfer code shown to the registrant is obtained.',
        ],
        'authinfo_length' => [
            'FriendlyName' => 'AuthInfo Length',
            'Type' => 'text',
            'Size' => '3',
            'Default' => (string) Helper::AUTHINFO_DEFAULT_LENGTH,
            'Description' => 'Length of generated codes (8-64). 20 gives the 128 bits of entropy RFC 9154 asks for; lower it only if the registry has a maximum.',
        ],
        'authinfo_ttl' => [
            'FriendlyName' => 'AuthInfo Lifetime (days)',
            'Type' => 'text',
            'Size' => '3',
            'Default' => '14',
            'Description' => 'A generated code is invalidated after this many days during the domain sync (RFC 9154 section 5.2). 0 = never.',
        ],
        'contact_mode' => [
            'FriendlyName' => 'Domain Contacts',
            'Type' => 'dropdown',
            'Options' => [
                Module::CONTACTS_ALL => 'Registrant + admin/tech/billing contact',
                Module::CONTACTS_TECH => 'Registrant + tech contact',
                Module::CONTACTS_NONE => 'Registrant only',
            ],
            'Default' => Module::CONTACTS_ALL,
            'Description' => 'Contacts linked to new registrations. Use the minimum the registry policy requires.',
        ],
        'transfer_no_period' => [
            'FriendlyName' => 'Omit Transfer Period',
            'Type' => 'yesno',
            'Description' => 'Do not send a period with transfer requests (for registries that reject it).',
        ],
        'TestMode' => [
            'FriendlyName' => 'Debug Log',
            'Type' => 'yesno',
            'Description' => 'Also write the EPP exchange to lib/logs/ (passwords and AuthInfo are masked).',
        ],
    ];
}

/**
 * Domain availability (RFC 5731 <domain:check>).
 *
 * @param array $params
 *
 * @throws Exception when the lookup fails
 *
 * @return ResultsList
 */
function pandiwhmcs_CheckAvailability($params)
{
    return Module::execute(__FUNCTION__, $params, function (array $params) {
        $lookup = Module::lookupNames($params);
        $checked = Connection::run($params, function (Connection $connection) use ($lookup) {
            return Domain::check($connection, array_keys($lookup));
        });

        $results = new ResultsList();
        foreach ($lookup as $name => $tld) {
            $result = new SearchResult($params['searchTerm'], $tld);
            if (!isset($checked[$name])) {
                $result->setStatus(SearchResult::STATUS_TLD_NOT_SUPPORTED);
            } elseif ($checked[$name]['avail']) {
                $result->setStatus(SearchResult::STATUS_NOT_REGISTERED);
            } else {
                $result->setStatus(SearchResult::STATUS_REGISTERED);
            }
            $results->append($result);
        }

        return $results;
    }, true);
}

/**
 * Available names among the TLDs WHMCS asks about.
 *
 * @param array $params
 *
 * @throws Exception when the lookup fails
 *
 * @return ResultsList
 */
function pandiwhmcs_GetDomainSuggestions($params)
{
    return Module::execute(__FUNCTION__, $params, function (array $params) {
        $lookup = Module::lookupNames($params);
        $checked = Connection::run($params, function (Connection $connection) use ($lookup) {
            return Domain::check($connection, array_keys($lookup));
        });

        $results = new ResultsList();
        foreach ($lookup as $name => $tld) {
            if (!empty($checked[$name]['avail'])) {
                $result = new SearchResult($params['searchTerm'], $tld);
                $result->setStatus(SearchResult::STATUS_NOT_REGISTERED);
                $result->setScore(1);
                $results->append($result);
            }
        }

        return $results;
    }, true);
}


/**
 * @param array $params
 *
 * @return array
 */
function pandiwhmcs_RegisterDomain($params)
{
    return Module::execute(__FUNCTION__, $params, function (array $params) {
        $name = Helper::domainName($params);
        $result = Connection::run($params, function (Connection $connection) use ($params) {
            return Domain::create($connection, $params);
        });

        Module::activity(sprintf('Domain %s %s', $name, $result['code'] === 1001 ? 'registration is pending at the registry' : 'registered'));
        if ($result['skipped_ns']) {
            Module::activity(sprintf(
                'Domain %s was registered without %s: register them as child nameservers, then save the nameservers again',
                $name,
                implode(', ', $result['skipped_ns'])
            ));
        }

        return ['success' => true];
    });
}

/**
 * @param array $params
 *
 * @return array
 */
function pandiwhmcs_TransferDomain($params)
{
    return Module::execute(__FUNCTION__, $params, function (array $params) {
        $code = Connection::run($params, function (Connection $connection) use ($params) {
            return Domain::transferRequest($connection, $params);
        });
        Module::activity(sprintf('Transfer of %s requested (result %d)', Helper::domainName($params), $code));

        return ['success' => true];
    });
}

/**
 * @param array $params
 *
 * @return array
 */
function pandiwhmcs_RenewDomain($params)
{
    return Module::execute(__FUNCTION__, $params, function (array $params) {
        $name = Helper::domainName($params);
        $result = Connection::run($params, function (Connection $connection) use ($params) {
            return Domain::renew($connection, $params);
        });

        if ($result['renewed']) {
            Module::activity(sprintf('Domain %s renewed%s', $name, $result['exDate'] ? ' until ' . $result['exDate'] : ''));
        } else {
            Module::activity(sprintf('Domain %s already runs until %s at the registry; no renew command sent', $name, $result['exDate']));
        }

        return ['success' => true];
    });
}

/**
 * @param array $params
 *
 * @return array
 */
function pandiwhmcs_GetNameservers($params)
{
    return Module::execute(__FUNCTION__, $params, function (array $params) {
        $hosts = Connection::run($params, function (Connection $connection) use ($params) {
            return Helper::nameservers(Domain::info($connection, Helper::domainName($params), null, 'del'));
        });

        $nameservers = [];
        for ($i = 1; $i <= 5; ++$i) {
            $nameservers['ns' . $i] = isset($hosts[$i - 1]) ? $hosts[$i - 1] : '';
        }

        return $nameservers;
    });
}

/**
 * @param array $params
 *
 * @return array
 */
function pandiwhmcs_SaveNameservers($params)
{
    return Module::execute(__FUNCTION__, $params, function (array $params) {
        $changed = Connection::run($params, function (Connection $connection) use ($params) {
            return Domain::updateNameservers($connection, $params);
        });
        if ($changed) {
            Module::activity(sprintf('Nameservers of %s updated', Helper::domainName($params)));
        }

        return ['success' => true];
    });
}

/**
 * @param array $params
 *
 * @return array
 */
function pandiwhmcs_GetContactDetails($params)
{
    return Module::execute(__FUNCTION__, $params, function (array $params) {
        return Connection::run($params, function (Connection $connection) use ($params) {
            return Contact::detailsForDomain($connection, Helper::domainName($params));
        });
    });
}

/**
 * @param array $params
 *
 * @return array
 */
function pandiwhmcs_SaveContactDetails($params)
{
    return Module::execute(__FUNCTION__, $params, function (array $params) {
        $changed = Connection::run($params, function (Connection $connection) use ($params) {
            return Contact::saveForDomain($connection, $params);
        });
        if ($changed) {
            Module::activity(sprintf('Contact data of %s updated', Helper::domainName($params)));
        }

        return ['success' => true];
    });
}

/**
 * @param array $params
 *
 * @return string|array 'locked', 'unlocked' or an error
 */
function pandiwhmcs_GetRegistrarLock($params)
{
    return Module::execute(__FUNCTION__, $params, function (array $params) {
        $statuses = Connection::run($params, function (Connection $connection) use ($params) {
            return Helper::statuses(Domain::info($connection, Helper::domainName($params), null, 'none'));
        });

        return in_array('clientTransferProhibited', $statuses, true) ? 'locked' : 'unlocked';
    });
}

/**
 * @param array $params
 *
 * @return array
 */
function pandiwhmcs_SaveRegistrarLock($params)
{
    return Module::execute(__FUNCTION__, $params, function (array $params) {
        $lock = isset($params['lockenabled']) && $params['lockenabled'] === 'locked';
        $name = Helper::domainName($params);
        $changed = Connection::run($params, function (Connection $connection) use ($name, $lock) {
            return $lock
                ? Domain::updateStatuses($connection, $name, Domain::LOCK_STATUSES, [])
                : Domain::updateStatuses($connection, $name, [], Domain::LOCK_STATUSES);
        });
        if ($changed) {
            Module::activity(sprintf('Registrar lock of %s %s', $name, $lock ? 'enabled' : 'disabled'));
        }

        return ['success' => 'success'];
    });
}

/**
 * EPP/transfer code. By default a fresh secure random code is set at the
 * registry and handed out (RFC 9154 section 5.2); it is invalidated again after
 * the configured lifetime by pandiwhmcs_Sync.
 *
 * @param array $params
 *
 * @return array
 */
function pandiwhmcs_GetEPPCode($params)
{
    return Module::execute(__FUNCTION__, $params, function (array $params) {
        $name = Helper::domainName($params);

        if (Module::authInfoMode($params) === Module::AUTHINFO_REGISTRY) {
            $info = Connection::run($params, function (Connection $connection) use ($name) {
                return Domain::info($connection, $name, null, 'none');
            });
            $code = isset($info['authInfo']['pw']) && is_string($info['authInfo']['pw']) ? $info['authInfo']['pw'] : '';
            if ($code === '') {
                throw new EppException('The registry did not return an AuthInfo code. Set "AuthInfo Mode" to generate a new code on request (RFC 9154).');
            }
            Module::addSecret($code);
            Module::activity(sprintf('AuthInfo code of %s disclosed', $name));

            return ['eppcode' => $code];
        }

        $code = Helper::authInfo(Module::authInfoLength($params));
        Module::addSecret($code);
        Connection::run($params, function (Connection $connection) use ($name, $code) {
            Domain::changeAuthInfo($connection, $name, $code);
        });

        try {
            Store::rememberAuthInfo($name, Module::authInfoTtlDays($params));
        } catch (\Exception $e) {
            Logger::error(__FUNCTION__, 'Could not record the AuthInfo lifetime: ' . $e->getMessage());
        }
        Module::activity(sprintf('New AuthInfo code issued for %s', $name));

        return ['eppcode' => $code];
    });
}

/**
 * Child nameserver with glue (RFC 5732 <host:create>).
 *
 * @param array $params
 *
 * @return array
 */
function pandiwhmcs_RegisterNameserver($params)
{
    return Module::execute(__FUNCTION__, $params, function (array $params) {
        $addresses = Host::parseAddresses($params['ipaddress']);
        Connection::run($params, function (Connection $connection) use ($params, $addresses) {
            Host::create($connection, $params['nameserver'], $addresses);
        });
        Module::activity(sprintf('Child nameserver %s created', $params['nameserver']));

        return ['success' => 'success'];
    });
}

/**
 * @param array $params
 *
 * @return array
 */
function pandiwhmcs_ModifyNameserver($params)
{
    return Module::execute(__FUNCTION__, $params, function (array $params) {
        $new = Host::parseAddresses($params['newipaddress']);
        $current = isset($params['currentipaddress']) ? trim((string) $params['currentipaddress']) : '';
        Connection::run($params, function (Connection $connection) use ($params, $current, $new) {
            Host::changeAddress($connection, $params['nameserver'], $current, $new[0]);
        });
        Module::activity(sprintf('Child nameserver %s updated', $params['nameserver']));

        return ['success' => 'success'];
    });
}

/**
 * @param array $params
 *
 * @return array
 */
function pandiwhmcs_DeleteNameserver($params)
{
    return Module::execute(__FUNCTION__, $params, function (array $params) {
        Connection::run($params, function (Connection $connection) use ($params) {
            Host::delete($connection, $params['nameserver']);
        });
        Module::activity(sprintf('Child nameserver %s deleted', $params['nameserver']));

        return ['success' => 'success'];
    });
}

/**
 * @param array $params
 *
 * @return array
 */
function pandiwhmcs_RequestDelete($params)
{
    return Module::execute(__FUNCTION__, $params, function (array $params) {
        $name = Helper::domainName($params);
        $code = Connection::run($params, function (Connection $connection) use ($name) {
            return Domain::delete($connection, $name);
        });
        Module::activity(sprintf('Domain %s %s', $name, $code === 1001 ? 'is pending deletion at the registry' : 'deleted'));

        return ['success' => 'success'];
    });
}

/**
 * Status and expiry date from the registry. Also drains the poll queue and
 * invalidates AuthInfo codes that passed their lifetime.
 *
 * @param array $params
 *
 * @return array
 */
function pandiwhmcs_Sync($params)
{
    return Module::execute(__FUNCTION__, $params, function (array $params) {
        $name = Helper::domainName($params);

        return Connection::run($params, function (Connection $connection) use ($params, $name) {
            Poll::drain($connection);

            try {
                $info = Domain::info($connection, $name, null, 'none');
            } catch (EppException $e) {
                if ($e->getCode() === 2201) {
                    return ['transferredAway' => true];
                }
                throw $e;
            }

            $sponsor = isset($info['clID']) && is_string($info['clID']) ? $info['clID'] : '';
            if ($sponsor !== '' && strcasecmp($sponsor, $connection->registrarId()) !== 0) {
                return ['transferredAway' => true];
            }

            $statuses = Helper::statuses($info);
            Domain::expireAuthInfo($connection, $params, $name, $statuses);

            $expiry = Helper::date(isset($info['exDate']) ? $info['exDate'] : null);
            if ($expiry === null) {
                throw new EppException('The registry did not return an expiry date for ' . $name);
            }

            $expired = $expiry < gmdate('Y-m-d');
            $pendingDelete = in_array('pendingDelete', $statuses, true);

            return [
                'expirydate' => $expiry,
                'active' => !$expired && !$pendingDelete,
                'expired' => $expired,
                'cancelled' => $pendingDelete && !$expired,
                'transferredAway' => false,
            ];
        });
    });
}

/**
 * Inbound transfer status (RFC 5731 transfer query).
 *
 * @param array $params
 *
 * @return array
 */
function pandiwhmcs_TransferSync($params)
{
    return Module::execute(__FUNCTION__, $params, function (array $params) {
        $name = Helper::domainName($params);

        return Connection::run($params, function (Connection $connection) use ($params, $name) {
            Poll::drain($connection);

            try {
                $transfer = Domain::transferQuery($connection, $params);
            } catch (EppException $e) {
                // 2301: no transfer pending any more; find out from the sponsor
                if (!in_array($e->getCode(), [2201, 2301, 2303], true)) {
                    throw $e;
                }
                $transfer = [];
            }

            $status = isset($transfer['trStatus']) ? (string) $transfer['trStatus'] : '';
            if ($status === 'pending') {
                return [];
            }

            $failures = [
                'clientRejected' => 'Rejected by the current registrar',
                'serverRejected' => 'Rejected by the registry',
                'clientCancelled' => 'Cancelled by the requesting registrar',
                'serverCancelled' => 'Cancelled by the registry',
            ];
            if (isset($failures[$status])) {
                return ['failed' => true, 'reason' => $failures[$status]];
            }

            // approved, or no longer pending: completed only if we sponsor the domain now
            $info = Domain::info($connection, $name, null, 'none');
            $sponsor = isset($info['clID']) && is_string($info['clID']) ? $info['clID'] : '';
            if (strcasecmp($sponsor, $connection->registrarId()) === 0) {
                $result = ['completed' => true];
                $expiry = Helper::date(isset($info['exDate']) ? $info['exDate'] : (isset($transfer['exDate']) ? $transfer['exDate'] : null));
                if ($expiry !== null) {
                    $result['expirydate'] = $expiry;
                }

                return $result;
            }

            return $status === '' ? ['failed' => true, 'reason' => 'The transfer is no longer pending at the registry'] : [];
        });
    });
}



/**
 * ===================================== Client Area =====================================
 */

/**
 * @return array
 */
function pandiwhmcs_ClientAreaCustomButtonArray()
{
    return [
        'Manage DNSSEC DS Records' => 'manageDNSSECDSRecords',
        'List Host' => 'listHost',
    ];
}

/**
 * @return array
 */
function pandiwhmcs_ClientAreaAllowedFunctions()
{
    return [
        'List Host' => 'listHost',
        'Manage DNSSEC DS Records' => 'manageDNSSECDSRecords',
    ];
}

/**
 * Child nameservers of the domain.
 *
 * @param array $params
 *
 * @return array
 */
function pandiwhmcs_listHost($params)
{
    $result = Module::execute(__FUNCTION__, $params, function (array $params) {
        return Connection::run($params, function (Connection $connection) use ($params) {
            return Host::subordinates($connection, Helper::domainName($params));
        });
    });

    $vars = ['HostRecords' => 'NO', 'HostRecordsList' => [], 'errorMessage' => ''];
    if (isset($result['error'])) {
        $vars['errorMessage'] = $result['error'];
    } else {
        foreach ($result as $host) {
            $vars['HostRecordsList'][] = ['name' => $host['name'], 'ip' => implode(', ', $host['addr'])];
        }
        $vars['HostRecords'] = $vars['HostRecordsList'] ? 'YES' : 'NO';
    }

    return [
        'templatefile' => 'manageListHost',
        'requirelogin' => true,
        'vars' => $vars,
    ];
}

/**
 * List, add and remove DS records (RFC 5910).
 *
 * @param array $params
 *
 * @return array
 */
function pandiwhmcs_manageDNSSECDSRecords($params)
{
    $command = '';
    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['command'])) {
        $command = (string) $_POST['command'];
        if (function_exists('check_token')) {
            check_token('WHMCS.default');
        }
    }
    $input = $_POST;

    $result = Module::execute(__FUNCTION__, $params, function (array $params) use ($command, $input) {
        return Connection::run($params, function (Connection $connection) use ($params, $command, $input) {
            $name = Helper::domainName($params);
            $message = '';
            $commandError = '';

            try {
                if ($command === 'secDNSadd') {
                    Dnssec::add($connection, $name, Dnssec::validate($input));
                    $message = 'The DS record was added.';
                    Module::activity(sprintf('DS record added to %s', $name));
                } elseif ($command === 'secDNSrem') {
                    Dnssec::remove($connection, $name, Dnssec::validate($input, true));
                    $message = 'The DS record was removed.';
                    Module::activity(sprintf('DS record removed from %s', $name));
                }
            } catch (\InvalidArgumentException $e) {
                $commandError = $e->getMessage();
            } catch (EppException $e) {
                $commandError = $e->getMessage();
            }

            $info = Domain::info($connection, $name, null, 'none');

            return [
                'records' => Dnssec::dsRecords($info),
                'keyData' => Dnssec::usesKeyData($info),
                'supported' => $connection->supports(Connection::EXT_SECDNS),
                'message' => $message,
                'commandError' => $commandError,
            ];
        });
    });

    $vars = [
        'DSRecords' => 'NO',
        'DSRecordslist' => [],
        'dnssecSupported' => true,
        'keyDataInterface' => false,
        'successMessage' => '',
        'errorMessage' => '',
        'algorithms' => Dnssec::ALGORITHMS,
        'digestTypes' => array_map(function ($type) {
            return $type[0];
        }, Dnssec::DIGEST_TYPES),
    ];
    if (isset($result['error'])) {
        $vars['errorMessage'] = $result['error'];
    } else {
        $vars['DSRecordslist'] = $result['records'];
        $vars['DSRecords'] = $result['records'] ? 'YES' : 'NO';
        $vars['dnssecSupported'] = $result['supported'];
        $vars['keyDataInterface'] = $result['keyData'];
        $vars['successMessage'] = $result['message'];
        $vars['errorMessage'] = $result['commandError'];
    }

    return [
        'templatefile' => 'manageDNSSECDSRecords',
        'requirelogin' => true,
        'vars' => $vars,
    ];
}

/**
 * ===================================== Admin Area =====================================
 */

/**
 * @param array $params
 *
 * @return array
 */
function pandiwhmcs_AdminCustomButtonArray($params)
{
    return [
        'Put Domain On Hold' => 'OnHoldDomain',
        'Remove Domain Hold' => 'UnHoldDomain',
        'Restore Domain (RGP)' => 'RestoreDomain',
    ];
}

/**
 * Add clientHold: the domain stays registered but is removed from the DNS (RFC 5731 section 2.3).
 *
 * @param array $params
 *
 * @return array
 */
function pandiwhmcs_OnHoldDomain($params)
{
    return Module::execute(__FUNCTION__, $params, function (array $params) {
        $name = Helper::domainName($params);
        Connection::run($params, function (Connection $connection) use ($name) {
            Domain::updateStatuses($connection, $name, ['clientHold'], []);
        });
        Module::activity(sprintf('Domain %s put on hold (clientHold)', $name));

        return ['success' => 'success'];
    });
}

/**
 * @param array $params
 *
 * @return array
 */
function pandiwhmcs_UnHoldDomain($params)
{
    return Module::execute(__FUNCTION__, $params, function (array $params) {
        $name = Helper::domainName($params);
        Connection::run($params, function (Connection $connection) use ($name) {
            Domain::updateStatuses($connection, $name, [], ['clientHold']);
        });
        Module::activity(sprintf('Domain %s removed from hold (clientHold)', $name));

        return ['success' => 'success'];
    });
}

/**
 * Restore a deleted domain from the redemption grace period (RFC 3915).
 *
 * @param array $params
 *
 * @return array
 */
function pandiwhmcs_RestoreDomain($params)
{
    return Module::execute(__FUNCTION__, $params, function (array $params) {
        $name = Helper::domainName($params);
        $outcome = Connection::run($params, function (Connection $connection) use ($params) {
            return Domain::restore($connection, $params);
        });
        Module::activity(sprintf(
            'Domain %s %s',
            $name,
            $outcome === 'reported' ? 'restore requested and restore report submitted (RGP)' : 'restored from the redemption period (RGP)'
        ));

        return ['success' => 'success'];
    });
}
