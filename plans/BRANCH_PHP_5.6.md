# Plan: PHP 5.6 Compatibility Branch

## Branch Name
`php-5.6`

## Goal
Create a branch targeting PHP 5.6 that provides full backward compatibility with the current API. PHP 5.6 users will get async via a callback-based approach using ReactPHP components that support PHP 5.6+.

## Summary of Changes

| Category | Change |
|----------|--------|
| PHP Minimum | `^5.6` (from `^8.1`) |
| Fibers | Not available - use callback-based async |
| Typed Properties | Remove (PHP 7.4 feature) |
| Return Type Declarations | Remove (PHP 7.0 feature) |
| Nullable Types | Remove (PHP 7.1 feature) |
| Void Return Types | Remove (PHP 7.1 feature) |
| Namespaces with Group Use | Remove (PHP 7.0 feature, but common) |
| Scalar Type Hints | Remove (PHP 7.0 feature) |
| Revolt EventLoop | Replace with React\EventLoop (PHP 5.6 compatible) |
| Workerman | Downgrade from `^5.1` to `^3.5` (PHP 5.3+) |

## PHP Version Feature Comparison

| Feature | 5.6 | 7.0 | 7.1 | 7.4 | 8.0+ |
|---------|-----|-----|-----|-----|------|
| Type hints (scalar) | ❌ | ✅ | ✅ | ✅ | ✅ |
| Return types | ❌ | ✅ | ✅ | ✅ | ✅ |
| Nullable types (?T) | ❌ | ❌ | ✅ | ✅ | ✅ |
| Void returns | ❌ | ❌ | ✅ | ✅ | ✅ |
| Typed properties | ❌ | ❌ | ❌ | ✅ | ✅ |
| Fibers | ❌ | ❌ | ❌ | ❌ | ✅ |

## Key Architectural Changes

### 1. Remove All Type Declarations

Every method signature needs to be converted:

```php
// OLD (PHP 7.0+)
public function connect(string $host, int $port, int $timeout, array $contextOptions = []): bool
public function close(): void
public function write(string $data): int|false
private ?Closure $errorSink = null;

// NEW (PHP 5.6 compatible)
/**
 * @param string $host
 * @param int $port
 * @param int $timeout
 * @param array $contextOptions
 * @return bool
 */
public function connect($host, $port, $timeout, $contextOptions = [])
public function close()
/**
 * @param string $data
 * @return int|false
 */
public function write($data)
/** @var Closure|null */
private $errorSink = null;
```

### 2. Replace Workerman 5.x with Workerman 3.x

Workerman 3.x supports PHP 5.3+ and uses callback-based async:

```json
// composer.json
{
    "require": {
        "php": "^5.6",
        "workerman/workerman": "^3.5",
        "react/event-loop": "^0.4",
        "react/promise": "^2.0"
    }
}
```

**Note:** Workerman 3.x has a different API than 5.x. `Worker::run()` is similar but connection handling uses callbacks.

### 3. Async Strategy for PHP 5.6

Without Fibers or Generators as first-class async, use:

1. **React\EventLoop** - Event loop for non-blocking I/O
2. **React\Promise** - Promise-based async patterns
3. **Workerman 3.x callbacks** - `onConnect`, `onMessage`, etc.

## Files to Create

| File | Purpose |
|------|---------|
| `src/Async/PromiseTransport.php` | Transport using React\Promise |
| `src/Async/CallbackRunner.php` | Event-loop runner for callbacks |
| `src/Async/Workerman35Transport.php` | Transport for Workerman 3.x |
| `test/Async/PromiseTransportTest.php` | Tests |
| `test/Async/Php56CompatibilityTest.php` | PHP 5.6 specific tests |

## Detailed File Changes

### A. `src/Async/Transport.php` (Interface)

**Complete rewrite needed:**

