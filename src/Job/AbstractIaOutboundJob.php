<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Job;

use Laminas\Log\Logger;
use Omeka\Job\AbstractJob;

abstract class AbstractIaOutboundJob extends AbstractJob
{
    protected function getJobLogger(): Logger
    {
        return $this->getServiceLocator()->get('Omeka\Logger');
    }
}
