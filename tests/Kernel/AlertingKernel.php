<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Tests\Kernel;

use MulerTech\AlertingBundle\MulerTechAlertingBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * An application of its own for each configuration under test, with the framework, Monolog and the
 * bundle, and a mail and a Notifier transport that send nothing.
 *
 * It fails on demand: `/failure` throws, `/unavailable` answers 503 with no exception, `app:fail`
 * throws, and every `\ArrayObject` message fails in its handler.
 */
final class AlertingKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * A directory of its own: two cases sharing a compiled container would test the first one twice.
     */
    private readonly string $directory;

    /**
     * @param array<string, mixed>                       $alerting configuration of mulertech_alerting
     * @param array<string, mixed>                       $framework merged over the framework configuration of the test
     * @param list<\Closure(ContainerBuilder): void>     $services  extra services of the application
     */
    public function __construct(
        private readonly array $alerting,
        private readonly array $framework = [],
        private readonly array $services = [],
        private readonly bool $monolog = true,
    ) {
        parent::__construct('prod', false);
        $this->directory = sys_get_temp_dir().'/mulertech_alerting/'.bin2hex(random_bytes(8));
    }

    public function removeDirectory(): void
    {
        new Filesystem()->remove($this->directory);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();

        if ($this->monolog) {
            yield new MonologBundle();
        }

        yield new MulerTechAlertingBundle();
    }

    public function getProjectDir(): string
    {
        return \dirname(__DIR__, 2);
    }

    public function getCacheDir(): string
    {
        return $this->directory.'/cache';
    }

    public function getLogDir(): string
    {
        return $this->directory.'/log';
    }

    /**
     * The standard error channel writes to a file of the kernel, not to the output of the suite.
     */
    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new class($this->directory.'/stderr') implements CompilerPassInterface {
            public function __construct(private readonly string $path)
            {
            }

            public function process(ContainerBuilder $container): void
            {
                $container->getDefinition('mulertech_alerting.standard_error')->setArguments([$this->path]);
            }
        });
    }

    public function failure(): never
    {
        throw new \RuntimeException('The basket could not be priced');
    }

    public function unavailable(): Response
    {
        return new Response('', Response::HTTP_SERVICE_UNAVAILABLE);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('failure', '/failure')->controller([$this, 'failure']);
        $routes->add('unavailable', '/unavailable')->controller([$this, 'unavailable']);
    }

    protected function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        $container->extension('framework', array_replace_recursive([
            'secret' => 'test',
            'test' => true,
            'http_method_override' => false,
            'mailer' => ['dsn' => 'null://null'],
            'notifier' => ['chatter_transports' => [], 'texter_transports' => []],
            'messenger' => [
                'failure_transport' => 'failed',
                'transports' => ['async' => 'in-memory://', 'failed' => 'in-memory://'],
            ],
            'scheduler' => ['enabled' => true],
            'cache' => ['pools' => ['cache.alerting' => ['adapter' => 'cache.adapter.filesystem']]],
        ], $this->framework));

        if ($this->monolog) {
            $container->extension('monolog', ['handlers' => ['main' => ['type' => 'null']]]);
        }

        $container->extension('mulertech_alerting', $this->alerting);

        $builder->register('app.failing_command', FailingCommand::class)->addTag('console.command', ['command' => 'app:fail']);
        $builder->register('app.failing_handler', FailingHandler::class)->addTag('messenger.message_handler', ['handles' => \ArrayObject::class]);

        foreach ($this->services as $register) {
            $register($builder);
        }
    }
}
