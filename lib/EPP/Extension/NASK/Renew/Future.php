<?php

namespace WHMCS\Module\Registrar\Pandiwhmcs\EPP\Extension\NASK\Renew;

use WHMCS\Module\Registrar\Pandiwhmcs\EPP\Frame\Command\Renew as RenewCommand;
use WHMCS\Module\Registrar\Pandiwhmcs\EPP\PeriodTrait;
use WHMCS\Module\Registrar\Pandiwhmcs\EPP\Validator;
use Exception;

class Future extends RenewCommand
{
    use PeriodTrait;

    /**
     * Set domain name for future (option)
     *
     * @param string $domain Domain Name
     *
     * @throws Exception on incorrect domain name
     */
    public function setFuture($domain)
    {
        if (!Validator::isHostname($domain)) {
            throw new Exception(sprintf('%s is not a valid domain name', $domain));
        }

        $this->set('future:name', $domain);
    }

    /**
     * Set current expiration date of future object
     *
     * @param string $curExpDate date in yyyy-mm-dd format
     */
    public function setCurrentExpirationDate($curExpDate)
    {
        $this->set('future:curExpDate', $curExpDate);
    }

    /**
     * Set renew period for future object
     *
     * Usually this should be 3y.
     *
     * @param string $period ending in y (for years) or m (for months)
     */
    public function setPeriod($period)
    {
        $this->appendPeriod('future:period[@unit=\'%s\']', $period);
    }
}
