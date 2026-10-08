<?php

declare(strict_types=1);

use App\Actions\Numbering\DocumentNumbers;
use App\Domain\Numbering\DocumentType;
use App\Models\Branch;
use App\Models\DocumentSeries;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * R8: numbers are issued inside the document's own transaction, gap-free and
 * never duplicated.
 */

function issueNumber(Organization $organization, ?string $branchId = null, DocumentType $type = DocumentType::WorkOrder, string $at = '2026-10-08T02:00:00Z'): string
{
    return asSystem(fn () => DB::transaction(
        fn () => app(DocumentNumbers::class)->issue($organization->id, $branchId, $type, new CarbonImmutable($at))->formatted,
    ));
}

it('issues consecutive numbers from 1 in each series', function () {
    $organization = Organization::factory()->create();

    expect(issueNumber($organization))->toBe('WO-2026-0001')
        ->and(issueNumber($organization))->toBe('WO-2026-0002')
        ->and(issueNumber($organization, type: DocumentType::Invoice))->toBe('INV-2026-0001');
});

it('keeps separate series per branch and per Manila year', function () {
    $organization = Organization::factory()->create();
    $branch = asSystem(fn () => Branch::factory()->inOrganization($organization)->create());

    expect(issueNumber($organization))->toBe('WO-2026-0001')
        ->and(issueNumber($organization, $branch->id))->toBe('WO-2026-0001')
        // 07:30 on 1 January in Manila: the new year's series.
        ->and(issueNumber($organization, at: '2026-12-31T23:30:00Z'))->toBe('WO-2027-0001')
        ->and(issueNumber($organization))->toBe('WO-2026-0002');
});

it('hands a rolled-back number to the next document: no gap', function () {
    $organization = Organization::factory()->create();

    expect(issueNumber($organization))->toBe('WO-2026-0001');

    try {
        asSystem(fn () => DB::transaction(function () use ($organization): void {
            app(DocumentNumbers::class)->issue($organization->id, null, DocumentType::WorkOrder, new CarbonImmutable('2026-10-08T02:00:00Z'));

            throw new RuntimeException('the document failed to save');
        }));
    } catch (RuntimeException) {
    }

    expect(issueNumber($organization))->toBe('WO-2026-0002');
});

it('rolls back the series row itself when its first number is rolled back', function () {
    $organization = Organization::factory()->create();

    try {
        asSystem(fn () => DB::transaction(function () use ($organization): void {
            app(DocumentNumbers::class)->issue($organization->id, null, DocumentType::Receipt, new CarbonImmutable('2026-10-08T02:00:00Z'));

            throw new RuntimeException('rolled back');
        }));
    } catch (RuntimeException) {
    }

    expect(asSystem(fn () => DocumentSeries::query()->where('organization_id', $organization->id)->count()))->toBe(0)
        ->and(issueNumber($organization, type: DocumentType::Receipt))->toBe('OR-2026-0001');
});

it('refuses to issue outside a transaction', function () {
    config(['database.connections.numbering' => config('database.connections.pgsql')]);

    try {
        (new DocumentNumbers(DB::connection('numbering')))
            ->issue((string) Str::ulid(), null, DocumentType::WorkOrder, CarbonImmutable::now());
    } finally {
        DB::purge('numbering');
    }
})->throws(LogicException::class, 'must run inside the transaction');

it('never issues a duplicate or leaves a gap under concurrent transactions', function () {
    // Child processes need committed rows, so this test works on its own
    // autocommit connection and cleans up after itself.
    config(['database.connections.numbering' => config('database.connections.pgsql')]);
    $db = DB::connection('numbering');
    $organizationId = strtolower((string) Str::ulid());
    $db->table('organizations')->insert([
        'id' => $organizationId,
        'name' => 'Concurrency Test',
        'slug' => 'concurrency-'.$organizationId,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    try {
        $processes = [];
        foreach (range(1, 4) as $worker) {
            $pipes = [];
            $process = proc_open(
                [PHP_BINARY, base_path('tests/Support/issue-numbers.php'), DB::connection()->getDatabaseName(), $organizationId, '12', (string) $worker],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                base_path(),
            );
            expect($process)->not->toBeFalse();
            $processes[] = [$process, $pipes];
        }

        $issued = [];
        foreach ($processes as [$process, $pipes]) {
            $out = (string) stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            expect(proc_close($process))->toBe(0, $err);
            foreach (array_filter(explode("\n", trim($out))) as $line) {
                $issued[] = (int) trim($line);
            }
        }

        sort($issued);

        expect($issued)->not->toBeEmpty()
            ->and($issued)->toBe(range(1, count($issued)))
            ->and((int) $db->table('document_series')->where('organization_id', $organizationId)->value('next_number'))->toBe(count($issued) + 1);
    } finally {
        $db->table('document_series')->where('organization_id', $organizationId)->delete();
        $db->table('organizations')->where('id', $organizationId)->delete();
        DB::purge('numbering');
    }
});
