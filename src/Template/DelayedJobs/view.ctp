<div class="webhookRequests view">
    <h2><?php echo __('Webhook Request'); ?></h2>
    <dl>
        <dt><?php echo __('Id'); ?></dt>
        <dd>
            <?php echo h($DelayedJob['DelayedJob']['id']); ?>
            &nbsp;
        </dd>
        <dt><?php echo __('Group'); ?></dt>
        <dd>
            <?php echo h($DelayedJob['DelayedJob']['group']); ?>
            &nbsp;
        </dd>
        <dt><?php echo __('Class'); ?></dt>
        <dd>
            <?php echo h($DelayedJob['DelayedJob']['class']); ?>::<?php echo h($DelayedJob['DelayedJob']['method']); ?>
            &nbsp;
        </dd>
        <dt><?php echo __('Status'); ?></dt>
        <dd>
            <?php echo h($DelayedJob['DelayedJob']['status']); ?>
            &nbsp;
        </dd>
        <dt><?php echo __('Run At'); ?></dt>
        <dd>
            <?php echo h($DelayedJob['DelayedJob']['run_at']); ?>
            &nbsp;
        </dd>
        <dt><?php echo __('Created'); ?></dt>
        <dd>
            <?php echo h($DelayedJob['DelayedJob']['created']); ?>
            &nbsp;
        </dd>
        <dt><?php echo __('Modified'); ?></dt>
        <dd>
            <?php echo h($DelayedJob['DelayedJob']['modified']); ?>
            &nbsp;
        </dd>
        <dt><?php echo __('Priority'); ?></dt>
        <dd>
            <?php echo h($DelayedJob['DelayedJob']['priority']); ?>
            &nbsp;
        </dd>
        <dt><?php echo __('Retries'); ?></dt>
        <dd>
            <?php echo h($DelayedJob['DelayedJob']['retries']); ?>
            &nbsp;
        </dd>
        <dt><?php echo __('Last Message'); ?></dt>
        <dd>
            <?php echo h($DelayedJob['DelayedJob']['last_message']); ?>
            &nbsp;
        </dd>
        <dt><?php echo __('Worker'); ?></dt>
        <dd>
            <?php echo h($DelayedJob['DelayedJob']['locked_by']); ?>
            &nbsp;
        </dd>
        <dt><?php echo __('Failed At'); ?></dt>
        <dd>
            <?php echo h($DelayedJob['DelayedJob']['failed_at']); ?>
            &nbsp;
        </dd>
        <dt><?php echo __('PID'); ?></dt>
        <dd>
            <?php echo h($DelayedJob['DelayedJob']['pid']); ?>
            &nbsp;
        </dd>
        <dt><?php echo __('Options'); ?></dt>
        <dd>
            <?php echo json_encode(unserialize($DelayedJob['DelayedJob']['options'])); ?>
            &nbsp;
        </dd>
        <dt><?php echo __('Payload'); ?></dt>
        <dd>
            <?php echo json_encode(unserialize($DelayedJob['DelayedJob']['payload'])); ?>
            &nbsp;
        </dd>

    </dl>
</div>
