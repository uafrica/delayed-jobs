<div class="panel panel-inverse">
    <div class="panel-body">
        <div class="table-responsive">
            <div class="alert alert-info">
                <p><?php echo $jobs_per_second; ?> jobs per second</p>
            </div>
            <table class="table">
                <thead>
                <tr>
                    <th><?php echo $this->Paginator->sort('id'); ?></th>
                    <th><?php echo $this->Paginator->sort('group'); ?></th>
                    <th><?php echo $this->Paginator->sort('method'); ?></th>
                    <th><?php echo $this->Paginator->sort('status'); ?></th>
                    <th><?php echo $this->Paginator->sort('retries'); ?></th>
                    <th><?php echo $this->Paginator->sort('priority'); ?></th>
                    <th><?php echo $this->Paginator->sort('run_at'); ?></th>
                    <th><?php echo $this->Paginator->sort('last_message'); ?></th>
                    <th><?php echo $this->Paginator->sort('created'); ?></th>
                    <th class="actions"><?php echo __('Actions'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($delayedJobs as $delayedJob): ?>

                    <?php
                    switch ($delayedJob->status) {
                        case 1:
                            $status_display = "New";
                            break;
                        case 2:
                            $status_display = "Running";
                            break;
                        case 3:
                            $status_display = "Burried";
                            break;
                        case 4:
                            $status_display = "Success";
                            break;
                        case 5:
                            $status_display = "Kick";
                            break;
                        case 6:
                            $status_display = "Failed";
                            break;
                        case 7:
                            $status_display = "Unknown";
                            break;
                        case 8:
                            $status_display = "Test Job";
                            break;
                        default:
                            $status_display = "Unknown";
                    }
                    ?>

                    <tr>
                        <td><?php echo h($delayedJob->id); ?>&nbsp;</td>
                        <td><?php echo h($delayedJob->group); ?>&nbsp;</td>
                        <td><?php echo h(
                                $delayedJob->class . "::" . $delayedJob->method
                            ); ?>&nbsp;</td>
                        <td><?php echo h($status_display); ?>&nbsp;</td>
                        <td><?php echo h($delayedJob->retries); ?>&nbsp;</td>
                        <td><?php echo h($delayedJob->priority); ?>&nbsp;</td>
                        <td><?php echo h(
                                $delayedJob->run_at
                                    ->i18nFormat([\IntlDateFormatter::MEDIUM,
                                        \IntlDateFormatter::SHORT], 'Africa/Johannesburg')
                            );?>&nbsp;</td>
                        <td><?php echo h($delayedJob->last_message); ?>&nbsp;</td>
                        <td><?php echo h($delayedJob->created); ?>&nbsp;</td>
                        <td class="actions">
                            <?php echo $this->Html->link(
                                '<i class="fa fa-info"></i>',
                                ['action' => 'view', $delayedJob->id],
                                [
                                    'title' => 'View Job',
                                    'escape' => false,
                                    'class' => 'btn btn-default btn-icon btn-circle btn-sm'
                                ]
                            ); ?>
                            <?php echo $this->Html->link(
                                '<i class="fa fa-gears"></i>',
                                ['action' => 'run', $delayedJob->id],
                                [
                                    'title' => 'Run Job',
                                    'escape' => false,
                                    'class' => 'btn btn-danger btn-icon btn-circle btn-sm'
                                ]
                            ); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php echo $this->Element('paging'); ?>


        </div>
    </div>
</div>






