<?php

/**
 * PANDI EPP registrar module for WHMCS
 *
 * Copyright (C) 2023-2026 PANDI (Pengelola Nama Domain Internet Indonesia) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace WHMCS\Module\Registrar\Pandiwhmcs;

/**
 * Optional file log in lib/logs/ (in addition to the WHMCS module log).
 *
 * Secrets are masked before writing. The directory ships with an .htaccess
 * that denies web access; on nginx deny it in the server config.
 */
class Logger
{
    /** @var string|null overrides lib/logs/ (used by the test suite) */
    public static $directory;

    public static function debug($action, $data)
    {
        self::write('', $action, $data);
    }

    public static function error($action, $data)
    {
        self::write('-error', $action, $data);
    }

    private static function write($suffix, $action, $data)
    {
        try {
            $dir = self::$directory !== null ? self::$directory : __DIR__ . '/logs';
            if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
                return;
            }

            $text = sprintf(
                "\n[%s] ================= %s =================\n%s\n",
                date('c'),
                $action,
                print_r(Helper::redactArray($data), true)
            );
            @file_put_contents($dir . '/' . date('Y-m-d') . $suffix . '.log', $text, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // logging must never break a registrar operation
        }
    }
}
