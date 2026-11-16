<?php

/**
 * PANDI EPP registrar module for WHMCS
 *
 * Copyright (C) 2023-2026 PANDI (Pengelola Nama Domain Internet Indonesia) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace WHMCS\Module\Registrar\Pandiwhmcs;

/**
 * An EPP error result (RFC 5730 section 3) or a transport/protocol failure.
 *
 * getCode() is the EPP result code, or 0 when no EPP response was received.
 */
class EppException extends \Exception
{
    /**
     * Short explanations of the RFC 5730 section 3 error codes, shown next to the
     * server's own message so the admin/client knows what to do.
     */
    const HINTS = [
        2001 => 'command syntax error',
        2002 => 'command use error',
        2003 => 'required parameter missing',
        2004 => 'parameter value range error',
        2005 => 'parameter value syntax error',
        2101 => 'unimplemented protocol version',
        2102 => 'unimplemented command',
        2103 => 'unimplemented option',
        2104 => 'billing failure (check the registrar balance)',
        2105 => 'object is not eligible for renewal',
        2106 => 'object is not eligible for transfer',
        2200 => 'authentication error',
        2201 => 'authorization error (object is not sponsored by this registrar)',
        2202 => 'invalid authorization information (wrong EPP/auth code)',
        2300 => 'object pending transfer',
        2301 => 'object not pending transfer',
        2302 => 'object exists',
        2303 => 'object does not exist',
        2304 => 'object status prohibits operation',
        2305 => 'object association prohibits operation',
        2306 => 'parameter value policy error',
        2307 => 'unimplemented object service',
        2308 => 'data management policy violation',
        2400 => 'command failed',
        2500 => 'command failed; server closing connection',
        2501 => 'authentication error; server closing connection',
        2502 => 'session limit exceeded; server closing connection',
    ];

    /** @var string|null */
    private $serverMessage;

    /** @var string|null */
    private $reason;

    public function __construct($message, $code = 0, $reason = null, ?\Throwable $previous = null)
    {
        $this->serverMessage = $message;
        $this->reason = $reason;

        $text = $message;
        if ($code >= 2000) {
            $hint = array_key_exists($code, self::HINTS) ? self::HINTS[$code] : null;
            $text = sprintf('EPP %d: %s', $code, $message !== '' && $message !== null ? $message : $hint);
            if ($hint !== null && $message !== '' && $message !== null) {
                // "invalid authorization information (wrong EPP/auth code)": add only
                // what the server message does not already say
                $core = trim(preg_replace('/\s*\(.*\)$/', '', $hint));
                if (stripos($message, $core) === false) {
                    $text .= ' (' . $hint . ')';
                } elseif (preg_match('/\((.+)\)$/', $hint, $matches)) {
                    $text .= ' (' . $matches[1] . ')';
                }
            }
            if ($reason !== null && $reason !== '') {
                $text .= ' - ' . $reason;
            }
        }

        parent::__construct($text, (int) $code, $previous);
    }

    public function getServerMessage()
    {
        return $this->serverMessage;
    }

    public function getReason()
    {
        return $this->reason;
    }
}
