# PHPMailer-Async-Proxy-Workerman: PHP Version Backport Plans

This directory contains detailed implementation plans for creating backward-compatible branches targeting older PHP versions.

## Branch Plans

| Branch | PHP Target | Status | Complexity | Effort |
|--------|------------|--------|------------|--------|
| `php-8.0` | PHP 8.0.x | Planned | Medium | ~10 days |
| `php-7.4` | PHP 7.4.x | Planned | Medium-High | ~10 days |
| `php-7.1` | PHP 7.1.x | Planned | High | ~12 days |
| `php-5.6` | PHP 5.6.x | Planned | Very High | ~18 days |

## Quick Summary

### PHP 8.0 Branch
- **What's different:** No Fibers (still PHP 8.1+), use Generator-based coroutines
- **Key changes:** Replace `FiberRunner` with `CallbackRunner`, Revolt → React\EventLoop
- **Dependencies:** `workerman/workerman: ^4.0`, `react/event-loop: ^1.0`

### PHP 7.4 Branch
- **What's different:** No typed properties (PHP 7.4+), no Fibers
- **Key changes:** Remove `: string`, `: int` property declarations, use docblocks
- **Dependencies:** Same as 8.0 branch

### PHP 7.1 Branch
- **What's different:** No typed properties, no nullable types (`?T`), no void returns
- **Key changes:** Remove all modern type syntax, keep return types (PHP 7.0+)
- **Dependencies:** Same as 8.0 branch

### PHP 5.6 Branch
- **What's different:** No type declarations at all, no return types, callback-based async only
- **Key changes:** Complete type removal, Workerman 3.x instead of 5.x, full rewrite
- **Dependencies:** `workerman/workerman: ^3.5`, `react/event-loop: ^0.4`

## Architecture Invariants

All branches maintain these guarantees:

### 1. Identical Public API
```php
// This code works unchanged on all branches
$mail = new PHPMailer(true);
$mail->isSMTP();
$mail->Host = 'smtp.example.com';
$mail->send(); // Works everywhere
```

### 2. Transport Interface Compatibility
```php
// All transports implement the same interface
$transport->connect($host, $port, $timeout, $options);
$transport->write($data);
$transport->readLine($maxLength);
$transport->close();
```

### 3. Sync/Async Toggle
```php
// Blocking (sync) - always works, uses StreamTransport
$mail->send();

// Non-blocking (async) - uses Workerman/React based on PHP version
$transport = TransportFactory::auto();
$mail->setTransport($transport);
$mail->send(); // Non-blocking in Workerman context
```

## File Structure

```
plans/
├── BRANCH_PHP_8.0.md       # PHP 8.0 implementation plan
├── BRANCH_PHP_7.4.md       # PHP 7.4 implementation plan
├── BRANCH_PHP_7.1.md       # PHP 7.1 implementation plan
├── BRANCH_PHP_5.6.md       # PHP 5.6 implementation plan
└── README.md               # This file
```

## Strategy Overview

### For PHP 8.0 and 7.4
These branches are incremental changes from master:
1. Add `CallbackRunner` as Fiber alternative
2. Replace Fiber-based async with Generator/Callback-based
3. Remove typed properties (7.4 only)
4. Update dependencies

### For PHP 7.1 and 5.6
These branches require more substantial changes:
1. Remove ALL type declarations (parameters, returns, properties)
2. Rewrite async infrastructure without Fibers
3. Use older Workerman version (3.x)
4. Different event loop library (React 0.4 for 5.6)

## Shared Components

### CallbackRunner (All Branches)
All async branches use some form of callback-based runner:

**PHP 8.0/7.4/7.1:** Generator-based coroutines with React\EventLoop
```php
CallbackRunner::run(function() {
    // Sync-looking code that yields to event loop
    $result = someAsyncOperation();
    return $result;
});
```

**PHP 5.6:** Pure callback-based
```php
$deferred = new React\Promise\Deferred();
$asyncOperation(function($result) use ($deferred) {
    $deferred->resolve($result);
});
return $deferred->promise();
```

### StreamTransport (All Branches)
Blocking transport that works identically everywhere:
```php
$transport = new StreamTransport();
$transport->connect('smtp.example.com', 587, 30);
$transport->write("QUIT\r\n");
$transport->close();
```

## Testing Strategy

Each branch includes:
1. **Compatibility tests** - Verify no type declarations remain
2. **Parity tests** - Verify behavior matches blocking version
3. **Integration tests** - Full SMTP flow with each transport
4. **CI matrix** - Multiple PHP versions tested

## Dependencies by Branch

```
master (current)
├── PHP: ^8.1
├── workerman/workerman: ^5.1
├── revolt/event-loop: ^1.0
└── Features: Fibers, typed properties, return types, nullable types, void returns

php-8.0
├── PHP: ^8.0
├── workerman/workerman: ^4.0
├── react/event-loop: ^1.0
└── Features: Generators (no Fibers), typed properties, return types, nullable types

php-7.4
├── PHP: ^7.4
├── workerman/workerman: ^4.0
├── react/event-loop: ^1.0
└── Features: No typed properties, no Fibers, return/nullable types OK

php-7.1
├── PHP: ^7.1
├── workerman/workerman: ^4.0
├── react/event-loop: ^1.0
└── Features: No typed properties, no nullable/?, no void, return types OK

php-5.6
├── PHP: ^5.6
├── workerman/workerman: ^3.5
├── react/event-loop: ^0.4
└── Features: No types at all, callbacks only, no return type declarations
```

## Backward Compatibility Priority

1. **100% API compatibility** - Existing code must work unchanged
2. **Transport interface stability** - All transports implement same interface
3. **Sync first, async opt-in** - Blocking works always, async requires setup
4. **Progressive enhancement** - Higher PHP versions get better async performance

## Recommended Implementation Order

1. **Start with `php-8.0`** - Easiest changes, validates architecture
2. **Then `php-7.4`** - Reuse 8.0 work, just remove typed properties
3. **Then `php-7.1`** - Remove nullable/void syntax
4. **Finally `php-5.6`** - Complete rewrite, test thoroughly

## CI Matrix

Each branch should add to `.github/workflows/tests.yml`:

```yaml
jobs:
  test:
    strategy:
      matrix:
        php-version: ['8.1', '8.0']  # For php-8.0 branch
        # or ['8.1', '7.4'] for php-7.4 branch
        # or ['7.4', '7.1'] for php-7.1 branch
        # or ['7.4', '5.6'] for php-5.6 branch
```

## User Impact

| User PHP Version | Current | With Branch | Behavior |
|-----------------|---------|-------------|----------|
| 8.1+ | ✅ Works | ✅ Works | Full Fiber async |
| 8.0 | ❌ Breaks | ✅ Works | Generator async |
| 7.4 | ❌ Breaks | ✅ Works | Callback async |
| 7.1 | ❌ Breaks | ✅ Works | Callback async |
| 5.6 | ❌ Breaks | ✅ Works | Legacy callback |

## Questions to Answer During Implementation

1. Can we share tests across branches using polyfills?
2. Should we use composer.json `replace` or `provide` for version targeting?
3. How to handle phpdoc `@method` annotations for IDE support on type-free code?
4. What's the minimum viable async test coverage per branch?

## References

- [PHP Version History](https://www.php.net/releases/5_6_0/)
- [Workerman 3.x Documentation](https://www.workerman.net/)
- [Workerman 4.x Documentation](https://wiki.workerman.net/)
- [React\EventLoop](https://github.com/reactphp/event-loop)
- [PHPMailer Upstream](https://github.com/PHPMailer/PHPMailer)
