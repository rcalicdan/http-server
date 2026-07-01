<?php

declare(strict_types=1);

use Hibla\HttpServer\ClusterOptions;
use Hibla\HttpServer\HttpServer;
use Hibla\HttpServer\Message\Request;
use Hibla\HttpServer\Message\Response;
use Hibla\Stream\Interfaces\ReadableStreamInterface;

use function Hibla\await;
use function Hibla\delay;

function runConfigTest(callable $configureServer, callable $clientActions): void
{
    if (PHP_OS_FAMILY === 'Windows') {
        test()->markTestSkipped('Process forking is not supported on Windows.');
    }

    $port = random_int(10000, 15000);
    $address = "127.0.0.1:{$port}";

    $pid = pcntl_fork();
    expect($pid)->not->toBe(-1);

    if ($pid === 0) {
        try {
            $configureServer($address);
            exit(0);
        } catch (\Throwable $e) {
            fwrite(STDERR, "Child Error: " . $e->getMessage());
            exit(1);
        }
    }

    try {
        usleep(100000); // 100ms boot allowance
        $fp = stream_socket_client("tcp://{$address}", $errno, $errstr, 1.0);
        expect($fp)->not->toBeFalse();

        $clientActions($fp);

        if (is_resource($fp)) {
            fclose($fp);
        }
    } finally {
        posix_kill($pid, SIGTERM);
        pcntl_waitpid($pid, $status);
    }
}

function runClusterConfigTest(callable $configureServer, callable $clientActions): void
{
    if (PHP_OS_FAMILY === 'Windows') {
        test()->markTestSkipped('Clustered process forking is not supported on Windows.');
    }

    $port = random_int(10000, 15000);
    $address = "127.0.0.1:{$port}";

    $pid = pcntl_fork();
    expect($pid)->not->toBe(-1);

    if ($pid === 0) {
        posix_setpgid(0, 0);
        try {
            $configureServer($address);
            exit(0);
        } catch (\Throwable $e) {
            fwrite(STDERR, "Cluster Master Error: " . $e->getMessage());
            exit(1);
        }
    }

    try {
        usleep(250000);
        $clientActions($address);
    } finally {
        posix_kill(-$pid, SIGTERM);
        pcntl_waitpid($pid, $status);
    }
}

describe("True Integration Test", function () {
    it('starts the server and gracefully drains in-flight requests on SIGTERM', function () {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Process forking and signal trapping are not supported on Windows.');
        }

        $port = random_int(10000, 15000);
        $address = "127.0.0.1:{$port}";

        $pid = pcntl_fork();
        expect($pid)->not->toBe(-1);

        if ($pid === 0) {
            try {
                HttpServer::create($address)
                    ->withoutLogging()
                    ->withGracefulShutdownTimeout(1.0)
                    ->start(function () {
                        await(delay(0.1));

                        return Response::plaintext('Drained Safely');
                    });
                exit(0);
            } catch (\Throwable $e) {
                fwrite(STDERR, "Child Error: " . $e->getMessage());
                exit(1);
            }
        }

        try {
            usleep(50000);

            $fp = stream_socket_client("tcp://{$address}", $errno, $errstr, 1.0);
            expect($fp)->not->toBeFalse();

            fwrite($fp, "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");

            usleep(20000);
            posix_kill($pid, SIGTERM);

            $response = '';
            while (!feof($fp)) {
                $response .= fread($fp, 1024);
            }
            fclose($fp);

            expect($response)->toContain('HTTP/1.1 200 OK')
                ->and($response)->toContain('Drained Safely');

            pcntl_waitpid($pid, $status);
            expect(pcntl_wexitstatus($status))->toBe(0);
        } catch (\Throwable $e) {
            posix_kill($pid, SIGKILL);
            throw $e;
        }
    });
});

