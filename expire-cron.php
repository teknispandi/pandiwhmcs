<?php

/**
 * PANDI EPP registrar module for WHMCS
 *
 * Optional cron (daily): point domains that passed their expiry date at the
 * "domain expired" page, through the PowerDNS API.
 *
 *   php -q /path/to/whmcs/modules/registrars/pandiwhmcs/expire-cron.php
 *
 * ICANN's Expired Registration Recovery Policy requires such a page to state
 * that the domain expired and explain how to renew it. This only changes zones
 * hosted on the configured PowerDNS; domains delegated elsewhere keep resolving.
 * DNS is not switched back after a renewal.
 *
 * Copyright (C) 2023-2026 PANDI (Pengelola Nama Domain Internet Indonesia) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/init.php';

use WHMCS\Database\Capsule;
use WHMCS\Module\Registrar\Pandiwhmcs\PowerDns;

if ((string) PANDIWHMCS_EXPIRED_IP === '') {
    fwrite(STDERR, "PANDIWHMCS_EXPIRED_IP is not configured\n");
    exit(1);
}

$pdns = PowerDns::fromConfig();
$failed = 0;

// every expired domain is handled, in batches (a plain LIMIT would pick the same rows every day)
Capsule::table('tbldomains')
    ->where('status', 'Active')
    ->where('expirydate', '>', '0000-00-00')
    ->where('expirydate', '<=', date('Y-m-d'))
    ->orderBy('id')
    ->select('id', 'domain')
    ->chunk(max(1, (int) PANDIWHMCS_CRON_BATCH), function ($rows) use ($pdns, &$failed) {
        foreach ($rows as $row) {
            try {
                $pdns->pointTo($row->domain, PANDIWHMCS_EXPIRED_IP);
                echo date('c') . " expired page for {$row->domain}\n";
            } catch (\Exception $e) {
                ++$failed;
                fwrite(STDERR, date('c') . " {$row->domain}: " . $e->getMessage() . "\n");
            }
        }
    });

exit($failed > 0 ? 1 : 0);
