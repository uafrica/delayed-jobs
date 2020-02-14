<?php
declare(strict_types=1);

namespace DelayedJobs\Database\Type;

use Cake\Database\Driver;
use Cake\Database\TypeFactory;
use Cake\Log\Log;
use PDO;

/**
 * Class JsonType
 */
class SerializeType extends TypeFactory
{
    /**
     * Casts given value from a database type to PHP equivalent
     *
     * @param mixed $value value to be converted to PHP equivalent
     * @param \Cake\Database\Driver $driver object from which database preferences and configuration will be extracted
     * @return mixed
     */
    public function toPHP($value, Driver $driver)
    {
        if (!is_string($value)) {
            return null;
        }

        $unserialized = unserialize($value);
        if ($unserialized === false) {
            Log::error(__('Could not unserialize payload:'));
            Log::error($value);
            $unserialized = [];
        }

        return $unserialized;
    }

    /**
     * Marshalls flat data into PHP objects.
     *
     * Most useful for converting request data into PHP objects
     * that make sense for the rest of the ORM/Database layers.
     *
     * @param mixed $value The value to convert.
     * @return mixed Converted value.
     */
    public function marshal($value)
    {
        if (!is_string($value)) {
            return $value;
        }

        return unserialize($value);
    }

    /**
     * Casts given value from a PHP type to one acceptable by database
     *
     * @param mixed $value value to be converted to database equivalent
     * @param \Cake\Database\Driver $driver object from which database preferences and configuration will be extracted
     * @return mixed
     */
    public function toDatabase($value, Driver $driver)
    {
        return serialize($value);
    }

    /**
     * Casts give value to Statement equivalent
     *
     * @param mixed $value value to be converted to PHP equivalent
     * @param \Cake\Database\Driver $driver object from which database preferences and configuration will be extracted
     * @return mixed
     */
    public function toStatement($value, Driver $driver)
    {
        if ($value === null) {
            return PDO::PARAM_NULL;
        }

        return PDO::PARAM_STR;
    }
}
