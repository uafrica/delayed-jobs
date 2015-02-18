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
                        switch ($delayedJob['DelayedJob']['status'])
                        {
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
                            <td><?php echo h($delayedJob['DelayedJob']['id']); ?>&nbsp;</td>
                            <td><?php echo h($delayedJob['DelayedJob']['group']); ?>&nbsp;</td>
                            <td><?php echo h($delayedJob['DelayedJob']['class'] . "::" . $delayedJob['DelayedJob']['method']); ?>&nbsp;</td>
                            <td><?php echo h($status_display); ?>&nbsp;</td>
                            <td><?php echo h($delayedJob['DelayedJob']['retries']); ?>&nbsp;</td>
                            <td><?php echo h($delayedJob['DelayedJob']['priority']); ?>&nbsp;</td>
                            <td><?php echo h($this->Time->format($this->Time->convert(strtotime($delayedJob['DelayedJob']['run_at']), 'Africa/Johannesburg'), '%e %b %Y %H:%M')); ?>&nbsp;</td>
                            <td><?php echo h($delayedJob['DelayedJob']['last_message']); ?>&nbsp;</td>
                            <td><?php echo h($delayedJob['DelayedJob']['created']); ?>&nbsp;</td>
                            <td class="actions">
                                <?php echo $this->Html->link('<i class="fa fa-info"></i>', array('action' => 'view', $delayedJob['DelayedJob']['id']), array('title'=>'View Job','escape' => false, 'class' => 'btn btn-default btn-icon btn-circle btn-sm')); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php echo $this->Element('paging'); ?>



        </div>
    </div>
</div>






