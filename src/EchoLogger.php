<?php

namespace PHPModbus;

use Psr\Log\AbstractLogger;

/**
 * Default PSR-3 logger that writes messages to stdout.
 */
class EchoLogger extends AbstractLogger
{
    /**
     * Logs with an arbitrary level.
     *
     * @param mixed $level
     * @param string $message
     * @param array $context
     * @return void
     */
    public function log($level, $message, array $context = array())
    {
        echo date(DATE_RFC822) . ' - ' . $this->interpolate((string) $message, $context) . "\n";
    }

    /**
     * Replaces PSR-3 placeholders with context values.
     *
     * @param string $message
     * @param array $context
     * @return string
     */
    private function interpolate($message, array $context)
    {
        $replace = array();

        foreach ($context as $key => $value) {
            $replace['{' . $key . '}'] = $this->stringify($value);
        }

        return strtr($message, $replace);
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function stringify($value)
    {
        if ($value === null || is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) {
            return (string) $value;
        }

        if (is_array($value)) {
            return 'array';
        }

        if (is_object($value)) {
            return get_class($value);
        }

        if (is_resource($value)) {
            return get_resource_type($value);
        }

        return 'unknown';
    }
}
