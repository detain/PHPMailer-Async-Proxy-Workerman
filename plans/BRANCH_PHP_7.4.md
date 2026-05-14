# Plan: PHP 7.4 Compatibility Branch

## Branch Name
`php-7.4`

## Goal
Create a branch targeting PHP 7.4 that provides full backward compatibility with the current API. PHP 7.4 users will get async via a Generator-based coroutine approach (same as 8.0) plus callback-based async for deeper compatibility.

## Summary of Changes

| Category | Change |
|----------|--------|
| PHP Minimum | `^7.4` (from `^8.1`) |
| Fibers | Replace with Generator-based coroutines |
| Typed Properties | Remove typed declarations, use docblocks |
| Revolt EventLoop | Replace with React\EventLoop |
| Workerman | Downgrade from `^5.1` to `^4.0` |
| Return Types | Keep (PHP 7.0+) |
| Nullable Types | Keep (PHP 7.1+) |
| Arrow Functions | Keep (PHP 7.4) |

## Key Differences from PHP 8.0 Branch

The PHP 7.4 branch builds on the PHP 8.0 branch and additionally:
1. Removes all typed property declarations (`: string`, `: int`, etc.)
2. Keeps return types and nullable types since PHP 7.0/7.1 supports them

## PHP 8+ Features Used in Current Code

### Features to Remove (PHP 7.4 incompatible)

**Typed Properties (PHP 7.4 only, not in 7.0-7.3):**
```php
// These MUST be removed in php-7.4 branch
private string $readBuffer = '';
private int $readTimeout = 30;
private bool $eof = false;
```

### Features to Keep

- Return type declarations (`: bool`, `: void`, `: string`, `: ?string`)
- Nullable type hints (`?string`, `?callable`)
- Arrow functions (`fn() =>`)
- Class constant types

## Detailed File Changes

### A. Files with Typed Properties to Update

#### `src/Async/WorkermanTransport.php`

**Lines 40-54** - Remove type declarations:
```php
// OLD (PHP 8.0+)
private string $readBuffer = '';
private int $readTimeout = 30;
private ?Closure $errorSink = null;
private bool $eof = false;

// NEW (PHP 7.4)
 /** @var string */
private $readBuffer = '';
/** @var int */
private $readTimeout = 30;
/** @var callable|null */
private $errorSink = null;
/** @var bool */
private $eof = false;
```

Same pattern for:
- `$connectError`
- `$lastWarning`
- `$proxyProtocolHeader`

#### `src/Async/WorkermanConnectionTransport.php`

**Lines 40-58** - Remove typed properties:
```php
// OLD
private string $readBuffer = '';
private int $readTimeout = 30;
private bool $eofSignalled = false;

// NEW
/** @var string */
private $readBuffer = '';
/** @var int */
private $readTimeout = 30;
/** @var bool */
private $eofSignalled = false;
```

#### `src/Async/StreamTransport.php`

**Lines 30-31** - Remove typed properties:
```php
// OLD
private int $readTimeout = 30;

// NEW
/** @var int */
private $readTimeout = 30;
```

#### `src/Async/SmtpConnectionPool.php`

**Lines 62-70** - Remove typed properties:
```php
// OLD
/** @var array<string, list<SMTP>> */
private array $idle = [];
private int $maxPerKey = 10;

// NEW
/** @var array<string, list<SMTP>> */
private $idle = [];
/** @var int */
private $maxPerKey = 10;
```

### B. Dependency Changes

**composer.json:**
```json
{
    "require": {
        "php": "^7.4",
        "workerman/workerman": "^4.0",
        "react/event-loop": "^1.0",
        "react/promise": "^2.0"
    }
}
```

## Files to Create

| File | Purpose |
|------|---------|
| `src/Async/CallbackRunner.php` | Generator-based async runner |
| `test/Async/CallbackRunnerTest.php` | CallbackRunner tests |
| `test/Async/Php74CompatibilityTest.php` | PHP 7.4 specific tests |

