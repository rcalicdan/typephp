<?php 

use TypePHP\Tests\Fixtures\Types\UserObjectShape;

/**
 * Strictly accepts stdClass instances matching the shape
 *
 * @param stdClass{id: positive-int, name: non-empty-string} $payload
 */
function testStrictStdClassShapeContract(object $payload): bool
{
    return true;
}

$std = new stdClass();
$std->id = 100;
$std->name = 'Alice';
testStrictStdClassShapeContract($std); 

$user = new UserObjectShape(100, 'Alice');
testStrictStdClassShapeContract($user); // ❌ Throws TypeError!