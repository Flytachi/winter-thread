# 2. Installation and Requirements

To use Winter Thread, your PHP environment must meet a few key requirements.

## System Requirements

-   **PHP Version**: PHP 8.1 or higher.
-   **Operating System**: A POSIX-compliant operating system (e.g., Linux, macOS, BSD). This library relies on POSIX signals and process control functions and is **not compatible with Windows**.
-   **PHP Extensions**:
    -   `ext-pcntl`: The Process Control extension is essential for process forking and management.
    -   `ext-posix`: The POSIX extension is required for sending signals (`posix_kill`) and identifying processes.

You can check for installed extensions by running `php -m` in your terminal.

## Optional Dependencies

### `opis/closure` for Closure Serialization

To execute anonymous classes or `Closure` objects in a background thread, you need to install the `opis/closure` library. It provides a secure way to serialize and deserialize executable code.

```bash
composer require opis/closure
```

When using opis/closure, you must also define a secret key. 
This key is used to sign the serialized closure, preventing remote code execution vulnerabilities. 
Define it once at the beginning of your application's lifecycle.

```php
define('WINTER_THREAD_SECRET', 'your-secret-key');
```

## Project Structure
The library assumes a standard Composer project layout. 
The internal runner script is located in the library's root directory and is automatically found.
If you need to customize the path to the runner script (for example, for deep framework integration), 
you can use the Thread::bindRunner() method:

```php
use Flytachi\Winter\Thread\Thread;

// Call this once during your application's bootstrap phase.
Thread::bindRunner('/path/to/your/custom/runner');
```