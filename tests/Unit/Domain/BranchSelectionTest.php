<?php

declare(strict_types=1);

use App\Domain\Tenancy\BranchSelection;
use App\Domain\Tenancy\ScopeDenial;

it('allows every branch when the user has no pins', function () {
    $selection = BranchSelection::resolve(['a', 'b'], [], null);

    expect($selection->allowedBranchIds)->toBe(['a', 'b'])
        ->and($selection->restricted)->toBeFalse()
        ->and($selection->selectedBranchId)->toBeNull();
});

it('narrows to the pinned branches of the organization only', function () {
    $selection = BranchSelection::resolve(['a', 'b', 'c'], ['c', 'elsewhere'], null);

    expect($selection->allowedBranchIds)->toBe(['c'])->and($selection->restricted)->toBeTrue();
});

it('selects the single allowed branch when no header is sent', function () {
    expect(BranchSelection::resolve(['a', 'b'], ['b'], null)->selectedBranchId)->toBe('b');
});

it('selects the header branch when it is allowed', function () {
    expect(BranchSelection::resolve(['a', 'b'], [], 'b')->selectedBranchId)->toBe('b');
});

it('treats "all" and an empty header as every allowed branch', function (?string $header) {
    expect(BranchSelection::resolve(['a', 'b'], [], $header)->selectedBranchId)->toBeNull();
})->with(['all', '', '  ']);

it('refuses a branch the user may not work in, rather than ignoring it', function (array $pins, string $header) {
    $selection = BranchSelection::resolve(['a', 'b'], $pins, $header);

    expect($selection->denial)->toBe(ScopeDenial::BranchNotAllowed)
        ->and($selection->selectedBranchId)->toBeNull();
})->with([
    'another organization\'s branch' => [[], 'foreign'],
    'an unpinned branch' => [['a'], 'b'],
    'garbage' => [[], '../../etc'],
]);
