<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Mail;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Yaml\Yaml;
use Webwerkwien\ContaoConshoBundle\Mail\SecretCipher;

/**
 * Builds SecretCipher the way the container does, with the argument read from
 * config/services.yaml — not typed again here, or a change there would go
 * unnoticed.
 *
 * On an installation without the variable, the env processor handed over
 * null, the constructor refused it, and because Consho's terminate
 * listeners build the mail services on every request and every console
 * command, Contao's post-update script died with it. SecretCipherTest
 * showed none of that — it always passed a string of its own.
 */
class SecretCipherContainerTest extends TestCase
{
    private const VARIABLE = 'CONSHO_SECRET_KEY';

    private string|false $saved;

    protected function setUp(): void
    {
        $this->saved = getenv(self::VARIABLE);
        putenv(self::VARIABLE);
        unset($_ENV[self::VARIABLE], $_SERVER[self::VARIABLE]);
    }

    protected function tearDown(): void
    {
        if (false !== $this->saved) {
            putenv(self::VARIABLE.'='.$this->saved);
        }
    }

    /**
     * N2: Without CONSHO_SECRET_KEY from APP_SECRET (kernel.secret) — the path
     * a shop freshly installed through the Contao Manager takes.
     */
    public function testWithoutTheVariableTheKeyComesFromTheKernelSecret(): void
    {
        $cipher = $this->cipher('kernel-secret-of-this-installation');

        self::assertSame('hunter2', $cipher->decrypt($cipher->encrypt('hunter2')));
    }

    public function testWithoutTheVariableAndWithoutASecretItStillBuilds(): void
    {
        $cipher = $this->cipher('');

        self::assertSame('', $cipher->encrypt(''), 'nothing to protect, nothing refused');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(self::VARIABLE);
        $cipher->encrypt('hunter2');
    }

    public function testWithTheVariableItEncrypts(): void
    {
        putenv(self::VARIABLE.'='.base64_encode(str_repeat('k', 32)));

        $cipher = $this->cipher();

        self::assertSame('hunter2', $cipher->decrypt($cipher->encrypt('hunter2')));
    }

    private function cipher(string $kernelSecret = 'kernel-secret'): SecretCipher
    {
        /** @var array{services: array<string, array{arguments: array{'$key': string, '$fallbackSecret': string}}>} $config */
        $config = Yaml::parseFile(\dirname(__DIR__, 2).'/config/services.yaml');
        $arguments = $config['services'][SecretCipher::class]['arguments'];

        self::assertStringContainsString(self::VARIABLE, $arguments['$key'], 'the definition this test is about');

        $container = new ContainerBuilder();
        $container->setParameter('kernel.secret', $kernelSecret);
        $container->register(SecretCipher::class, SecretCipher::class)
            ->setArgument('$key', $arguments['$key'])
            ->setArgument('$fallbackSecret', $arguments['$fallbackSecret'])
            ->setPublic(true);
        $container->compile(true);

        $cipher = $container->get(SecretCipher::class);
        self::assertInstanceOf(SecretCipher::class, $cipher);

        return $cipher;
    }
}
