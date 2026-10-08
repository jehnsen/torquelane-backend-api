<?php

declare(strict_types=1);

namespace Tests\Golden\Support;

use RuntimeException;

/**
 * A fixture argument that is not a whole number of centavos (a 3-decimal
 * rate). The API stores rates in centavos, so such an input cannot reach it;
 * the case is a pinned divergence, never silently rounded on the way in.
 */
final class SubCentavoInput extends RuntimeException {}
