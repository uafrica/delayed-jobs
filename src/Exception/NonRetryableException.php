<?php
declare(strict_types=1);

namespace DelayedJobs\Exception;

use Exception;

/**
 * Special exception case for delayed job failures that should not be retried
 */

class NonRetryableException extends Exception
{
}
