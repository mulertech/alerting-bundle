<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Tests\Source;

use MulerTech\AlertingBundle\Alert\Severity;
use MulerTech\AlertingBundle\Source\DependencyClassifier;
use MulerTech\AlertingBundle\Source\ThrowableAlertFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportException;

final class ThrowableAlertFactoryTest extends TestCase
{
    public function testTheAlertSaysWhatFailedAndWhere(): void
    {
        $failure = $this->thrownAt('/srv/app/src/Cart.php', 42, new \RuntimeException('Cart 12 not found'));

        $alert = $this->factory()->create('http', Severity::Critical, 'Request GET /cart answered 500', $failure, ['status' => 500]);

        self::assertSame(Severity::Critical, $alert->severity);
        self::assertSame('http', $alert->source);
        self::assertSame('Request GET /cart answered 500: RuntimeException: Cart 12 not found', $alert->summary);
        self::assertSame(['status' => '500', 'exception' => 'RuntimeException', 'at' => 'src/Cart.php:42'], $alert->context);
    }

    public function testTheMessageIsNoPartOfTheIdentity(): void
    {
        $factory = $this->factory();
        $first = $factory->create('http', Severity::Error, 'Request', $this->thrownAt('/srv/app/src/Cart.php', 42, new \RuntimeException('Cart 12 not found')));
        $second = $factory->create('http', Severity::Error, 'Request', $this->thrownAt('/srv/app/src/Cart.php', 42, new \RuntimeException('Cart 13 not found')));

        self::assertSame($first->fingerprint, $second->fingerprint);
    }

    public function testThePlaceAndTheSourceIdentityAreParts(): void
    {
        $factory = $this->factory();
        $reference = $factory->create('http', Severity::Error, 'Request', $this->thrownAt('/srv/app/src/Cart.php', 42, new \RuntimeException('Failure')), identity: ['500']);
        $otherLine = $factory->create('http', Severity::Error, 'Request', $this->thrownAt('/srv/app/src/Cart.php', 43, new \RuntimeException('Failure')), identity: ['500']);
        $otherStatus = $factory->create('http', Severity::Error, 'Request', $this->thrownAt('/srv/app/src/Cart.php', 42, new \RuntimeException('Failure')), identity: ['503']);

        self::assertNotSame($reference->fingerprint, $otherLine->fingerprint);
        self::assertNotSame($reference->fingerprint, $otherStatus->fingerprint);
    }

    public function testTheFingerprintSurvivesADeploymentToAnotherDirectory(): void
    {
        $here = new ThrowableAlertFactory(new DependencyClassifier(), '/srv/app');
        $there = new ThrowableAlertFactory(new DependencyClassifier(), '/srv/release-2/');

        self::assertSame(
            $here->create('http', Severity::Error, 'Request', $this->thrownAt('/srv/app/src/Cart.php', 42, new \RuntimeException('Failure')))->fingerprint,
            $there->create('http', Severity::Error, 'Request', $this->thrownAt('/srv/release-2/src/Cart.php', 42, new \RuntimeException('Failure')))->fingerprint,
        );
    }

    public function testAFileOutsideTheProjectKeepsItsFullPath(): void
    {
        $alert = $this->factory()->create('http', Severity::Error, 'Request', $this->thrownAt('/usr/share/php/Lib.php', 7, new \RuntimeException('Failure')));

        self::assertSame('/usr/share/php/Lib.php:7', $alert->context['at']);
    }

    public function testAFailingDependencyMakesOneAlertWhereverItIsMet(): void
    {
        $factory = $this->factory();
        $inCart = $factory->create('http', Severity::Error, 'Request GET /cart answered 500', $this->thrownAt('/srv/app/src/Cart.php', 42, new TransportException('Connection refused')));
        $inImport = $factory->create('messenger', Severity::Error, 'Message App\Import abandoned', $this->thrownAt('/srv/app/src/Import.php', 9, new TransportException('Connection timed out')));

        self::assertSame(Severity::Critical, $inCart->severity);
        self::assertSame('dependency', $inCart->source);
        self::assertSame('Dependency "mail transport" failed: Connection refused', $inCart->summary);
        self::assertSame('Request GET /cart answered 500', $inCart->context['seen_in']);
        self::assertSame($inCart->fingerprint, $inImport->fingerprint);
    }

    private function factory(): ThrowableAlertFactory
    {
        return new ThrowableAlertFactory(new DependencyClassifier(), '/srv/app');
    }

    /**
     * @template T of \Throwable
     *
     * @param T $throwable
     *
     * @return T
     */
    private function thrownAt(string $file, int $line, \Throwable $throwable): \Throwable
    {
        $class = new \ReflectionClass($throwable instanceof \Exception ? \Exception::class : \Error::class);
        $class->getProperty('file')->setValue($throwable, $file);
        $class->getProperty('line')->setValue($throwable, $line);

        return $throwable;
    }
}