describe("Clustered Integration Test", function () {
    it('starts the clustered server and gracefully drains in-flight requests on SIGTERM', function () {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Process forking and signal trapping are not supported on Windows.');
        }

        $port = random_int(10000, 15000);
        $address = "127.0.0.1:{$port}";

        $pid = pcntl_fork();
        expect($pid)->not->toBe(-1);

        if ($pid === 0) {
            posix_setpgid(0, 0);

            try {
                HttpServer::create($address)
                    ->withCluster(2, ClusterOptions::make()->withWorkerRestartLimit(10))
                    ->withoutLogging()
                    ->withGracefulShutdownTimeout(1.0)
                    ->start(function () {
                        await(delay(0.1));

                        return Response::plaintext('Drained Safely');
                    });
                exit(0);
            } catch (\Throwable $e) {
                fwrite(STDERR, "Master Cluster Error: " . $e->getMessage());
                exit(1);
            }
        }

        try {
            usleep(150000);

            $fp = stream_socket_client("tcp://{$address}", $errno, $errstr, 1.0);
            expect($fp)->not->toBeFalse();

            fwrite($fp, "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");

            usleep(20000);
            posix_kill(-$pid, SIGTERM);

            $response = '';
            while (!feof($fp)) {
                $response .= fread($fp, 1024);
            }
            fclose($fp);

            expect($response)->toContain('HTTP/1.1 200 OK')
                ->and($response)->toContain('Drained Safely');

            pcntl_waitpid($pid, $status);
            expect(pcntl_wexitstatus($status))->toBe(0);
        } catch (\Throwable $e) {
            posix_kill(-$pid, SIGKILL);
            throw $e;
        }
    });
});

describe("Server Configuration Integration Tests", function () {

    it('enforces max body size limit', function () {
        runConfigTest(
            function ($address) {
                HttpServer::create($address)
                    ->withoutLogging()
                    ->withMaxBodySize(5)
                    ->start(fn() => Response::plaintext('OK'));
            },
            function ($fp) {
                fwrite($fp, "POST / HTTP/1.1\r\nHost: localhost\r\nContent-Length: 10\r\n\r\n0123456789");

                $response = stream_get_contents($fp);
                expect($response)->toContain('413 Payload Too Large');
            }
        );
    });

    it('enforces maximum header count limit', function () {
        runConfigTest(
            function ($address) {
                HttpServer::create($address)
                    ->withoutLogging()
                    ->withHeaderLimits(8192, 2)
                    ->start(fn() => Response::plaintext('OK'));
            },
            function ($fp) {
                fwrite($fp, "GET / HTTP/1.1\r\nHost: localhost\r\nX-One: 1\r\nX-Two: 2\r\n\r\n");

                $response = stream_get_contents($fp);
                expect($response)->toContain('431 Request Header Fields Too Large');
            }
        );
    });

    it('enforces header timeout for slowloris attacks', function () {
        runConfigTest(
            function ($address) {
                HttpServer::create($address)
                    ->withoutLogging()
                    ->withHeaderTimeout(0.5)
                    ->start(fn() => Response::plaintext('OK'));
            },
            function ($fp) {
                fwrite($fp, "GET / HTTP/1.1\r\nHost: localhost\r\n");

                usleep(600000);

                $response = stream_get_contents($fp);
                expect($response)->toContain('408 Request Timeout');
            }
        );
    });

    it('closes idle connections after keep-alive timeout expires', function () {
        runConfigTest(
            function ($address) {
                HttpServer::create($address)
                    ->withoutLogging()
                    ->withKeepAliveTimeout(0.4)
                    ->start(fn() => Response::plaintext('OK'));
            },
            function ($fp) {
                fwrite($fp, "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");

                $response = fread($fp, 1024);
                expect($response)->toContain('200 OK');

                usleep(500000);

                fread($fp, 1);
                expect(feof($fp))->toBeTrue();
            }
        );
    });

    it('executes the onStart lifecycle hook before accepting connections', function () {
        $lockFile = sys_get_temp_dir() . '/hibla_onstart_test.lock';
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }

        runConfigTest(
            function ($address) use ($lockFile) {
                HttpServer::create($address)
                    ->withoutLogging()
                    ->onStart(fn() => file_put_contents($lockFile, 'booted_successfully'))
                    ->start(fn() => Response::plaintext('OK'));
            },
            function ($fp) use ($lockFile) {
                fwrite($fp, "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");
                $response = fread($fp, 1024);

                expect($response)->toContain('200 OK');
                expect(file_exists($lockFile))->toBeTrue();
                expect(file_get_contents($lockFile))->toBe('booted_successfully');

                if (file_exists($lockFile)) {
                    unlink($lockFile);
                }
            }
        );
    });

    it('delivers request bodies as streams when enabled', function () {
        runConfigTest(
            function ($address) {
                HttpServer::create($address)
                    ->withoutLogging()
                    ->withStreamingRequests(true)
                    ->start(function (Request $request) {
                        $body = $request->getBody();
                        $isStream = $body instanceof ReadableStreamInterface;

                        return Response::plaintext($isStream ? 'STREAM_DETECTED' : 'STRING_DETECTED');
                    });
            },
            function ($fp) {
                fwrite($fp, "POST / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\nContent-Length: 4\r\n\r\nbody");

                $response = stream_get_contents($fp);
                expect($response)->toContain('STREAM_DETECTED');
            }
        );
    });
});

