<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Ovebot\Chat\Test\Unit\Controller\Preview;

use Magento\Framework\App\Action\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Ovebot\Chat\Controller\Preview\Validate;
use Ovebot\Chat\Model\Security\PreviewToken;
use PHPUnit\Framework\TestCase;

class ValidateTest extends TestCase
{
    private const TOKEN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

    /**
     * @var array
     */
    private $answer = [];

    /**
     * @var string[] tokens given to PreviewToken::isValid()
     */
    private $checked = [];

    protected function setUp(): void
    {
        $this->answer = ['headers' => [], 'data' => null];
        $this->checked = [];
    }

    /**
     * @param mixed $token value of the "token" parameter
     * @return array the answer
     */
    private function ask($token): array
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(function ($name) use ($token) {
            return $name === 'token' ? $token : null;
        });
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);

        $previewToken = $this->createMock(PreviewToken::class);
        $previewToken->method('isValid')->willReturnCallback(function ($given) {
            $this->checked[] = $given;

            return $given === self::TOKEN;
        });

        $result = $this->createMock(Json::class);
        $result->method('setHeader')->willReturnCallback(function ($name, $value) use ($result) {
            $this->answer['headers'][$name] = $value;

            return $result;
        });
        $result->method('setData')->willReturnCallback(function ($data) use ($result) {
            $this->answer['data'] = $data;

            return $result;
        });
        $factory = $this->getMockBuilder(JsonFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $factory->method('create')->willReturn($result);

        (new Validate($context, $previewToken, $factory))->execute();

        return $this->answer;
    }

    public function testTheTokenOfTheShopIsValid()
    {
        $answer = $this->ask(self::TOKEN);

        $this->assertSame(['valid' => true], $answer['data']);
        $this->assertSame([self::TOKEN], $this->checked);
    }

    public function testAnotherTokenIsNot()
    {
        $this->assertSame(['valid' => false], $this->ask(str_repeat('0', 32))['data']);
    }

    public function testATokenThatIsNotTextIsNotEvenChecked()
    {
        $this->assertSame(['valid' => false], $this->ask(null)['data']);
        $this->assertSame(['valid' => false], $this->ask([self::TOKEN])['data']);
        $this->assertSame([], $this->checked);
    }

    public function testTheAnswerIsNeverCached()
    {
        $headers = $this->ask(self::TOKEN)['headers'];

        $this->assertStringContainsString('no-store', $headers['Cache-Control']);
        $this->assertSame('no-cache', $headers['Pragma']);
        $this->assertStringStartsWith('application/json', $headers['Content-Type']);
    }
}
