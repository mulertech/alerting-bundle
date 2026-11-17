<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Source;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Which path owns each exception, so that a fault is reported once.
 *
 * Symfony logs at `critical` what a dedicated source already reports (a 5xx, an abandoned message,
 * a failed command). Each source claims the exception before Symfony logs it, and the log handler
 * leaves every claimed exception alone, including those a source decides not to report (a 404).
 * The first path to see an exception owns it: a fatal error is logged before the kernel turns it
 * into a response, so the log handler claims it and the HTTP source steps aside.
 *
 * A wrapper counts as claimed when an exception it wraps is: Symfony wraps an exception mapped to
 * a status code in an `HttpException` before logging it.
 */
final class ThrowableClaims implements ResetInterface
{
    /**
     * @var \WeakMap<\Throwable, string>
     */
    private \WeakMap $owners;

    public function __construct()
    {
        $this->owners = new \WeakMap();
    }

    /**
     * Claims the exception for this owner, and says whether it was free.
     */
    public function claim(\Throwable $throwable, string $owner): bool
    {
        if (null !== $this->ownerOf($throwable)) {
            return false;
        }

        $this->owners[$throwable] = $owner;

        return true;
    }

    public function ownerOf(\Throwable $throwable): ?string
    {
        for ($link = $throwable; null !== $link; $link = $link->getPrevious()) {
            if (isset($this->owners[$link])) {
                return $this->owners[$link];
            }
        }

        return null;
    }

    public function reset(): void
    {
        $this->owners = new \WeakMap();
    }
}
