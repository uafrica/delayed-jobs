<?php
use Migrations\AbstractMigration;

class AddPulseColumnToDelayedJobHosts extends AbstractMigration
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
        $table = $this->table('delayed_job_hosts');
        $table->addColumn('pulse', 'datetime', [
            'default' => null,
            'null' => true,
        ]);
        $table->update();
    }
}
