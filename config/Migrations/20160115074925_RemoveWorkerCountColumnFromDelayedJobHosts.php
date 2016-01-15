<?php
use Migrations\AbstractMigration;

class RemoveWorkerCountColumnFromDelayedJobHosts extends AbstractMigration
{
    /**
     * @return void
     */
    public function up()
    {
        $table = $this->table('delayed_job_hosts');
        $table->removeColumn('worker_count');
        $table->update();
    }

    /**
     * @return void
     */
    public function down()
    {
        $table = $this->table('delayed_job_hosts');
        $table->addColumn('worker_count', 'integer', [
            'default' => 1,
            'limit' => 11,
            'null' => false,
        ]);
        $table->update();
    }
}
