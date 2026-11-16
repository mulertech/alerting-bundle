<?php

declare(strict_types=1);

namespace MulerTech\AlertingBundle\Tests\Alert;

use MulerTech\AlertingBundle\Alert\Alert;
use MulerTech\AlertingBundle\Alert\Severity;
use PHPUnit\Framework\TestCase;

final class AlertTest extends TestCase
{
    public function testTheFingerprintIgnoresTheSummaryAndTheContext(): void
    {
        $first = new Alert(Severity::Error, 'http', 'User 123 not found', ['App\NotFound', 'src/A.php', '12'], ['id' => 123]);
        $second = new Alert(Severity::Critical, 'http', 'User 124 not found', ['App\NotFound', 'src/A.php', '12'], ['id' => 124]);

        self::assertSame($first->fingerprint, $second->fingerprint);
    }

    public function testTheFingerprintFollowsTheIdentity(): void
    {
        $first = new Alert(Severity::Error, 'http', 'Failure', ['App\Failure', 'src/A.php', '12']);
        $second = new Alert(Severity::Error, 'http', 'Failure', ['App\Failure', 'src/A.php', '13']);

        self::assertNotSame($first->fingerprint, $second->fingerprint);
    }

    public function testTheFingerprintFollowsTheSource(): void
    {
        $http = new Alert(Severity::Error, 'http', 'Failure', ['App\Failure']);
        $messenger = new Alert(Severity::Error, 'messenger', 'Failure', ['App\Failure']);

        self::assertNotSame($http->fingerprint, $messenger->fingerprint);
    }

    public function testIdentityPartsDoNotRunIntoEachOther(): void
    {
        $first = new Alert(Severity::Error, 'console', 'Failure', ['app:import', '12']);
        $second = new Alert(Severity::Error, 'console', 'Failure', ['app:import1', '2']);

        self::assertNotSame($first->fingerprint, $second->fingerprint);
    }

    public function testALongSummaryIsCutAndSaysSo(): void
    {
        $alert = new Alert(Severity::Error, 'log', str_repeat('é', Alert::MAX_TEXT_LENGTH + 10), []);

        self::assertSame(str_repeat('é', Alert::MAX_TEXT_LENGTH).'…', $alert->summary);
    }

    public function testASummaryWithinTheBoundIsKeptWhole(): void
    {
        $summary = str_repeat('a', Alert::MAX_TEXT_LENGTH);

        self::assertSame($summary, new Alert(Severity::Error, 'log', $summary, [])->summary);
    }

    public function testTheContextIsReducedToBoundedStrings(): void
    {
        $alert = new Alert(Severity::Error, 'log', 'Failure', [], [
            'null' => null,
            'yes' => true,
            'no' => false,
            'count' => 3,
            'ratio' => 1.5,
            'route' => 'app_home',
            'user' => new \stdClass(),
            'list' => [1, 2],
            'query' => str_repeat('x', Alert::MAX_TEXT_LENGTH + 1),
        ]);

        self::assertSame([
            'null' => 'null',
            'yes' => 'true',
            'no' => 'false',
            'count' => '3',
            'ratio' => '1.5',
            'route' => 'app_home',
            'user' => 'stdClass',
            'list' => 'array',
            'query' => str_repeat('x', Alert::MAX_TEXT_LENGTH).'…',
        ], $alert->context);
    }
}
