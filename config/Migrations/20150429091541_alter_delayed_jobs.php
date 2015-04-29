<?php
use Phinx\Migration\AbstractMigration;

class AlterDelayedJobs extends AbstractMigration
{
    /**
     * Change Method.
     *
     * More information on this method is available here:
     * http://docs.phinx.org/en/latest/migrations.html#the-change-method
     * @return void
     */
    public function up()
    {
        $table = $this->table('delayed_jobs');
        $table->changeColumn('last_message', 'text', [
            'default' => null,
            'null' => true,
        ]);
        $table->update();
    }

    /**
     * @return void
     */
    public function down()
    {
        $table = $this->table('delayed_jobs');
        $table->changeColumn(
            'last_message',
            'string',
            [
                'limit' => 512,
                'default' => null,
                'null' => true,
            ]
        );
        $table->update();
    }
}