```php
<?php
/**
 * PHPMailer-Async-Proxy-Workerman — Transport interface.
 *
 * PHP 5.6 compatible version.
 */
namespace PHPMailer\PHPMailer\Async;

/**
 * Byte-level transport contract.
 *
 * Compatible with PHP 5.6 using only:
 * - No scalar type hints
 * - No return type declarations
 * - No nullable types
 * - Docblock annotations for IDE support
 */
interface Transport
{
    /**
     * Open a TCP connection.
     *
     * @param string $host Hostname or IP.
     * @param int $port TCP port.
     * @param int $timeout Connect timeout, seconds.
     * @param array $contextOptions Context options.
     * @return bool True on success, false on failure.
     */
    public function connect($host, $port, $timeout, $contextOptions = []);

    /**
     * Close the connection.
     */
    public function close();

    /**
     * Check if connection is open.
     *
     * @return bool
     */
    public function isOpen();

    /**
     * Write raw bytes.
     *
     * @param string $data Bytes to write.
     * @return int|false Bytes written or false on failure.
     */
    public function write($data);

    /**
     * Read a single line.
     *
     * @param int $maxLength Maximum bytes to read.
     * @return string Line including terminator, or '' on EOF.
     */
    public function readLine($maxLength);

    /**
     * Wait for input.
     *
     * @param int $timeoutSeconds Timeout in seconds.
     * @return bool|null true if readable, false on timeout, null on error.
     */
    public function waitReadable($timeoutSeconds);

    /**
     * Upgrade to TLS.
     *
     * @param int $cryptoMethod STREAM_CRYPTO_METHOD_* bitmask.
     * @param int $timeout Handshake timeout.
     * @return bool True on success.
     */
    public function enableCrypto($cryptoMethod, $timeout = 30);

    /**
     * Get connection metadata.
     *
     * @return array
     */
    public function getMetadata();

    /**
     * Set read timeout.
     *
     * @param int $seconds Timeout in seconds.
     */
    public function setReadTimeout($seconds);

    /**
     * Get connection error.
     *
     * @return array{errno: int, errstr: string}
     */
    public function getConnectError();

    /**
     * Get last warning.
     *
     * @return array{errno: int, errstr: string, errfile: string, errline: int}
     */
    public function getLastWarning();

    /**
     * Clear last warning.
     */
    public function clearLastWarning();

    /**
     * Get underlying resource.
     *
     * @return resource|null
     */
    public function getResource();

    /**
     * Set error handler.
     *
     * @param callable|null $handler Error handler callback.
     */
    public function setErrorHandler($handler = null);

    /**
     * Set PROXY protocol header.
     *
     * @param string|null $bytes PROXY protocol header bytes.
     */
    public function setProxyProtocolHeader($bytes = null);
}
```

### B. `src/Async/StreamTransport.php`

**Complete rewrite needed:**

```php
<?php
/**
 * PHP 5.6 compatible version.
 */
namespace PHPMailer\PHPMailer\Async;

use Closure;

/**
 * Blocking stream transport - mirrors upstream PHPMailer SMTP socket flow.
 */
final class StreamTransport implements Transport
{
    /** @var resource|null */
    private $socket = null;

    /** @var int */
    private $readTimeout = 30;

    /** @var Closure|null */
    private $errorSink = null;

    /** @var array */
    private $connectError = ['errno' => 0, 'errstr' => ''];

    /** @var array */
    private $lastWarning = ['errno' => 0, 'errstr' => '', 'errfile' => '', 'errline' => 0];

    /** @var string|null */
    private $proxyProtocolHeader = null;

    public function setErrorHandler($handler = null)
    {
        $this->errorSink = $handler === null ? null : $handler;
    }

    public function setProxyProtocolHeader($bytes = null)
    {
        $this->proxyProtocolHeader = $bytes;
    }

    public function connect($host, $port, $timeout, $contextOptions = [])
    {
        // Same logic as current StreamTransport but without types
        // ...
    }

    public function close()
    {
        // ...
    }

    // ... all other methods without type declarations
}
```

### C. `src/Async/Workerman35Transport.php`

**New file for Workerman 3.x compatibility:**

