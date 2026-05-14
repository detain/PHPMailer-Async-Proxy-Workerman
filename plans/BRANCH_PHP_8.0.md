# Plan: PHP 8.0 Compatibility Branch

## Branch Name
`php-8.0`

## Goal
Create a branch targeting PHP 8.0 that provides full backward compatibility with the current API, supporting both sync (blocking) and async (non-blocking) handling. PHP 8.0 users will get async via a Generator-based coroutine approach instead of Fibers.

## Summary of Changes

| Category | Change |
|----------|--------|
| PHP Minimum | `^8.0` (from `^8.1`) |
| Fibers | Replace with Generator-based coroutines |
| Revolt EventLoop | Replace with React\EventLoop |
| Workerman | Downgrade from `^5.1` to `^4.0` |
| Typed Properties | Keep (PHP 8.0 supports) |
| Return Types | Keep (PHP 8.0 supports) |
| Nullable Types | Keep (PHP 8.0 supports) |

## Key Architectural Changes

### 1. Replace FiberRunner with CallbackRunner

**Current (PHP 8.1+ Fiber-based):**
```php
// FiberRunner.php - uses Fiber class
if (Fiber::getCurrent() !== null) {
    return $work();
}
return self::runWithExistingLoop($work);
```

**New (PHP 8.0 Generator-based):**
```php
// CallbackRunner.php - uses Generator + React\EventLoop
public static function run(Closure $work) {
    if (self::isInCoroutine()) {
        return $work();
    }
    return self::runWithEventLoop($work);
}
```

### 2. Dependency Changes

**composer.json changes:**
```json
{
    "require": {
        "php": "^8.0",
        "workerman/workerman": "^4.0",
        "react/event-loop": "^1.0",
        "react/promise": "^2.0"
    },
    "replace": {
        "phpmailer/phpmailer": "^7.0"
    }
}
```

### 3. Files to Create

| File | Purpose |
|------|---------|
| `src/Async/CallbackRunner.php` | Generator-based async runner (replaces FiberRunner) |
| `src/Async/ReactTransport.php` | Transport using React\EventLoop (optional alt async) |

### 4. Files to Modify

| File | Changes |
|------|---------|
| `src/Async/FiberRunner.php` | Rename to `CoroutineRunner.php` with Generator fallback for 8.0 detection |
| `src/Async/WorkermanTransport.php` | Replace `FiberRunner::run()` with `CallbackRunner::run()` |
| `src/Async/WorkermanConnectionTransport.php` | Replace Revolt with React\EventLoop |
| `composer.json` | Update PHP version, dependencies |
| `src/Async/Transport.php` | Keep unchanged - interface is backward compatible |

## Detailed File Changes

### A. Create: `src/Async/CallbackRunner.php`

```php
<?php
declare(strict_types=1);

namespace PHPMailer\PHPMailer\Async;

use Closure;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use Throwable;

/**
 * Generator-based coroutine runner for PHP 8.0.
 *
 * Provides fiber-like async behavior using Generator + event loop.
 * Falls back to synchronous execution when already in a coroutine.
 */
final class CallbackRunner
{
    /** @var bool */
    private static $inCoroutine = false;

    public static function run(Closure $work)
    {
        // Already inside a coroutine - run inline
        if (self::$inCoroutine) {
            return $work();
        }

        $loop = Loop::get();
        $result = null;
        $error = null;

        $coroutine = self::createCoroutine($work, $result, $error);

        self::$inCoroutine = true;
        try {
            $loop->futureTick($coroutine);
            $loop->run();
        } finally {
            self::$inCoroutine = false;
        }

        if ($error !== null) {
            throw $error;
        }
        return $result;
    }

    public static function isInCoroutine(): bool
    {
        return self::$inCoroutine;
    }

    private static function createCoroutine(Closure $work, &$result, &$error): \Generator
    {
        try {
            $result = $work();
        } catch (Throwable $t) {
            $error = $t;
        }
        yield; // Allow loop to process
    }
}
```

### B. Modify: `src/Async/WorkermanTransport.php`

**Changes:**
- Replace `use Fiber` with `use React\EventLoop\Loop`
- Replace `FiberRunner::run()` with `CallbackRunner::run()`
- Replace `Revolt\EventLoop` with `React\EventLoop\Loop`

**Lines to change:**
```php
// Line 17: Replace imports
// OLD: use Revolt\EventLoop;
// NEW: use React\EventLoop\Loop;

// Line 68: Replace FiberRunner::run() with CallbackRunner::run()
// OLD: return FiberRunner::run(function () use (...) : bool {
// NEW: return CallbackRunner::run(function () use (...) : bool {

// Lines 55-63 in FiberRunner::runWithExistingLoop need alternative
// Instead use: $suspension = Loop::get()->futureTick()
```

### C. Modify: `src/Async/WorkermanConnectionTransport.php`

**Changes:**
- Replace `use Fiber` with Generator handling
- Replace `use Revolt\EventLoop\Suspension` with React\Promise\Deferred
- Replace `$this->readWaiter = $suspension` with Promise-based waiting

### D. Modify: `src/Async/SmtpConnectionPool.php`

**Changes:**
- Keep as-is (no Fiber usage)
- May need to update Revolt references to React\EventLoop

## Test Strategy

### New Tests to Create

