<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Model\Util;

/**
 * Describes an error for the log without its message: class, code, where it was thrown and the place in this
 * module the call came from.
 *
 * Used where the message may hold what a customer sent: the message of a database error repeats the query, with
 * the email or the phone of the request in it. The arguments of the calls are never read.
 */
class ErrorSummary
{
    private const MODULE_NAMESPACE = 'Ovebot\\Chat\\';

    /**
     * "RuntimeException (code 42) thrown in /path/File.php:12, called from Ovebot\Chat\..\Class::method() line 34"
     *
     * @param \Throwable $error
     * @return string
     */
    public function describe(\Throwable $error): string
    {
        $text = $this->name($error) . ' thrown in ' . $error->getFile() . ':' . $error->getLine();

        $origin = $this->origin($error);
        if ($origin !== '') {
            $text .= ', called from ' . $origin;
        }

        $previous = $error->getPrevious();
        if ($previous !== null) {
            $text .= '; caused by ' . $this->name($previous);
        }

        return $text;
    }

    /**
     * Class and code of an error; database errors carry the SQLSTATE as code
     *
     * @param \Throwable $error
     * @return string
     */
    private function name(\Throwable $error): string
    {
        $code = (string) $error->getCode();

        return get_class($error) . ($code !== '' && $code !== '0' ? ' (code ' . $code . ')' : '');
    }

    /**
     * The deepest call made from this module: class, method and line
     *
     * A frame of the trace names the function called and the line it was called from; that line belongs to the
     * function of the next frame.
     *
     * @param \Throwable $error
     * @return string empty when the call did not pass through this module
     */
    private function origin(\Throwable $error): string
    {
        $trace = $error->getTrace();
        foreach ($trace as $index => $frame) {
            $caller = isset($trace[$index + 1]) ? $trace[$index + 1] : null;
            if ($caller !== null && isset($caller['class'], $caller['function'], $frame['line'])
                && strpos($caller['class'], self::MODULE_NAMESPACE) === 0
            ) {
                return $caller['class'] . '::' . $caller['function'] . '() line ' . $frame['line'];
            }
        }

        return '';
    }
}
