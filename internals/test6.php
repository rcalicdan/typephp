<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

/**
 * @var int[] $scores
 */
$scores = [1, 2, 3];       // should pass

$scores = [1, 2, 'three']; // 💥 SHOULD FAIL AND WILL NOW FAIL!
