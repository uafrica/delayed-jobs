<div class="webhookRequests view">
    <h2><?php echo __('Webhook Request'); ?></h2>
    <dl>
        <dt><?php echo __('Id'); ?></dt>
        <dd>
            <?php echo h($delayedJob->id); ?>
            &nbsp;
        </dd>
        <dt><?php echo __('Group'); ?></dt>
        <dd>
            <?php echo h($delayedJob->group); ?>
            &nbsp;
        </dd>
        <dt><?php echo __('Class'); ?></dt>
        <dd>
            <?php echo h($delayedJob->class); ?>::<?php echo h($delayedJob->method); ?>
            &nbsp;
        </dd>
        <dt><?php echo __('Status'); ?></dt>
        <dd>
            <?php echo h($delayedJob->status); ?>
            &nbsp;
        </dd>
        <dt><?php echo __('Run At'); ?></dt>
        <dd>
            <?php echo h($delayedJob->run_at); ?>
            &nbsp;
        </dd>
        <dt><?php echo __('Created'); ?></dt>
        <dd>
            <?php echo h($delayedJob->created); ?>
            &nbsp;
        </dd>
        <dt><?php echo __('Modified'); ?></dt>
        <dd>
            <?php echo h($delayedJob->modified); ?>
            &nbsp;
        </dd>
        <dt><?php echo __('Priority'); ?></dt>
        <dd>
            <?php echo h($delayedJob->priority); ?>
            &nbsp;
        </dd>
        <dt><?php echo __('Retries'); ?></dt>
        <dd>
            <?php echo h($delayedJob->retries); ?>
            &nbsp;
        </dd>
        <dt><?php echo __('Last Message'); ?></dt>
        <dd>
            <?php echo h($delayedJob->last_message); ?>
            &nbsp;
        </dd>
        <dt><?php echo __('Worker'); ?></dt>
        <dd>
            <?php echo h($delayedJob->locked_by); ?>
            &nbsp;
        </dd>
        <dt><?php echo __('Failed At'); ?></dt>
        <dd>
            <?php echo h($delayedJob->failed_at); ?>
            &nbsp;
        </dd>
        <dt><?php echo __('PID'); ?></dt>
        <dd>
            <?php echo h($delayedJob->pid); ?>
            &nbsp;
        </dd>
        <dt><?php echo __('Options'); ?></dt>
        <dd>
            <pre><?php echo json_encode(unserialize($delayedJob->options), JSON_PRETTY_PRINT); ?></pre>
            &nbsp;
        </dd>
        <dt><?php echo __('Payload'); ?></dt>
        <dd>
            <pre><?php echo json_encode(unserialize($delayedJob->payload), JSON_PRETTY_PRINT); ?></pre>
            &nbsp;
        </dd>

    </dl>
</div>
