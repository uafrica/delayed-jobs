<?php

namespace DelayedJobs\Exception;

/**
 * Special exception case for delayed job failures that should not be retried
 */
class NonRetryableException extends \Exception
{

}
