<?php

namespace WHMCS\Module\Registrar\Pandiwhmcs\EPP\Extension\NASK\Renew;

use WHMCS\Module\Registrar\Pandiwhmcs\EPP\ExtensionInterface;
use WHMCS\Module\Registrar\Pandiwhmcs\EPP\Frame\Command\Renew\Domain as DomainRenew;

class Domain extends DomainRenew implements ExtensionInterface
{
    protected $extension = 'extdom';
    protected $extension_xmlns = 'http://www.dns.pl/nask-epp-schema/extdom-2.0';

    public function setReactivate()
    {
        $this->set('//epp:epp/epp:command/epp:extension/extdom:renew/extdom:reactivate');
    }

    public function setRenewToDate($date)
    {
        $this->set('//epp:epp/epp:command/epp:extension/extdom:renew/extdom:renewToDate', $date);
    }
}
