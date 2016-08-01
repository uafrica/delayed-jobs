<?php
use Migrations\AbstractMigration;

class DelayedJobDbOptimisations extends AbstractMigration
{
    /**
     * Change Method.
     *
     * More information on this method is available here:
     * http://docs.phinx.org/en/latest/migrations.html#the-change-method
     *
     * @return void
     */
    public function up()
    {
        $table = $this->table('delayed_jobs');
        $table->changeColumn('status', 'integer', [
            'default' => null,
            'limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_TINY,
            'null' => false,
        ])
            ->changeColumn('retries', 'integer', [
                'default' => 0,
                'limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_TINY,
                'null' => false,
            ])
            ->changeColumn('priority', 'integer', [
                'default' => 1,
                'limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_SMALL,
                'null' => false,
            ])
            ->changeColumn('sequence', 'string', [
                'default' => null,
                'limit' => 100,
                'null' => true,
            ]);

        if ($table->hasIndex(['status'])) {
            $table->removeIndex(['status']);
        }
        if ($table->hasIndex(['sequence'])) {
            $table->removeIndex(['sequence']);
        }
        if ($table->hasIndex(['priority', 'id'])) {
            $table->removeIndex(['priority', 'id']);
        }
        if ($table->hasIndex(['worker', 'status'])) {
            $table->removeIndex(['worker', 'status']);
        }
        if (!$table->hasIndex(['priority'])) {
            $table->addIndex(['priority']);
        }
        if (!$table->hasIndex(['status', 'modified'])) {
            $table->addIndex(['status', 'modified'], [
                'name' => 'status_modified',
            ]);
        }
        $table->save();
    }

    /**
     * Change Method.
     *
     * More information on this method is available here:
     * http://docs.phinx.org/en/latest/migrations.html#the-change-method
     *
     * @return void
     */
    public function down()
    {
        $table = $this->table('delayed_jobs');
        $table->changeColumn('status', 'integer', [
            'default' => null,
            'limit' => 10,
            'null' => false,
        ])
            ->changeColumn('retries', 'integer', [
                'default' => 0,
                'limit' => 10,
                'null' => false,
            ])
            ->changeColumn('priority', 'integer', [
                'default' => 1,
                'limit' => 10,
                'null' => false,
            ])
            ->changeColumn('sequence', 'string', [
                'default' => null,
                'limit' => 200,
                'null' => true,
            ]);

        if (!$table->hasIndex(['status'])) {
            $table->addIndex(['status']);
        }
        if (!$table->hasIndex(['sequence'])) {
            $table->addIndex(['sequence']);
        }
        if (!$table->hasIndex(['priority', 'id'])) {
            $table->addIndex(['priority', 'id']);
        }
        if (!$table->hasIndex(['worker', 'status'])) {
            $table->addIndex(['worker', 'status'], [
                'name' => 'worker_status',
            ]);
        }
        if ($table->hasIndex(['priority'])) {
            $table->removeIndex(['priority']);
        }
        if ($table->hasIndex(['status', 'modified'])) {
            $table->removeIndex(['status', 'modified']);
        }

        $table->save();
    }
}
