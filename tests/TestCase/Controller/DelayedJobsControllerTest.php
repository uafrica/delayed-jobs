<?php
namespace DelayedJobs\Test\TestCase\Controller;

use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestCase;
use DelayedJobs\Model\Table\DelayedJobsTable;
use Fabricate\Fabricate;

/**
 * Class DelayedJobsControllerTest
 * @coversDefaultClass \DelayedJobs\Controller\DelayedJobsController
 */
class DelayedJobsControllerTest extends IntegrationTestCase
{

    public $fixtures = ['plugin.DelayedJobs.DelayedJobs'];

    public function tearDown() {
        parent::tearDown();
        TableRegistry::clear();
    }

    /**
     * @return void
     * @covers ::index
     */
    public function testIndex() {
        $count = 10;
        $jobs = Fabricate::create('DelayedJobs.DelayedJobs', $count);

        $this->get('/delayed_jobs');
        $this->assertResponseOk();
        $this->assertResponseContains(h($jobs[0]->method));
        $this->assertResponseContains(round($count / 60 / 60, 3) . ' jobs per second');
    }

    /**
     * @return void
     * @covers ::view
     */
    public function testView()
    {
        $jobs = Fabricate::create('DelayedJobs.DelayedJobs', 1);

        $this->get('/delayed_jobs/view/' . $jobs[0]->id);
        $this->assertResponseOk();
        $this->assertResponseContains(h($jobs[0]->method));
    }

    /**
     * @return void
     * @covers ::run
     */
    public function testRunCompletedJob()
    {
        $jobs = Fabricate::create('DelayedJobs.DelayedJobs', 1, function () {
            return ['status' => DelayedJobsTable::STATUS_SUCCESS];
        });

        $this->get('/delayed_jobs/run/' . $jobs[0]->id);
        $this->assertSession('Job Already Completed', 'Flash.flash.message');
        $this->assertResponseSuccess();
        $this->assertRedirect(['action' => 'index']);
    }

    /**
     * @return void
     * @covers ::run
     */
    public function testRunExecuteJob()
    {
        $table = $this->getModel('\\DelayedJobs\\Model\\Table\\DelayedJobsTable', ['get'], 'DelayedJobs', 'delayed_jobs');
        TableRegistry::getTableLocator()->set('DelayedJobs.DelayedJobs', $table);

        $job_data = Fabricate::attributes_for('DelayedJobs.DelayedJobs')[0];
        $job = $this->getMock('\\DelayedJobs\\Model\\Entity\\DelayedJob', ['execute'], [$job_data]);

        $job_output = 'Test completed';

        $table
            ->expects($this->once())
            ->method('get')
            ->willReturn($job);

        $job
            ->expects($this->once())
            ->method('execute')
            ->willReturn($job_output);

        $this->get('/delayed_jobs/run/' . $job->id);
        $this->assertSession('Job Completed: ' . $job_output, 'Flash.flash.message');
        $this->assertResponseSuccess();
        $this->assertRedirect(['action' => 'index']);
    }
}