describe("Clustered Mode Advanced Integration Tests", function () {

    it('automatically recovers and respawns workers when they crash', function () {
        runClusterConfigTest(
            function ($address) {
                HttpServer::create($address)
                    ->withCluster(1)
                    ->withoutLogging()
                    ->start(function (Request $request) {
                        if ($request->getUri() === '/suicide') {
                            exit(1);
                        }

                        return Response::plaintext('ALIVE_REPLACEMENT');
                    });
            },
            function ($address) {
                $fp = stream_socket_client("tcp://{$address}", $errno, $errstr, 1.0);
                fwrite($fp, "GET /suicide HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");

                $response1 = stream_get_contents($fp);
                fclose($fp);
                expect($response1)->toBe('');

                usleep(150000);

                $fp2 = stream_socket_client("tcp://{$address}", $errno, $errstr, 1.0);
                fwrite($fp2, "GET /status HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");

                $response2 = stream_get_contents($fp2);
                fclose($fp2);

                expect($response2)->toContain('200 OK')
                    ->and($response2)->toContain('ALIVE_REPLACEMENT');
            }
        );
    });

    it('enforces custom worker memory limits', function () {
        runClusterConfigTest(
            function ($address) {
                $options = ClusterOptions::make()->withWorkerMemoryLimit('20M');

                HttpServer::create($address)
                    ->withCluster(1, $options)
                    ->withoutLogging()
                    ->start(function (Request $request) {
                        if ($request->getUri() === '/bloat') {
                            $data = str_repeat('X', 30 * 1024 * 1024);
                            return Response::plaintext('Bloated to ' . strlen($data));
                        }

                        return Response::plaintext('STABLE');
                    });
            },
            function ($address) {
                $fp = stream_socket_client("tcp://{$address}", $errno, $errstr, 1.0);
                fwrite($fp, "GET /bloat HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");

                $response1 = stream_get_contents($fp);
                fclose($fp);

                expect($response1)->not->toContain('Bloated to');

                usleep(150000);

                $fp2 = stream_socket_client("tcp://{$address}", $errno, $errstr, 1.0);
                fwrite($fp2, "GET /status HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");

                $response2 = stream_get_contents($fp2);
                fclose($fp2);

                expect($response2)->toContain('200 OK')
                    ->and($response2)->toContain('STABLE');
            }
        );
    });

    it('executes custom bootstrap files and callbacks inside the child worker context', function () {
        $bootstrapFile = sys_get_temp_dir() . '/hibla_cluster_bootstrap.php';
        file_put_contents($bootstrapFile, "<?php define('BOOTSTRAP_FILE_RAN', 'file_yes');");

        runClusterConfigTest(
            function ($address) use ($bootstrapFile) {
                $options = ClusterOptions::make()
                    ->withClusterBootstrap($bootstrapFile, function (string $file) {
                        require $file; 
                        define('BOOTSTRAP_CALLBACK_RAN', 'callback_yes');
                    });

                HttpServer::create($address)
                    ->withCluster(1, $options)
                    ->withoutLogging()
                    ->start(function () {
                        $f = defined('BOOTSTRAP_FILE_RAN') ? BOOTSTRAP_FILE_RAN : 'file_no';
                        $c = defined('BOOTSTRAP_CALLBACK_RAN') ? BOOTSTRAP_CALLBACK_RAN : 'callback_no';

                        return Response::plaintext("{$f}:{$c}");
                    });
            },
            function ($address) use ($bootstrapFile) {
                $fp = stream_socket_client("tcp://{$address}", $errno, $errstr, 1.0);
                fwrite($fp, "GET / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");

                $response = stream_get_contents($fp);
                fclose($fp);

                expect($response)->toContain('200 OK')
                    ->and($response)->toContain('file_yes:callback_yes');

                if (file_exists($bootstrapFile)) {
                    unlink($bootstrapFile);
                }
            }
        );
    });
});
