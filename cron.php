<?php

/**
 * PANDI EPP registrar module for WHMCS
 *
 * Optional cron (every 5 minutes): point new domains that have no active
 * hosting service at the parking page, through the PowerDNS API.
 *
 *   php -q /path/to/whmcs/modules/registrars/pandiwhmcs/cron.php
 *
 * Needs the table tbldomain_parking (see README.md).
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

if ((string) PANDIWHMCS_PARKING_IP === '') {
    fwrite(STDERR, "PANDIWHMCS_PARKING_IP is not configured\n");
    exit(1);
}

$pdns = PowerDns::fromConfig();

// domains already parked are excluded in SQL, so every run picks up the next batch
$domains = Capsule::table('tbldomains as d')
    ->leftJoin('tbldomain_parking as p', 'p.domain', '=', 'd.domain')
    ->whereNull('p.id')
    ->where('d.status', 'Active')
    ->whereDate('d.registrationdate', '>=', date('Y-m-d', strtotime('-1 day')))
    ->whereNotExists(function ($query) {
        $query->select(Capsule::raw(1))
            ->from('tblhosting as h')
            ->whereRaw('h.domain = d.domain')
            ->where('h.domainstatus', 'Active');
    })
    ->orderBy('d.id')
    ->limit(max(1, (int) PANDIWHMCS_CRON_BATCH))
    ->pluck('d.domain');

$failed = 0;
foreach ($domains as $domain) {
    try {
        $pdns->pointTo($domain, PANDIWHMCS_PARKING_IP);
        Capsule::table('tbldomain_parking')->insert(['domain' => $domain, 'isParking' => true]);
        echo date('c') . " parked {$domain}\n";
    } catch (\Exception $e) {
        ++$failed;
        fwrite(STDERR, date('c') . " {$domain}: " . $e->getMessage() . "\n");
    }
}

exit($failed > 0 ? 1 : 0);
