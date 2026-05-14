# Plan: PHP 7.1 Compatibility Branch

## Branch Name
`php-7.1`

## Goal
Create a branch targeting PHP 7.1 that provides full backward compatibility with the current API. PHP 7.1 users will get async via a callback-based approach (no Generators since they'll be used, but no Fiber-based code).

## Summary of Changes

| Category | Change |
|----------|--------|
| PHP Minimum | `^7.1` (from `^8.1`) |
| Fibers | Not available - use callback-based async |
| Typed Properties | Remove (PHP 7.4 feature) |
| Nullable Types | Remove `?T` syntax (PHP 7.1+ supports, but we're removing for 7.0 compat) |
| Void Return Types | Remove `(): void` (PHP 7.1+ supports, but we're going to 7.0 compat) |
| Return Types | Keep (PHP 7.0+) |
| Revolt EventLoop | Replace with React\EventLoop |
| Workerman | Downgrade from `^5.1` to `^4.0` |

**Note:** PHP 7.1 does support nullable types (`?string`) and void returns, but this branch is designed to be closer to PHP 7.0 compatible. If you want full PHP 7.0 compatibility, see the `php-5.6` branch plan.

## Key Differences from PHP 7.4 Branch

The PHP 7.1 branch is a step below 7.4 and requires:
1. All PHP 7.4 changes PLUS
2. Remove nullable type prefixes (`?string` → `string|null` or just remove types)
3. Remove void return types (keep return types but not `void`)

## PHP 8+ Features Used in Current Code

### Features to Remove

**Typed Properties (PHP 7.4+):**
```php
private string $x = '';
private int $y = 0;
```

**Nullable Types (PHP 7.1+):**
```php
public function setErrorHandler(?callable $handler): void
private ?Closure $errorSink = null;
```

**Void Returns (PHP 7.1+):**
```php
public function close(): void
public function setReadTimeout(int $seconds): void
```

## Detailed File Changes

### A. Nullable Type Removal Pattern

For parameters and return types that use `?Type`:

**Method parameters:**
```php
// OLD (PHP 7.1+ with nullable)
public function setErrorHandler(?callable $handler): void

// NEW (PHP 7.0 compatible)
// Option 1: Remove type hint entirely
public function setErrorHandler($handler = null)
// Option 2: Use union via docblock
/**
 * @param callable|null $handler
 */
public function setErrorHandler($handler = null)
```

**Return types with `?`:**
```php
// OLD
public function readLine(int $maxLength): ?string

// NEW
public function readLine($maxLength) {
    // ... returns string or null
}
```

**Class properties:**
```php
// OLD
private ?string $proxyProtocolHeader = null;

// NEW
/** @var string|null */
private $proxyProtocolHeader = null;
```

### B. Void Return Type Removal

```php
// OLD (PHP 7.1+)
public function close(): void
public function setReadTimeout(int $seconds): void
public function clearLastWarning(): void

// NEW (PHP 7.0 compatible)
public function close()
public function setReadTimeout($seconds)
public function clearLastWarning()
```

### C. Files with These Patterns to Update

#### `src/Async/Transport.php` (Interface)

**Lines to change:**
```php
// Line 50 - close(): void
public function close()  // Remove : void

// Line 102 - setReadTimeout(int): void
public function setReadTimeout($seconds)

// Line 123 - clearLastWarning(): void
public function clearLastWarning()

// Line 140 - setErrorHandler(?callable): void
public function setErrorHandler($handler = null)
```

#### `src/Async/WorkermanTransport.php`

**Lines to change:**
```php
// Line 56 - setErrorHandler(?callable): void
public function setErrorHandler($handler = null)

// Line 61 - setProxyProtocolHeader(?string): void
public function setProxyProtocolHeader($bytes = null)

// Line 187 - close(): void
public function close()

// Line 269 - setReadTimeout(int): void
public function setReadTimeout($seconds)

// Line 287 - clearLastWarning(): void
public function clearLastWarning()

// Properties with nullable types:
private $errorSink = null;
private $proxyProtocolHeader = null;
```

#### `src/Async/WorkermanConnectionTransport.php`

**Lines to change:**
```php
// setErrorHandler(?callable): void
public function setErrorHandler($handler = null)

// setProxyProtocolHeader(?string): void
public function setProxyProtocolHeader($bytes = null)

// close(): void
public function close()

// setReadTimeout(int): void
public function setReadTimeout($seconds)

// clearLastWarning(): void
public function clearLastWarning()
```

#### `src/Async/StreamTransport.php`

**Lines to change:**
```php
// Line 43 - setErrorHandler(?callable): void
public function setErrorHandler($handler = null)

// Line 48 - setProxyProtocolHeader(?string): void
public function setProxyProtocolHeader($bytes = null)

// Line 187 - close(): void
public function close()

// Line 287 - clearLastWarning(): void
public function clearLastWarning()

// Properties:
private $errorSink = null;
private $proxyProtocolHeader = null;
```

#### `src/Async/SmtpConnectionPool.php`

**Lines to change:**
```php
// setErrorHandler(?callable): void
public function setErrorHandler($handler = null)
```

## Files to Create

| File | Purpose |
|------|---------|
| `src/Async/CallbackRunner.php` | Generator-based async runner |
| `test/Async/CallbackRunnerTest.php` | Tests for callback runner |
| `test/Async/Php71CompatibilityTest.php` | PHP 7.1 specific tests |

## Files to Modify

| File | Changes |
|------|---------|
| `src/Async/Transport.php` | Remove `void` returns, nullable types |
| `src/Async/WorkermanTransport.php` | Remove typed props, `void`, nullable types |
| `src/Async/WorkermanConnectionTransport.php` | Remove typed props, `void`, nullable types |
| `src/Async/StreamTransport.php` | Remove typed props, `void`, nullable types |
| `src/Async/SmtpConnectionPool.php` | Remove typed props, nullable types |
| `composer.json` | PHP ^7.1, react/* dependencies |

## Test Strategy

### New Tests

| Test File | Coverage |
|-----------|----------|
| `test/Async/CallbackRunnerTest.php` | Callback-based runner |
| `test/Async/Php71CompatibilityTest.php` | Void/nullable removal verification |
| `test/Async/TransportInterfaceTest.php` | Interface compatibility |

### Compatibility Tests

```php
class Php71CompatibilityTest extends TestCase
{
    public function testNoVoidReturnTypes(): void
    {
        $methods = ['close', 'setReadTimeout', 'clearLastWarning'];
        foreach ($methods as $methodName) {
            $method = new ReflectionMethod(WorkermanTransport::class, $methodName);
            $returnType = $method->getReturnType();
            $this->assertNull(
                $returnType,
                "Method $methodName should not have void return type"
            );
        }
    }

    public function testNoNullableParameterTypes(): void
    {
        $method = new ReflectionMethod(WorkermanTransport::class, 'setErrorHandler');
        $param = $method->getParameters()[0];
        $type = $param->getType();
        // Should not be Nullable type
        if ($type !== null) {
            $this->assertNotInstanceOf(
                ReflectionNamedType::class,
                $type,
                'Parameter should not use ?nullable syntax'
            );
        }
    }

    public function testNoTypedProperties(): void
    {
        $reflection = new ReflectionClass(WorkermanTransport::class);
        foreach ($reflection->getProperties() as $property) {
            $this->assertNull(
                $property->getType(),
                'Property ' . $property->getName() . ' should not have type'
            );
        }
    }

    public function testTransportInterfaceCompliance(): void
    {
        $transport = new WorkermanTransport();
        $this->assertInstanceOf(Transport::class, $transport);

        // Verify all interface methods exist with correct signatures
        $interfaceMethods = [
            'connect' => ['string', 'int', 'int', 'array'],
            'close' => [],
            'isOpen' => [],
            'write' => ['string'],
            'readLine' => ['int'],
            'waitReadable' => ['int'],
            'enableCrypto' => ['int', 'int'],
            'getMetadata' => [],
            'setReadTimeout' => ['int'],
            'getConnectError' => [],
            'getLastWarning' => [],
            'clearLastWarning' => [],
            'getResource' => [],
            'setErrorHandler' => ['callable'],
            'setProxyProtocolHeader' => ['string'],
        ];

        foreach ($interfaceMethods as $method => $paramTypes) {
            $this->assertTrue(
                method_exists($transport, $method),
                "Method $method must exist"
            );
        }
    }
}
```

## Backward Compatibility

### Current Usage Must Continue Working

```php
// Sync usage
$mail = new PHPMailer(true);
$mail->isSMTP();
$mail->send();

// Async with Workerman
$transport = new WorkermanTransport();
$transport->connect('smtp.example.com', 587, 30);
$transport->write("QUIT\r\n");
$transport->close();

// StreamTransport fallback
$transport = new StreamTransport();
$transport->connect('smtp.example.com', 587, 30);
$transport->close();
```

## Implementation Phases

### Phase 1: Base Setup (Days 1-2)
- [ ] Branch from `php-7.4` branch (not master - reuse work)
- [ ] Or branch from master and apply all 7.4 changes plus these
- [ ] Update composer.json to `^7.1`

### Phase 2: Remove Void Returns (Days 3-4)

**Files to update:**
- [ ] `src/Async/Transport.php` - Interface
- [ ] `src/Async/WorkermanTransport.php`
- [ ] `src/Async/WorkermanConnectionTransport.php`
- [ ] `src/Async/StreamTransport.php`

### Phase 3: Remove Nullable Types (Days 5-6)

**Method parameters:**
- [ ] `setErrorHandler(?callable $handler)` → `setErrorHandler($handler = null)`
- [ ] `setProxyProtocolHeader(?string $bytes)` → `setProxyProtocolHeader($bytes = null)`

**Properties:**
- [ ] `private ?Closure $errorSink = null;` → `private $errorSink = null;`
- [ ] `private ?string $proxyProtocolHeader = null;` → `private $proxyProtocolHeader = null;`

### Phase 4: Remove Typed Properties (Day 7)
- [ ] Apply same typed property removal as 7.4 branch

### Phase 5: Update Async Infrastructure (Days 8-9)
- [ ] Use CallbackRunner/React\EventLoop
- [ ] Test async flows

### Phase 6: Testing (Days 10-11)
- [ ] Run full test suite
- [ ] Verify all tests pass
- [ ] Add PHP 7.1 to CI

### Phase 7: Documentation (Day 12)
- [ ] Update README
- [ ] Document limitations

## Files Summary

### Create (New Files)
```
src/Async/CallbackRunner.php
test/Async/CallbackRunnerTest.php
test/Async/Php71CompatibilityTest.php
```

### Modify (Main Changes)
```
src/Async/Transport.php           # Remove void, nullable
src/Async/WorkermanTransport.php  # Remove void, nullable, typed props
src/Async/WorkermanConnectionTransport.php # Remove void, nullable, typed props
src/Async/StreamTransport.php     # Remove void, nullable, typed props
src/Async/SmtpConnectionPool.php  # Remove typed props
composer.json                     # PHP ^7.1
phpunit.xml.dist                  # Add PHP 7.1
```

### Keep Unchanged
```
src/Async/TransportFactory.php
src/PHPMailer.php
src/SMTP.php
```

## Effort Estimate

| Task | Time | Complexity |
|------|------|------------|
| Base setup | 1 day | Low |
| Remove void returns | 1.5 days | Medium |
| Remove nullable types | 1.5 days | Medium |
| Remove typed properties | 2 days | Low |
| Async infrastructure | 2 days | High |
| Testing & CI | 2 days | Medium |
| Documentation | 1 day | Low |
| **Total** | **11 days** | - |

## Verification Commands

```bash
# Check for remaining void returns
rg ': void' src/Async/

# Check for remaining nullable types
rg '\?\w+ \$' src/Async/

# Check for typed properties
rg 'private (string|int|bool|array|Closure)\s+\$' src/Async/

# Run tests
composer test

# Verify no PHP 7.2+ syntax
php -l src/Async/Transport.php
```

## Known Limitations on PHP 7.1

1. **No typed properties** - More verbose code, IDE support degraded
2. **No nullable types** - Must use docblocks for optional params
3. **No void returns** - Cannot distinguish void methods by signature
4. **Callback-based async** - More complex than Fiber-based

## Upgrade Path

If a user wants to upgrade from PHP 7.1 to PHP 7.4 or 8.0:

```bash
# In their composer.json, they can loosen constraint
"mailbaby/phpmailer-async-proxy-workerman": "^8.0"  # For PHP 8.0
"mailbaby/phpmailer-async-proxy-workerman": "^7.4"  # For PHP 7.4
"mailbaby/phpmailer-async-proxy-workerman": "^7.1"  # For PHP 7.1
```
