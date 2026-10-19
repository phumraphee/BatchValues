<?php
namespace BatchValues\Job;

use BatchValues\Stdlib\Processor;
use Omeka\Job\AbstractJob;

class ApplyChangeSet extends AbstractJob
{
    public function perform()
    {
        $setId = (int) $this->getArg('change_set_id');
        /** @var Processor $processor */
        $processor = $this->getServiceLocator()->get(Processor::class);
        $processor->setShouldStop([$this, 'shouldStop']);
        $processor->run($setId);
    }
}
