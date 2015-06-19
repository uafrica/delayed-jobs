<?php
use Phinx\Migration\AbstractMigration;

class AlterDelayedJobsAddSequence extends AbstractMigration
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
        $table->addColumn('sequence', 'string', [
            'default' => null,
            'limit' => 200,
            'null' => true,
        ]);
        $table->addIndex('sequence');
        $table->update();
    }
}