## Files to Modify

| File | Changes |
|------|---------|
| `src/Async/WorkermanTransport.php` | Remove typed properties, replace FiberRunner |
| `src/Async/WorkermanConnectionTransport.php` | Remove typed properties, replace Revolt |
| `src/Async/StreamTransport.php` | Remove typed properties |
| `src/Async/SmtpConnectionPool.php` | Remove typed properties |
| `src/Async/FiberRunner.php` | Deprecate, add fallback to CallbackRunner |
| `composer.json` | Update PHP, dependencies |
| `phpstan.neon` | Update PHP version |

## Test Strategy

### New Tests

| Test File | Coverage |
|-----------|----------|
| `test/Async/CallbackRunnerTest.php` | Generator coroutine runner |
| `test/Async/Php74CompatibilityTest.php` | Typed property removal verification |
| `test/Async/EventLoopCompatibilityTest.php` | React\EventLoop on PHP 7.4 |

### Typed Property Removal Tests

```php
class Php74CompatibilityTest extends TestCase
{
    public function testTypedPropertiesRemovedFromWorkermanTransport(): void
    {
        $reflection = new ReflectionClass(WorkermanTransport::class);
        foreach ($reflection->getProperties() as $property) {
            $this->assertNull(
                $property->getType(),
                'Property ' . $property->getName() . ' should not have type declaration'
            );
        }
    }

    public function testReturnTypesPreserved(): void
    {
        $method = new ReflectionMethod(WorkermanTransport::class, 'connect');
        $this->assertNotNull($method->getReturnType());
        $this->assertSame('bool', (string) $method->getReturnType());
    }

    public function testNullableTypesPreserved(): void
    {
        $method = new ReflectionMethod(WorkermanTransport::class, 'setErrorHandler');
        $params = $method->getParameters();
        $this->assertSame('callable|null', (string) $params[0]->getType());
    }
}
```

### Compatibility Test

```php
class EventLoopCompatibilityTest extends TestCase
{
    public function testReactEventLoopWorksOnPhp74(): void
    {
        $loop = React\EventLoop\Loop::get();
        $this->assertInstanceOf(React\EventLoop\LoopInterface::class, $loop);

        $result = null;
        $loop->futureTick(function() use (&$result) {
            $result = 'tick';
        });
        $loop->run();
        $this->assertSame('tick', $result);
    }

    public function testCallbackRunnerWithReactLoop(): void
    {
        $result = CallbackRunner::run(function() {
            return 'async_works_on_74';
        });
        $this->assertSame('async_works_on_74', $result);
    }
}
```

## Backward Compatibility

### Current Usage Must Continue Working

```php
// Sync usage - unchanged
$mail = new PHPMailer(true);
$mail->isSMTP();
$mail->SMTPAuth = true;
$mail->send();

// Async usage with callbacks
$transport = new WorkermanTransport();
$transport->connect('smtp.example.com', 587, 30);
$transport->write("QUIT\r\n");
$transport->close();

// StreamTransport - unchanged, works as fallback
$transport = new StreamTransport();
$transport->connect('smtp.example.com', 587, 30);
```

### Class Hierarchy Preservation

```
Transport (interface - unchanged)
├── StreamTransport (blocking, unchanged)
├── WorkermanTransport (async via callbacks)
└── WorkermanConnectionTransport (async via callbacks)
```

## Implementation Phases

### Phase 1: Base Setup (Days 1-2)
- [ ] Create new branch from `master`
- [ ] Create `src/Async/CallbackRunner.php` (reuse from 8.0 plan)
- [ ] Create `test/Async/CallbackRunnerTest.php`
- [ ] Update composer.json dependencies

### Phase 2: Remove Typed Properties (Days 3-5)

**WorkermanTransport.php:**
- [ ] Remove `: string` from `$readBuffer`
- [ ] Remove `: int` from `$readTimeout`
- [ ] Remove `: ?Closure` from `$errorSink`
- [ ] Remove `: bool` from `$eof`
- [ ] Update docblocks with `@var` annotations

