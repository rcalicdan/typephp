<?php

declare(strict_types=1);

/**
 * @var int[]
 */

$scores = [1, 2, 3];       // should pass
$scores = [1, 2, 'three']; // should fail