| Test File | Coverage |
|-----------|----------|
| `test/Async/CallbackRunnerTest.php` | CallbackRunner functionality |
| `test/Async/GeneratorCoroutineTest.php` | Generator-based coroutine behavior |
| `test/Async/ReactEventLoopTest.php` | React\EventLoop integration |
| `test/Async/Php80CompatibilityTest.php` | PHP 8.0 specific behaviors |

### Modifications to Existing Tests

| Test File | Change |
|-----------|--------|
| `test/Async/FiberRunnerTest.php` | Add fallback tests for non-Fiber environments |
| `test/Async/WorkermanTransportTest.php` | Parametrized tests for both Fiber/Callback runners |

### Compatibility Test Structure

```php
class Php80CompatibilityTest extends TestCase
{
    protected function set_up(): void
    {
        parent::set_up();
        // Detect if running with Fibers or not
        $this->hasFibers = class_exists(\Fiber::class);
    }

    public function testCallbackRunnerBlocksLikeSync(): void
    {
        $runner = new CallbackRunner();
        $result = $runner->run(function() {
            return 'sync_result';
        });
        $this->assertSame('sync_result', $result);
    }

    public function testNestedCoroutinesRunInline(): void
    {
        $result = CallbackRunner::run(function() {
            return CallbackRunner::run(function() {
                return 'nested';
            });
        });
        $this->assertSame('nested', $result);
    }

    public function testAsyncWriteYieldsToEventLoop(): void
    {
        // Test that waitReadable() yields properly
    }
}
```

## Backward Compatibility

### Current Usage (Must Continue Working)

```php
// Sync usage - MUST work unchanged
$mail = new PHPMailer(true);
$mail->isSMTP();
$mail->SMTPAuth = true;
$mail->send(); // Should work exactly as before

// Async usage - MUST work (via CallbackRunner now)
$mail = new PHPMailer(true);
$mail->isSMTP();
TransportFactory::auto()->connect(...); // Should work
$mail->send();
```

### Key Invariants

1. **StreamTransport unchanged** - Blocking behavior is identical
2. **Transport interface unchanged** - No changes to method signatures
3. **SMTP class unchanged** - Same public API
4. **FiberRunner present but deprecated** - Falls back gracefully on PHP 8.0

## Implementation Phases

### Phase 1: Create CallbackRunner (Days 1-2)
- [ ] Create `src/Async/CallbackRunner.php`
- [ ] Create `test/Async/CallbackRunnerTest.php`
- [ ] Verify basic run/return behavior

### Phase 2: Update WorkermanTransport (Days 3-4)
- [ ] Replace Fiber usage with CallbackRunner
- [ ] Replace Revolt with React\EventLoop
- [ ] Test async connect/read/write

### Phase 3: Update WorkermanConnectionTransport (Days 5-6)
- [ ] Replace Revolt\Suspension with React\Promise
- [ ] Test connection pool integration

### Phase 4: Update composer.json (Day 7)
- [ ] Change PHP requirement to `^8.0`
- [ ] Add `react/event-loop: ^1.0`
- [ ] Add `react/promise: ^2.0`
- [ ] Change `workerman/workerman: ^4.0`

### Phase 5: Integration Testing (Days 8-10)
- [ ] Run full test suite on PHP 8.0
- [ ] Verify all 811 tests pass
- [ ] Add PHP 8.0 to CI matrix

### Phase 6: Documentation (Day 11)
- [ ] Update README with PHP 8.0 support
- [ ] Document async behavior differences
- [ ] Add upgrade notes

## Files Summary

### Create (New Files)
```
src/Async/CallbackRunner.php     # Generator-based async runner
test/Async/CallbackRunnerTest.php
test/Async/Php80CompatibilityTest.php
```

### Modify
```
src/Async/FiberRunner.php        # Add CallbackRunner fallback
src/Async/WorkermanTransport.php # Replace FiberRunner with CallbackRunner
src/Async/WorkermanConnectionTransport.php # Replace Revolt with React
src/Async/SmtpConnectionPool.php # Update event loop refs
composer.json                    # Update dependencies
phpunit.xml.dist                 # Add PHP 8.0 to matrix
```

### Keep Unchanged
```
src/Async/Transport.php          # Interface unchanged
src/Async/StreamTransport.php    # Blocking behavior unchanged
src/Async/TransportFactory.php   # Works with both runners
src/PHPMailer.php                # No changes needed
src/SMTP.php                     # No changes needed
```

## Effort Estimate

| Task | Time | Complexity |
|------|------|------------|
| CallbackRunner implementation | 2 days | Medium |
| WorkermanTransport update | 2 days | High |
| WorkermanConnectionTransport update | 2 days | High |
| Composer/dependency update | 1 day | Low |
| Testing & CI | 2 days | Medium |
| Documentation | 1 day | Low |
| **Total** | **10 days** | - |

## Verification Commands

```bash
# After implementation
composer test  # Must pass 800+ tests

# PHP CS
composer check  # Must pass

# Manual verification
php -r "
require 'vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Async\StreamTransport;
use PHPMailer\PHPMailer\Async\CallbackRunner;

\$mail = new PHPMailer(true);
\$mail->isSMTP();
\$mail->Host = 'localhost';
// Test that sync path works unchanged
echo 'Sync path: OK' . PHP_EOL;

// Test async path with CallbackRunner
\$result = CallbackRunner::run(function() {
    return 'async works';
});
echo 'Async path: ' . \$result . PHP_EOL;
"
```
