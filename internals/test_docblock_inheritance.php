<?php

declare(strict_types=1);

// -------------------------------------------------------------
// 1. Parent Class Method Inheritance
// -------------------------------------------------------------
class BaseRepository
{
    /**
     * @param positive-int $id
     *
     * @return array{id: positive-int, name: string}
     */
    public function find(int $id): array
    {
        return ['id' => $id, 'name' => 'Alice'];
    }
}

class UserRepository extends BaseRepository
{
    // No docblock here! Should inherit @param positive-int $id and @return array{id: positive-int, name: string}
    public function find(int $id): array
    {
        if ($id === 999) {
            // Returns negative id (-5), violating inherited return shape array{id: positive-int}
            return ['id' => -5, 'name' => 'Invalid'];
        }

        return parent::find($id);
    }
}

// -------------------------------------------------------------
// 2. Interface Method Inheritance
// -------------------------------------------------------------
interface PaymentGatewayInterface
{
    /**
     * @param non-empty-string $currency
     * @param int<1, 1000000> $amount
     */
    public function pay(string $currency, int $amount): bool;
}

class StripeGateway implements PaymentGatewayInterface
{
    // No docblock here! Should inherit @param non-empty-string and @param int<1, 1000000>
    public function pay(string $currency, int $amount): bool
    {
        return true;
    }
}

// -------------------------------------------------------------
// 3. Trait Method Inheritance
// -------------------------------------------------------------
trait LoggerTrait
{
    /**
     * @param 'info'|'warning'|'error' $level
     * @param non-empty-string $message
     */
    public function log(string $level, string $message): void
    {
    }
}

class ApplicationService
{
    use LoggerTrait;

    // Overrides method with no docblock! Should inherit @param 'info'|'warning'|'error'
    public function log(string $level, string $message): void
    {
    }
}

echo "=== Testing DocBlock Method Contract Inheritance (LSP) ===\n\n";

// 1. Parent Class Method Inheritance
echo "1. Testing Parent Class Method Inheritance...\n";
$userRepo = new UserRepository();

// Valid call
$userRepo->find(10);
echo "   ✅ Valid find(10) passed!\n";

// Invalid param: -5 is not positive-int
try {
    $userRepo->find(-5);
    echo "   ❌ Failed to catch invalid parameter on inherited method!\n";
} catch (TypeError $e) {
    echo '   ✅ CAUGHT EXPECTED ERROR (Param): ' . $e->getMessage() . "\n";
}

// Invalid return: find(999) returns ['id' => -5]
try {
    $userRepo->find(999);
    echo "   ❌ Failed to catch invalid return on inherited method!\n";
} catch (TypeError $e) {
    echo '   ✅ CAUGHT EXPECTED ERROR (Return): ' . $e->getMessage() . "\n";
}

// 2. Interface Method Inheritance
echo "\n2. Testing Interface Method Inheritance...\n";
$stripe = new StripeGateway();

// Valid call
$stripe->pay('USD', 500);
echo "   ✅ Valid pay('USD', 500) passed!\n";

// Invalid param: empty currency string ''
try {
    $stripe->pay('', 500);
    echo "   ❌ Failed to catch empty currency on inherited interface method!\n";
} catch (TypeError $e) {
    echo '   ✅ CAUGHT EXPECTED ERROR: ' . $e->getMessage() . "\n";
}

// Invalid param: amount 0 out of int<1, 1000000> range
try {
    $stripe->pay('USD', 0);
    echo "   ❌ Failed to catch amount=0 on inherited interface method!\n";
} catch (TypeError $e) {
    echo '   ✅ CAUGHT EXPECTED ERROR: ' . $e->getMessage() . "\n";
}

// 3. Trait Method Inheritance
echo "\n3. Testing Trait Method Inheritance...\n";
$app = new ApplicationService();

// Valid call
$app->log('info', 'System booted');
echo "   ✅ Valid log('info', 'System booted') passed!\n";

// Invalid param: 'debug' is not in 'info'|'warning'|'error'
try {
    $app->log('debug', 'System booted');
    echo "   ❌ Failed to catch invalid log level on inherited trait method!\n";
} catch (TypeError $e) {
    echo '   ✅ CAUGHT EXPECTED ERROR: ' . $e->getMessage() . "\n";
}

echo "\n🎉 BASELINE TEST COMPLETED!\n";