```php
<?php
/**
 * PHPMailer-Async-Proxy-Workerman — Workerman 3.x async transport.
 *
 * For PHP 5.6 compatibility.
 */
namespace PHPMailer\PHPMailer\Async;

use Workerman\Worker;
use Workerman\Connection\TcpConnection;

/**
 * Async transport using Workerman 3.x callbacks.
 *
 * Note: Workerman 3.x uses callback-based async, not Fibers.
 * This is less elegant than the PHP 8.1+ Fiber-based approach
 * but provides the same non-blocking behavior.
 */
final class Workerman35Transport implements Transport
{
    /** @var TcpConnection|null */
    private $connection = null;

    /** @var string */
    private $readBuffer = '';

    /** @var int */
    private $readTimeout = 30;

    /** @var Closure|null */
    private $errorSink = null;

    /** @var array */
    private $connectError = ['errno' => 0, 'errstr' => ''];

    /** @var array */
    private $lastWarning = ['errno' => 0, 'errstr' => '', 'errfile' => '', 'errline' => 0];

    /** @var string|null */
    private $proxyProtocolHeader = null;

    /** @var bool */
    private $eof = false;

    public function setErrorHandler($handler = null)
    {
        $this->errorSink = $handler;
    }

    public function setProxyProtocolHeader($bytes = null)
    {
        $this->proxyProtocolHeader = $bytes;
    }

    public function connect($host, $port, $timeout, $contextOptions = [])
    {
        // Workerman 3.x async connect using callbacks
        $deferred = new \React\Promise\Deferred();

        $this->connection = new TcpConnection([
            'host' => $host,
            'port' => $port,
            'timeout' => $timeout,
        ]);

        $self = $this;

        $this->connection->onConnect = function($conn) use ($self, &$deferred) {
            if ($self->proxyProtocolHeader !== null) {
                $conn->send($self->proxyProtocolHeader);
            }
            $deferred->resolve(true);
        };

        $this->connection->onError = function($conn, $code, $msg) use ($self, &$deferred) {
            $self->connectError = ['errno' => $code, 'errstr' => $msg];
            $deferred->reject(new \RuntimeException($msg, $code));
        };

        $this->connection->onClose = function($conn) use ($self) {
            $self->eof = true;
        };

        try {
            $this->connection->connect();
        } catch (\Exception $e) {
            $this->connectError = ['errno' => $e->getCode(), 'errstr' => $e->getMessage()];
            return false;
        }

        // Wait for connection with timeout
        // Use event loop for async wait
        return $this->waitForConnect($timeout);
    }

    private function waitForConnect($timeout)
    {
        $loop = \React\EventLoop\Loop::get();
        $elapsed = 0;
        $interval = 0.01; // 10ms

        while ($elapsed < $timeout) {
            if ($this->connection !== null && $this->connection->isConnected()) {
                return true;
            }
            usleep($interval * 1000000);
            $elapsed += $interval;
            $loop->tick();
        }

        $this->connectError = ['errno' => 110, 'errstr' => 'Connection timed out'];
        return false;
    }

    public function close()
    {
        if ($this->connection !== null) {
            $this->connection->close();
            $this->connection = null;
        }
    }

    public function isOpen()
    {
        return $this->connection !== null && $this->connection->isConnected();
    }

    public function write($data)
    {
        if (!$this->isOpen()) {
            return false;
        }
        return $this->connection->send($data);
    }

    public function readLine($maxLength)
    {
        // For sync reading, would use $this->connection->read()
        // For async, use callbacks
        $line = '';
        $charsRead = 0;

        while ($charsRead < $maxLength) {
            $char = $this->connection->read(1);
            if ($char === '' || $char === null) {
                break;
            }
            $line .= $char;
            $charsRead++;
            if ($char === "\n") {
                break;
            }
        }

        return $line;
    }

    public function waitReadable($timeoutSeconds)
    {
        // In Workerman 3.x, this is handled by event callbacks
        // For sync compatibility, we poll
        return true;
    }

    public function enableCrypto($cryptoMethod, $timeout = 30)
    {
        if (!$this->isOpen()) {
            return false;
        }

        $this->connection->enableCrypto = true;
        return true;
    }

    public function getMetadata()
    {
        if ($this->connection === null) {
            return ['timed_out' => false, 'eof' => true, 'blocked' => false];
        }
        return ['timed_out' => false, 'eof' => $this->eof, 'blocked' => false];
    }

    public function setReadTimeout($seconds)
    {
        $this->readTimeout = $seconds;
    }

    public function getConnectError()
    {
        return $this->connectError;
    }

    public function getLastWarning()
    {
        return $this->lastWarning;
    }

    public function clearLastWarning()
    {
        $this->lastWarning = ['errno' => 0, 'errstr' => '', 'errfile' => '', 'errline' => 0];
    }

    public function getResource()
    {
        // Workerman 3.x doesn't expose raw socket
        return null;
    }
}
```

## Files to Modify

### Complete Rewrite Required

| File | Changes |
|------|---------|
| `src/Async/Transport.php` | Remove all type declarations |
| `src/Async/StreamTransport.php` | Remove all type declarations |
| `src/Async/WorkermanTransport.php` | PHP 5.6 incompatible - rewrite needed |
| `src/Async/WorkermanConnectionTransport.php` | PHP 5.6 incompatible - rewrite needed |
| `src/Async/SmtpConnectionPool.php` | Remove all type declarations |

