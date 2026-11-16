<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Tests;

use MulerTech\AlertingBundle\MulerTechAlertingBundle;
use PHPUnit\Framework\TestCase;

final class MulerTechAlertingBundleTest extends TestCase
{
    public function testItsConfigurationLivesUnderTheMulertechAlertingKey(): void
    {
        $extension = new MulerTechAlertingBundle()->getContainerExtension();

        self::assertNotNull($extension);
        self::assertSame('mulertech_alerting', $extension->getAlias());
    }
}
