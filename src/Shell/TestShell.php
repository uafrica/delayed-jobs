<?php
namespace DelayedJobs\Shell;

use Cake\Console\Shell;
use Cake\Event\Event;
use Cake\Event\EventManager;
use DelayedJobs\Lock;

/**
 * Class TestShell
 * @package DelayedJobs\Shell
 * @property \DelayedJobs\Model\Table\DelayedJobsTable $DelayedJobs
 */
class TestShell extends Shell
{
    public $Lock;
    public $modelClass = 'DelayedJobs.DelayedJobs';

    /**
     * @return bool
     * @throws \Exception Exception.
     */
    public function main()
    {
        $this->Lock = new Lock();
        if (!$this->Lock->lock('DelayedJobs.TestShell.main')) {
            $this->_stop(1);
        }

        $this->out('<info>Started up</info>', 1, Shell::VERBOSE);

        //** Loading Job that will succeed */
        $dj_data = [
            'group' => 'DelayedJobs.Tester',
            'class' => 'DelayedJobs\Model\Table\DelayedJobsTable',
            'method' => 'tester',
            'payload' => ["success" => true],
            'priority' => 1,
            'options' => ['max_retries' => 1],
        ];
        $job_event = new Event('DelayedJob.queue', $dj_data);
        EventManager::instance()->dispatch($job_event);

        $result = $job_event->result;

        $job_id = $result->id;

        if (!$result) {
            throw new \Exception("Success Job could not be loaded");
        }

        $this->out(
            '<success>Loaded Successful Job: Waiting 15 seconds to check if it was successfull</success>',
            1,
            Shell::VERBOSE
        );

        sleep(10);

        $job = $this->DelayedJobs->get($job_id);

        if ($job->status != 4) {
            throw new \Exception("Successful job was not successfull");
        }

        $this->out('<success>Test Success</success>', 1, Shell::VERBOSE);

        //** Loading Job that will fail */
        $dj_data = [
            'group' => 'DelayedJobs.Tester',
            'class' => 'DelayedJobs\Model\Table\DelayedJobsTable',
            'method' => 'tester',
            'payload' => ["success" => false],
            'priority' => 1,
            'options' => ['max_retries' => 1],
        ];
        $job_event = new Event('DelayedJob.queue', $dj_data);
        EventManager::instance()->dispatch($job_event);

        $result = $job_event->result;
        if (!$result) {
            throw new \Exception("Failed Job could not be loaded");
        }

        $job_id = $result->id;

        $this->out('<success>Loaded Failed Job: Waiting 15 seconds to check if it failed</success>', 1, Shell::VERBOSE);

        sleep(10);

        $job = $this->DelayedJobs->get($job_id);

        if ($job->status != 6) {
            throw new \Exception("Failed Job did not fail");
        }

        $this->out('<success>Test Success</success>', 1, Shell::VERBOSE);

        $this->out('<info>All Done</info>', 1, Shell::VERBOSE);

        return true;
    }


}