### New Files

| File | Purpose |
|------|---------|
| `src/Async/Workerman35Transport.php` | Workerman 3.x async transport |
| `src/Async/CallbackRunner.php` | Callback-based event loop runner |

### Keep Unchanged

```
src/PHPMailer.php          # Should work if SMTP is compatible
src/SMTP.php               # May need modifications
src/POP3.php               # Should work
```

## Test Strategy

### New Test Files

| Test File | Coverage |
|-----------|----------|
| `test/Async/PromiseTransportTest.php` | React\Promise based transport |
| `test/Async/Workerman35TransportTest.php` | Workerman 3.x transport |
| `test/Async/Php56CompatibilityTest.php` | PHP 5.6 syntax verification |
| `test/Async/SyncParityTest.php` | Verify sync behavior matches blocking |

### Compatibility Tests

```php
class Php56CompatibilityTest extends TestCase
{
    public function testNoTypeDeclarations(): void
    {
        $classes = [
            'PHPMailer\PHPMailer\Async\Transport',
            'PHPMailer\PHPMailer\Async\StreamTransport',
            'PHPMailer\PHPMailer\Async\Workerman35Transport',
        ];

        foreach ($classes as $class) {
            $reflection = new ReflectionClass($class);

            // Check methods
            foreach ($reflection->getMethods() as $method) {
                $returnType = $method->getReturnType();
                $this->assertNull(
                    $returnType,
                    "$class::{$method->getName()} should not have return type"
                );

                // Check parameters
                foreach ($method->getParameters() as $param) {
                    $type = $param->getType();
                    $this->assertNull(
                        $type,
                        "$class::{$method->getName()}(\${$param->getName()}) should not have type"
                    );
                }
            }

            // Check properties
            foreach ($reflection->getProperties() as $property) {
                $type = $property->getType();
                $this->assertNull(
                    $type,
                    "$class::\${$property->getName()} should not have type"
                );
            }
        }
    }

    public function testPhp56Syntax(): void
    {
        // Verify files parse correctly with PHP 5.6 parser
        $files = glob('src/Async/*.php');
        foreach ($files as $file) {
            $result = exec("php -l " . escapeshellarg($file));
            $this->assertStringContainsString('No syntax errors', $result);
        }
    }

    public function testStreamTransportBlockingBehavior(): void
    {
        $transport = new StreamTransport();
        // Connect to localhost:25 (or skip if not available)
        $this->assertTrue(
            $transport->connect('127.0.0.1', 25, 5, []),
            'StreamTransport should connect synchronously'
        );
        $transport->close();
    }
}
```

## Backward Compatibility

### Current Usage Must Continue Working

```php
// Sync usage on PHP 5.6
$mail = new PHPMailer(true);
$mail->isSMTP();
$mail->Host = 'smtp.example.com';
$mail->SMTPAuth = true;
$mail->Username = 'user';
$mail->Password = 'pass';
$mail->send(); // Must work

// Async usage (callback-based on PHP 5.6)
$transport = new Workerman35Transport();
$transport->connect('smtp.example.com', 587, 30);
// ... use callbacks for async I/O
```

## Dependency Changes

### composer.json

```json
{
    "name": "detain/phpmailer-async-proxy-workerman",
    "type": "library",
    "description": "Async PHPMailer for PHP 5.6+ with PROXY Protocol support",
    "require": {
        "php": "^5.6",
        "ext-ctype": "*",
        "ext-filter": "*",
        "ext-hash": "*",
        "workerman/workerman": "^3.5",
        "react/event-loop": "^0.4",
        "react/promise": "^2.0"
    },
    "replace": {
        "phpmailer/phpmailer": "^7.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^5.7",
        "squizlabs/php_codesniffer": "^3.0"
    }
}
```

**Note:** PHPUnit 5.7 supports PHP 5.6

## Implementation Phases

### Phase 1: Core Interface (Days 1-3)
- [ ] Create `src/Async/Transport.php` (completely type-free)
- [ ] Create `src/Async/StreamTransport.php` (type-free, same logic)
- [ ] Create `test/Async/StreamTransportTest.php`

### Phase 2: Async Infrastructure (Days 4-6)
- [ ] Create `src/Async/Workerman35Transport.php`
- [ ] Create `src/Async/CallbackRunner.php`
- [ ] Create `test/Async/Workerman35TransportTest.php`

