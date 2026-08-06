<?php

declare(strict_types=1);

use TypePHP\Internal\StreamWrapper;

describe('Line Number Preservation', function () {
    test('transforms code without shifting original line numbers for parameter checks', function () {
        $source = <<<'PHP'
<?php

declare(strict_types=1);

/**
 * @param positive-int $number
 */
function number(int $number): int
{
    return $number;
}

number(-5);
PHP;

        $transformed = StreamWrapper::transformSource($source, 'test4.php');

        $origLines = explode("\n", str_replace("\r\n", "\n", $source));
        $transLines = explode("\n", str_replace("\r\n", "\n", $transformed));

        // Total number of lines in transformed source MUST match original source
        expect(\count($transLines))->toBe(\count($origLines));

        // The line containing 'number(-5);' must be on the exact same line index
        $origCallLine = array_search('number(-5);', array_map('trim', $origLines), true);
        $transCallLine = array_search('number(-5);', array_map('trim', $transLines), true);

        expect($transCallLine)->toBe($origCallLine);
    });

    test('transforms constructor property promotion without shifting caller line numbers', function () {
        $source = <<<'PHP'
<?php

declare(strict_types=1);

class Numbers
{
    /**
     * @param int[] $numbers
     */
    public function __construct(public array $numbers)
    {
    }
}

new Numbers(['a', 'b', 'c', 1]);
PHP;

        $transformed = StreamWrapper::transformSource($source, 'test8.php');

        $origLines = explode("\n", str_replace("\r\n", "\n", $source));
        $transLines = explode("\n", str_replace("\r\n", "\n", $transformed));

        expect(\count($transLines))->toBe(\count($origLines));

        $origCallLine = array_search("new Numbers(['a', 'b', 'c', 1]);", array_map('trim', $origLines), true);
        $transCallLine = array_search("new Numbers(['a', 'b', 'c', 1]);", array_map('trim', $transLines), true);

        expect($transCallLine)->toBe($origCallLine);
    });
});
