<?php

declare(strict_types=1);

use App\Casts\DecimalCast;
use App\Casts\MoneyCast;
use App\Database\EnsureSchemaExists;
use Brick\Math\BigDecimal;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('stores timestamps in UTC whatever the server default is', function () {
    expect(DB::scalar("select current_setting('TimeZone')"))->toBe('UTC');
});

it('keeps every table in the API schema, none in public', function () {
    expect(DB::scalar('select current_schema()'))->toBe(config('database.connections.pgsql.search_path'))
        ->and(DB::table('pg_tables')->where('schemaname', 'public')->count())->toBe(0);
});

it('migrates as a role that owns its schema but may not create schemas', function () {
    // The least-privilege setup in docs/deploy.md 1b. Rolled back with the test.
    DB::statement('create role test_least_privilege nologin');
    DB::statement('revoke create on database '.DB::getDatabaseName().' from test_least_privilege');
    DB::statement('set local role test_least_privilege');

    EnsureSchemaExists::on(DB::connection());

    expect(DB::scalar('select current_user'))->toBe('test_least_privilege');
});

it('creates the schema when it is missing', function () {
    // A separate session, so the default connection's test transaction is untouched.
    config(['database.connections.schema_probe' => array_merge(
        config('database.connections.pgsql'),
        ['search_path' => 'test_fresh_schema'],
    )]);
    $probe = DB::connection('schema_probe');
    $probe->beginTransaction();

    try {
        EnsureSchemaExists::on($probe);

        expect($probe->scalar("select exists (select 1 from pg_namespace where nspname = 'test_fresh_schema')"))->toBeTrue();
    } finally {
        $probe->rollBack();
        DB::purge('schema_probe');
    }
});

it('creates money columns as bigint and quantities as numeric(14,3)', function () {
    Schema::create('test_lines', function (Blueprint $table) {
        $table->ulid('id')->primary();
        $table->cents('unit_price_cents');
        $table->quantity('quantity');
        $table->rate('labour_hours');
    });

    $columns = collect(Schema::getColumns('test_lines'))->keyBy('name');

    expect($columns['unit_price_cents']['type_name'])->toBe('int8')
        ->and($columns['quantity']['type'])->toBe('numeric(14,3)')
        ->and($columns['labour_hours']['type'])->toBe('numeric(14,3)');
});

it('refuses a money column not named *_cents', function () {
    Schema::create('test_bad', fn (Blueprint $table) => $table->cents('unit_price'));
})->throws(LogicException::class, 'must be named *_cents');

it('round-trips Money and BigDecimal through Postgres without floats', function () {
    Schema::create('test_lines', function (Blueprint $table) {
        $table->ulid('id')->primary();
        $table->cents('unit_price_cents');
        $table->quantity('quantity');
    });

    $model = new class extends Model
    {
        use HasUlids;

        protected $table = 'test_lines';

        public $timestamps = false;

        protected $guarded = [];

        protected function casts(): array
        {
            return ['unit_price_cents' => MoneyCast::class, 'quantity' => DecimalCast::class];
        }
    };

    $line = $model->newQuery()->create([
        'unit_price_cents' => Money::of('1234.56', 'PHP'),
        'quantity' => '2.5',
    ]);

    expect(DB::table('test_lines')->value('unit_price_cents'))->toBe(123456)
        ->and(DB::table('test_lines')->value('quantity'))->toBe('2.500');

    $fresh = $model->newQuery()->findOrFail($line->getKey());

    expect($fresh->unit_price_cents)->toBeInstanceOf(Money::class)
        ->and((string) $fresh->unit_price_cents)->toBe('PHP 1234.56')
        ->and($fresh->quantity)->toBeInstanceOf(BigDecimal::class)
        ->and($fresh->toArray())->toMatchArray(['unit_price_cents' => 123456, 'quantity' => '2.500']);
});