### Phase 3: SMTP Integration (Days 7-9)
- [ ] Modify `src/SMTP.php` to work with new Transport interface
- [ ] Remove type declarations from SMTP.php
- [ ] Test SMTP integration

### Phase 4: Connection Pool (Days 10-11)
- [ ] Create `src/Async/SmtpConnectionPool.php` (type-free)
- [ ] Create `test/Async/SmtpConnectionPoolTest.php`

### Phase 5: Transport Factory (Days 12-13)
- [ ] Create `src/Async/TransportFactory.php` (type-free)
- [ ] Create `test/Async/TransportFactoryTest.php`

### Phase 6: Full Testing (Days 14-17)
- [ ] Run full test suite on PHP 5.6
- [ ] Verify all existing tests pass or adapt
- [ ] Add PHP 5.6 to CI

### Phase 7: Documentation (Day 18)
- [ ] Update README with PHP 5.6 support
- [ ] Document callback-based async pattern
- [ ] Add upgrade guide

## Files Summary

### Create (New Files)
```
src/Async/Transport.php           # Type-free interface
src/Async/StreamTransport.php     # Type-free blocking transport
src/Async/Workerman35Transport.php # Workerman 3.x async transport
src/Async/CallbackRunner.php      # Event loop callback runner
src/Async/SmtpConnectionPool.php  # Type-free connection pool
src/Async/TransportFactory.php    # Type-free factory
test/Async/StreamTransportTest.php
test/Async/Workerman35TransportTest.php
test/Async/Php56CompatibilityTest.php
test/Async/SyncParityTest.php
test/Async/SmtpConnectionPoolTest.php
test/Async/TransportFactoryTest.php
```

### Modify
```
src/SMTP.php                      # Remove types, integrate Transport
src/PHPMailer.php                 # May need minor adjustments
composer.json                     # PHP ^5.6, react/*, workerman ^3.5
phpunit.xml.dist                  # PHP 5.6 compatible config
```

### Delete (PHP 8.1+ Only)
```
src/Async/FiberRunner.php         # Requires Fibers - PHP 8.1+
src/Async/WorkermanTransport.php  # Requires Fibers - PHP 8.1+
src/Async/WorkermanConnectionTransport.php # Requires Fibers - PHP 8.1+
```

## Effort Estimate

| Task | Time | Complexity |
|------|------|------------|
| Core interface | 3 days | High |
| Async infrastructure | 3 days | Very High |
| SMTP integration | 3 days | High |
| Connection pool | 2 days | Medium |
| Transport factory | 2 days | Medium |
| Testing & CI | 4 days | High |
| Documentation | 1 day | Low |
| **Total** | **18 days** | - |

## Verification Commands

```bash
# Check for any remaining type declarations
rg 'private (\w+ )?\$' src/Async/ --glob='*.php'
rg 'public function \w+\([^)]+\):' src/Async/ --glob='*.php'

# Verify PHP 5.6 syntax
find src/Async -name '*.php' -exec php -l {} \;

# Run tests (PHPUnit 5.7)
./vendor/bin/phpunit

# Manual verification
php -r "
require 'vendor/autoload.php';
echo 'StreamTransport: ';
\$t = new PHPMailer\PHPMailer\Async\StreamTransport();
echo 'OK' . PHP_EOL;
echo 'Workerman35Transport: ';
\$t = new PHPMailer\PHPMailer\Async\Workerman35Transport();
echo 'OK' . PHP_EOL;
"
```

## Known Limitations on PHP 5.6

1. **No async/await** - Must use callbacks
2. **No type safety** - Runtime errors more likely
3. **No return types** - IDE support limited
4. **No nullable types** - Docblocks required for optional params
5. **Performance** - Callback-based async has overhead

## Difference from Higher PHP Versions

| Feature | 5.6 | 7.4 | 8.0 | 8.1+ |
|---------|-----|-----|-----|------|
| Async I/O | Callback-based | Callback/Generator | Generator | Fiber |
| Type Safety | None | Docblocks | Partial | Full |
| Performance | Low | Medium | High | Highest |
| Code Clarity | Low | Medium | High | Highest |

## Migration Path

```
PHP 5.6 (this branch)
    ↓
PHP 7.4 (add types back gradually)
    ↓
PHP 8.0 (add return types)
    ↓
PHP 8.1 (enable Fibers)
```

Users can upgrade by switching branches/composer versions.
