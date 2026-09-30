<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Test\Unit\Model\Util;

use Ovebot\Chat\Model\Util\ErrorSummary;
use PHPUnit\Framework\TestCase;

class ErrorSummaryTest extends TestCase
{
    private const EMAIL = 'client@example.ro';

    /**
     * @var int line of the throw in throwError()
     */
    private $thrownAt = 0;

    /**
     * @var int line of the call to throwError()
     */
    private $calledAt = 0;

    private function throwError(?\Throwable $previous = null)
    {
        $this->thrownAt = __LINE__ + 1;
        throw new \RuntimeException("SQLSTATE[42S22]: ... WHERE customer_email = '" . self::EMAIL . "'", 42, $previous);
    }

    private function caught(?\Throwable $previous = null): \Throwable
    {
        try {
            $this->calledAt = __LINE__ + 1;
            $this->throwError($previous);
        } catch (\RuntimeException $e) {
            return $e;
        }

        self::fail('nothing was thrown');
    }

    public function testClassCodeAndPlace()
    {
        $text = (new ErrorSummary())->describe($this->caught());

        $this->assertStringStartsWith(
            'RuntimeException (code 42) thrown in ' . __FILE__ . ':' . $this->thrownAt,
            $text
        );
        $this->assertStringContainsString(
            'called from ' . self::class . '::caught() line ' . $this->calledAt,
            $text
        );
    }

    public function testNeverTheMessage()
    {
        $text = (new ErrorSummary())->describe($this->caught());

        $this->assertStringNotContainsString(self::EMAIL, $text);
        $this->assertStringNotContainsString('SQLSTATE', $text);
    }

    public function testTheCauseWithItsSqlState()
    {
        $cause = new class('driver said: ' . self::EMAIL) extends \Exception {
            /**
             * @var string PDOException keeps the SQLSTATE as a string code
             */
            protected $code = '42S22';
        };

        $text = (new ErrorSummary())->describe($this->caught($cause));

        $this->assertStringEndsWith(' (code 42S22)', $text);
        $this->assertStringContainsString('; caused by ', $text);
        $this->assertStringNotContainsString(self::EMAIL, $text);
    }

    public function testNoCodeNoCause()
    {
        $text = (new ErrorSummary())->describe(new \LogicException('x'));

        $this->assertStringStartsWith('LogicException thrown in ' . __FILE__ . ':', $text);
        $this->assertStringNotContainsString('(code', $text);
        $this->assertStringNotContainsString('caused by', $text);
    }
}
