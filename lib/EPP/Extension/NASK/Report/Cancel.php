<?php

namespace WHMCS\Module\Registrar\Pandiwhmcs\EPP\Extension\NASK\Report;

use WHMCS\Module\Registrar\Pandiwhmcs\EPP\Extension\NASK\Report;

class Cancel extends Report
{
    /**
     * Set ID of report to cancel generation
     *
     * @param string $reportId ReportID of report to cancel
     */
    public function setReportId($reportId)
    {
        $this->set('extreport:cancel/extreport:extreportId', $reportId);
    }
}
