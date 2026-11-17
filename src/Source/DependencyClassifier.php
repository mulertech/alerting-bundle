<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Source;

/**
 * Recognises, anywhere in the chain of an exception, a dependency of the application that failed.
 *
 * Every request hitting a fallen database fails with its own exception at its own line: reported
 * as such, an outage would make one alert per call site. Named after the dependency, it makes one.
 *
 * The bundle knows the dependencies any Symfony application may have, each by a class it matches
 * only when that class exists. The application declares its own.
 */
final readonly class DependencyClassifier
{
    private const array KNOWN = [
        'Doctrine\DBAL\Exception\ConnectionException' => 'database',
        'Doctrine\DBAL\Exception\ConnectionLost' => 'database',
        'Symfony\Component\Mailer\Exception\TransportExceptionInterface' => 'mail transport',
        'RedisException' => 'Redis',
        'RedisClusterException' => 'Redis',
        'Predis\Connection\ConnectionException' => 'Redis',
    ];

    /**
     * @param array<string, string> $declared exception class or interface => name of the dependency
     */
    public function __construct(
        private array $declared = [],
    ) {
    }

    public function classify(\Throwable $throwable): ?string
    {
        $dependencies = $this->declared + self::KNOWN;

        for ($link = $throwable; null !== $link; $link = $link->getPrevious()) {
            foreach ($dependencies as $class => $name) {
                if ($link instanceof $class) {
                    return $name;
                }
            }
        }

        return null;
    }
}
