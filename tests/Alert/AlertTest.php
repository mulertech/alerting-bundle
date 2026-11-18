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

    public function testAnEmailAddressKeepsItsDomainAlone(): void
    {
        $alert = new Alert(Severity::Error, 'messenger', 'Expected response code "250" but got code "550", with message "550 5.1.1 <jean.dupont+devis@exemple-hôtel.fr>: Recipient address rejected".', [], [
            'recipient' => 'jean.dupont@gmail.com',
            'route' => 'app_contact',
        ]);

        self::assertSame('Expected response code "250" but got code "550", with message "550 5.1.1 <***@exemple-hôtel.fr>: Recipient address rejected".', $alert->summary);
        self::assertSame(['recipient' => '***@gmail.com', 'route' => 'app_contact'], $alert->context);
    }

    public function testAnAddressAcrossTheBoundIsMaskedBeforeTheCut(): void
    {
        $alert = new Alert(Severity::Error, 'log', str_repeat('a', Alert::MAX_TEXT_LENGTH - 5).' jean.dupont@gmail.com', []);

        self::assertSame(str_repeat('a', Alert::MAX_TEXT_LENGTH - 5).' ***@'.'…', $alert->summary);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedRows(): iterable
    {
        yield 'unique key' => [
            "SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint \"uniq_client_phone\"\nDETAIL:  Key (phone)=(06 12 34 56 78) already exists.",
            "SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint \"uniq_client_phone\"\nDETAIL:  Key (phone)=(***) already exists.",
        ];
        yield 'unique key over several columns, a value holding a parenthesis' => [
            'DETAIL:  Key (last_name, first_name)=(Dupont (née Martin), Jeanne) already exists.',
            'DETAIL:  Key (last_name, first_name)=(***) already exists.',
        ];
        yield 'foreign key' => [
            'ERROR:  insert or update on table "quote" violates foreign key constraint "fk_quote_client"'."\n".'DETAIL:  Key (client_id)=(42) is not present in table "client".',
            'ERROR:  insert or update on table "quote" violates foreign key constraint "fk_quote_client"'."\n".'DETAIL:  Key (client_id)=(***) is not present in table "client".',
        ];
        yield 'row still referenced' => [
            'DETAIL:  Key (id)=(42) is still referenced from table "quote".',
            'DETAIL:  Key (id)=(***) is still referenced from table "quote".',
        ];
        yield 'not-null constraint' => [
            "ERROR:  null value in column \"phone\" of relation \"client\" violates not-null constraint\nDETAIL:  Failing row contains (12, Jeanne, Dupont, null, jeanne@exemple.fr).",
            "ERROR:  null value in column \"phone\" of relation \"client\" violates not-null constraint\nDETAIL:  Failing row contains (***).",
        ];
        yield 'exclusion constraint' => [
            'DETAIL:  Key (room_id, during)=(3, [2026-07-01,2026-07-05)) conflicts with existing key (room_id, during)=(3, [2026-07-03,2026-07-04)).',
            'DETAIL:  Key (room_id, during)=(***) conflicts with existing key (room_id, during)=(***).',
        ];
        yield 'value the column type cannot read' => [
            'SQLSTATE[22P02]: Invalid text representation: 7 ERROR:  invalid input syntax for type integer: "Jeanne "la" Dupont"',
            'SQLSTATE[22P02]: Invalid text representation: 7 ERROR:  invalid input syntax for type integer: "***"',
        ];
        yield 'value outside an enum' => [
            'ERROR:  invalid input value for enum quote_status: "jeanne@exemple.fr"',
            'ERROR:  invalid input value for enum quote_status: "***"',
        ];
        yield 'MySQL value the column type cannot read' => [
            "SQLSTATE[HY000]: General error: 1366 Incorrect integer value: 'Jeanne' for column 'age' at row 1",
            "SQLSTATE[HY000]: General error: 1366 Incorrect integer value: '***' for column 'age' at row 1",
        ];
        yield 'MySQL unique key' => [
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'jeanne.dupont' for key 'user.uniq_username'",
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '***' for key 'user.uniq_username'",
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedRows')]
    public function testARefusedRowKeepsItsConstraintTableAndColumnsButNotItsValues(string $message, string $expected): void
    {
        self::assertSame($expected, new Alert(Severity::Error, 'http', 'Uncaught exception', [], ['message' => $message])->context['message']);
    }

    public function testTextWithoutAnAddressIsLeftAlone(): void
    {
        $summary = 'Package symfony/mailer@8.1 failed in src/Mailer/Sender.php:42';

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
