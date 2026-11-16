<?php

/**
 * PANDI EPP registrar module for WHMCS
 *
 * Copyright (C) 2023-2026 PANDI (Pengelola Nama Domain Internet Indonesia) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if (version_compare(PHP_VERSION, '7.4.0', '<')) {
    throw new Exception('The pandiwhmcs registrar module requires PHP 7.4 or newer');
}
foreach (['dom', 'intl', 'openssl'] as $extension) {
    if (!extension_loaded($extension)) {
        throw new Exception(sprintf('The pandiwhmcs registrar module requires the PHP %s extension', $extension));
    }
}

// WHMCS autoloads WHMCS\Module\Registrar\<Name>\ from modules/registrars/<name>/lib/.
// Register the same PSR-4 mapping so the module does not depend on that.
spl_autoload_register(function ($class) {
    $prefix = 'WHMCS\\Module\\Registrar\\Pandiwhmcs\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
