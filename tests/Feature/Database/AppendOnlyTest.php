<?php

declare(strict_types=1);

use App\Database\AppendOnly;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * DDL is transactional in Postgres, so the throwaway table below vanishes with
 * RefreshDatabase's rollback. Each statement expected to fail runs in its own
 * DB::transaction (a savepoint), otherwise the first exception would abort the
 * whole test transaction.
 */

beforeEach(function () {
    Schema::create('test_ledger', function (Blueprint $table) {
        $table->ulid('id')->primary();
        $table->cents('amount_cents');
    });
    AppendOnly::protect('test_ledger');

    DB::table('test_ledger')->insert(['id' => '01JTESTTESTTESTTESTTESTTES', 'amount_cents' => 1000]);
});

function inSavepoint(Closure $statement): Closure
{
    return fn () => DB::transaction($statement);
}

it('allows inserts', function () {
    DB::table('test_ledger')->insert(['id' => '01JTESTTESTTESTTESTTESTTE2', 'amount_cents' => -1000]);

    expect(DB::table('test_ledger')->count())->toBe(2)
        ->and(AppendOnly::isProtected('test_ledger'))->toBeTrue();
});

it('rejects UPDATE', function () {
    expect(inSavepoint(fn () => DB::table('test_ledger')->update(['amount_cents' => 1])))
        ->toThrow(QueryException::class, 'append-only table test_ledger: UPDATE is not allowed');

    expect(DB::table('test_ledger')->value('amount_cents'))->toBe(1000);
});

it('rejects DELETE', function () {
    expect(inSavepoint(fn () => DB::table('test_ledger')->delete()))
        ->toThrow(QueryException::class, 'append-only table test_ledger: DELETE is not allowed');

    expect(DB::table('test_ledger')->count())->toBe(1);
});

it('rejects TRUNCATE', function () {
    expect(inSavepoint(fn () => DB::statement('truncate test_ledger')))
        ->toThrow(QueryException::class, 'append-only table test_ledger: TRUNCATE is not allowed');
});

it('raises SQLSTATE 23001 restrict_violation', function () {
    try {
        DB::transaction(fn () => DB::table('test_ledger')->delete());
        $this->fail('DELETE was allowed.');
    } catch (QueryException $e) {
        expect($e->getCode())->toBe('23001');
    }
});

it('can be lifted in a down() migration', function () {
    AppendOnly::unprotect('test_ledger');

    DB::table('test_ledger')->update(['amount_cents' => 1]);

    expect(AppendOnly::isProtected('test_ledger'))->toBeFalse()
        ->and(DB::table('test_ledger')->value('amount_cents'))->toBe(1);
});

it('refuses identifiers that are not plain lowercase names', function () {
    AppendOnly::protect('test_ledger"; drop table users; --');
})->throws(InvalidArgumentException::class);
