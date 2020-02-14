<?php

namespace DelayedJobs\Test\TestCase\Model\Table;

use Cake\Event\Event;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;
use DelayedJobs\Model\Table\DelayedJobsTable;
use Fabricate\Fabricate;

/**
 * Class DelayedJobsTableTest
 * @coversDefaultClass \DelayedJobs\Model\Table\DelayedJobsTable
 */
class DelayedJobsTableTest extends TestCase
{

    public $fixtures = [
        'plugin.DelayedJobs.DelayedJobs'
    ];

    /**
     * @var \DelayedJobs\Model\Table\DelayedJobsTable
     */
    public $DelayedJobsTable;

    public function setUp(): void
    {
        parent::setUp();
        TableRegistry::clear();
        $this->DelayedJobsTable = TableRegistry::get('DelayedJobs.DelayedJobs');
    }

    public function tearDown(): void
    {
        parent::tearDown();
        TableRegistry::clear();
    }

    /**
     * @return void
     * @covers ::beforeSave
     */
    public function testBeforeSave()
    {
        $options_array = ['test' => 1];
        $payload_array = ['test' => 2];
        $entity = Fabricate::build('DelayedJobs.DelayedJobs', function () use ($options_array, $payload_array) {
           return ['options' => $options_array, 'payload' => $payload_array];
        });

        $this->assertSame($options_array, $entity->options);
        $this->assertSame($payload_array, $entity->payload);
        $this->DelayedJobsTable->beforeSave(new Event('Test'), $entity);
        $this->assertSame(serialize($options_array), $entity->options);
        $this->assertSame(serialize($payload_array), $entity->payload);
    }

    /**
     * @return void
     * @covers ::completed
     */
    public function testCompleted()
    {
        $entity = Fabricate::create('DelayedJobs.DelayedJobs', 1, function () {
            return ['status' => 1];
        })[0];

        $this->DelayedJobsTable->completed($entity);
        $db_entity = $this->DelayedJobsTable->get($entity->id);

        $this->assertSame(DelayedJobsTable::STATUS_SUCCESS, $db_entity->status);
        $this->assertNull($db_entity->pid);
    }

    /**
     * @return void
     * @covers ::getRunningByHost
     */
    public function testGetRunningByHost() {
        Fabricate::create('DelayedJobs.DelayedJobs', 3, function ($data, $world) {
            return ['id' => $world->sequence('id'), 'status' => DelayedJobsTable::STATUS_BUSY, 'locked_by' => '1'];
        });
        Fabricate::create('DelayedJobs.DelayedJobs', 2, function ($data, $world) {
            return ['id' => $world->sequence('id', 4),'status' => DelayedJobsTable::STATUS_NEW, 'locked_by' => '1'];
        });
        Fabricate::create('DelayedJobs.DelayedJobs', 3, function ($data, $world) {
            return ['id' => $world->sequence('id', 6),'status' => DelayedJobsTable::STATUS_BUSY, 'locked_by' => '2'];
        });

        $query = $this->DelayedJobsTable->getRunningByHost(1);
        $results = $query->toArray();
        $this->assertCount(3, $results);
    }
}
