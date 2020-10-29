<?php

namespace DelayedJobs\Test\TestCase\DelayedJob;

use Cake\TestSuite\TestCase;
use DelayedJobs\DelayedJob\Job;

/**
 * Class JobTest
 */
class JobTest extends TestCase
{
    public function testConstruct()
    {
        $newJob = new Job([
            'id' => 1,
            'payload' => [
                'test' => 'test'
            ]
        ]);

        $this->assertSame(1, $newJob->getId());
        $this->assertEquals(['test' => 'test'], $newJob->getPayload());
    }
}
