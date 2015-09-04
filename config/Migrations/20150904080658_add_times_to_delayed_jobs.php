<?php
use Phinx\Migration\AbstractMigration;

class AddTimesToDelayedJobs extends AbstractMigration
{
    /**
     * Change Method.
     *
     * More information on this method is available here:
     * http://docs.phinx.org/en/latest/migrations.html#the-change-method
     * @return void
     */
    public function change()
    {
        $table = $this->table('delayed_jobs');
        $table->addColumn('start_time', 'datetime', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('end_time', 'datetime', [
            'default' => null,
            'null' => true,
        ]);
        $table->update();
    }
}
