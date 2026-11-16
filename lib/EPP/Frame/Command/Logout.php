<?php

/**
 * This file is part of the php-epp2 library.
 *
 * (c) Gunter Grodotzki <gunter@afri.cc>
 *
 * For the full copyright and license information, please view the LICENSE file
 * that was distributed with this source code.
 *
 * Modified for the PANDI WHMCS registrar module, see lib/EPP/README.md (last change 2026-11-16).
 */

namespace WHMCS\Module\Registrar\Pandiwhmcs\EPP\Frame\Command;

use WHMCS\Module\Registrar\Pandiwhmcs\EPP\Frame\Command as CommandFrame;
use WHMCS\Module\Registrar\Pandiwhmcs\EPP\ObjectSpec;

/**
 * @see http://tools.ietf.org/html/rfc5730#section-2.9.1.2
 */
class Logout extends CommandFrame
{
    public function __construct(?ObjectSpec $objectSpec = null)
    {
        parent::__construct($objectSpec);
        $this->set();
    }
}
