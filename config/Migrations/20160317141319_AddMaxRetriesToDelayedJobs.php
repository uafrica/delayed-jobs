<?php
use Migrations\AbstractMigration;

class AddMaxRetriesToDelayedJobs extends AbstractMigration
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
        $table->addColumn('max_retries', 'integer', [
            'default' => null,
            'limit' => 11,
            'null' => false,
        ]);
        $table->update();

        if ($table->hasColumn('max_retries')) {
            $rows = $this->query("SELECT * FROM delayed_jobs WHERE status = {DELAYEDJ}")
        }
    }
}