**WorkermanConnectionTransport.php:**
- [ ] Remove all typed property declarations
- [ ] Add `@var` docblocks

**StreamTransport.php:**
- [ ] Remove `: int` from `$readTimeout`
- [ ] Add `@var` docblock

**SmtpConnectionPool.php:**
- [ ] Remove typed property declarations
- [ ] Update array type hints

### Phase 3: Update Async Infrastructure (Days 6-7)
- [ ] Update WorkermanTransport to use CallbackRunner
- [ ] Update WorkermanConnectionTransport to use React\EventLoop
- [ ] Update FiberRunner to detect PHP version and delegate

### Phase 4: Testing (Days 8-10)
- [ ] Run full test suite
- [ ] Verify 800+ tests pass
- [ ] Add PHP 7.4 to CI matrix
- [ ] Run PHPStan on PHP 7.4 target

### Phase 5: Documentation (Day 11)
- [ ] Update README with PHP 7.4 support
- [ ] Add changelog entry
- [ ] Document known differences

## Files Summary

### Create (New Files)
```
src/Async/CallbackRunner.php
test/Async/CallbackRunnerTest.php
test/Async/Php74CompatibilityTest.php
test/Async/EventLoopCompatibilityTest.php
```

### Modify
```
src/Async/WorkermanTransport.php        # Remove typed props, use CallbackRunner
src/Async/WorkermanConnectionTransport.php # Remove typed props, use React\EventLoop
src/Async/StreamTransport.php           # Remove typed props
src/Async/SmtpConnectionPool.php        # Remove typed props
src/Async/FiberRunner.php               # Deprecate, fallback
composer.json                           # PHP ^7.4, react/*
phpunit.xml.dist                        # Add PHP 7.4 to matrix
phpstan.neon                            # Update PHP version
```

### Keep Unchanged
```
src/Async/Transport.php
src/Async/StreamTransport.php (logic)
src/Async/TransportFactory.php
src/PHPMailer.php
src/SMTP.php
```

## Effort Estimate

| Task | Time | Complexity |
|------|------|------------|
| Setup + CallbackRunner | 2 days | Medium |
| Remove typed properties (4 files) | 3 days | Low |
| Update async infrastructure | 2 days | High |
| Testing & CI | 2 days | Medium |
| Documentation | 1 day | Low |
| **Total** | **10 days** | - |

## Verification Commands

```bash
# Run tests
composer test

# Check for remaining typed properties
rg 'private (string|int|bool|array|Closure)\s+\$' src/Async/

# Verify PHP version detection
php -r "
echo 'PHP version: ' . PHP_VERSION . PHP_EOL;
echo 'Typed properties: ' . (PHP_VERSION_ID >= 70400 ? 'supported' : 'not supported') . PHP_EOL;
"

# Manual verification
php -r "
require 'vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Async\StreamTransport;
use PHPMailer\PHPMailer\Async\WorkermanTransport;

// Test StreamTransport (blocking - always works)
\$t = new StreamTransport();
echo 'StreamTransport: OK' . PHP_EOL;

// Test WorkermanTransport with typed props removed
\$t = new WorkermanTransport();
echo 'WorkermanTransport: OK' . PHP_EOL;
"
```

## Known Limitations on PHP 7.4

1. **No Fibers** - Async uses Generator-based approach, less efficient than Fibers
2. **Callback hell risk** - Without Fibers, complex async flows need careful structuring
3. **Performance** - Generator coroutines have more overhead than Fiber suspension

## Migration Guide for Users

```php
// Existing code works unchanged
$mail = new PHPMailer(true);
$mail->isSMTP();
$mail->Host = 'smtp.example.com';
$mail->send(); // Works on PHP 7.4+

// For async on PHP 7.4, use WorkermanTransport
$transport = TransportFactory::workermanConnection(); // Falls back gracefully
$mail->setTransport($transport);
$mail->send(); // Non-blocking if running in Workerman
```
