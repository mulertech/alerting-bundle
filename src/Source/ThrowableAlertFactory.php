<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Source;

use MulerTech\AlertingBundle\Alert\Alert;
use MulerTech\AlertingBundle\Alert\Severity;

/**
 * Turns an exception into an alert the same way for every source.
 *
 * Its identity is its class and the place it was thrown, never its message. A failing dependency
 * becomes one alert for the dependency, whatever the path and the line it was met on.
 */
final readonly class ThrowableAlertFactory
{
    public function __construct(
        private DependencyClassifier $classifier,
        private string $projectDir,
    ) {
    }

    /**
     * @param string               $what     what failed, in the words of the source: "Request GET /cart"
     * @param array<string, mixed> $context
     * @param list<string>         $identity what the source adds to tell two faults apart
     */
    public function create(string $source, Severity $severity, string $what, \Throwable $throwable, array $context = [], array $identity = []): Alert
    {
        $at = $this->relative($throwable->getFile()).':'.$throwable->getLine();
        $context += ['exception' => $throwable::class, 'at' => $at];
        $dependency = $this->classifier->classify($throwable);

        if (null !== $dependency) {
            return new Alert(
                Severity::Critical,
                'dependency',
                \sprintf('Dependency "%s" failed: %s', $dependency, $throwable->getMessage()),
                [$dependency],
                ['seen_in' => $what, ...$context],
            );
        }

        return new Alert(
            $severity,
            $source,
            \sprintf('%s: %s: %s', $what, $throwable::class, $throwable->getMessage()),
            [...$identity, $throwable::class, $this->relative($throwable->getFile()), (string) $throwable->getLine()],
            $context,
        );
    }

    /**
     * Relative to the project, so that the fingerprint survives a deployment to another directory.
     */
    private function relative(string $file): string
    {
        $prefix = rtrim($this->projectDir, '/\\').\DIRECTORY_SEPARATOR;

        return str_starts_with($file, $prefix) ? substr($file, \strlen($prefix)) : $file;
    }
}
