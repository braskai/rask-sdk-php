<?php

declare(strict_types=1);

namespace Rask\Sdk\Tests\Unit;

/**
 * A real TLS endpoint on 127.0.0.1, in a process of its own: it answers every request the same and counts the
 * connections it accepts, so a test sees each attempt that reached it.
 *
 * Its keys and certificates are made when a test class starts, in a temporary directory that the class removes when it
 * ends. None is kept in the repository.
 */
final class TlsEndpoint
{
    /** For 127.0.0.1, signed by the CA in ca.crt, valid for a day. The others differ from it in one thing each. */
    public const GOOD = 'good';
    public const SELF_SIGNED = 'self-signed';
    public const EXPIRED = 'expired';
    public const WRONG_HOST = 'wrong-host';

    /** No certificate at all: the endpoint hangs up on every connection before the handshake. */
    public const HANGS_UP = 'hangs-up';

    // Echoes its port, then "accepted" for each connection, before the handshake that a client may refuse.
    private const SERVER = <<<'PHP'
        $context = stream_context_create(is_file($argv[1]) ? ['ssl' => ['local_cert' => $argv[1], 'local_pk' => $argv[2]]] : []);
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        if ($server === false) {
            fwrite(STDERR, "The TLS endpoint cannot listen: $error\n");
            exit(1);
        }
        echo substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1), "\n";
        while (true) {
            $connection = @stream_socket_accept($server, 3600);
            if ($connection === false) {
                continue;
            }
            echo "accepted\n";
            stream_set_timeout($connection, 5);
            if (is_file($argv[1]) && @stream_socket_enable_crypto($connection, true, STREAM_CRYPTO_METHOD_TLS_SERVER) === true) {
                // Closing with a part of the request unread would reset the connection under the response.
                $request = '';
                while (!str_contains($request, "\r\n\r\n") && ($chunk = fread($connection, 65536)) !== false && $chunk !== '') {
                    $request .= $chunk;
                }
                $left = (preg_match('/^Content-Length:\s*(\d+)/mi', $request, $match) === 1 ? (int) $match[1] : 0)
                    - (strlen($request) - (int) strpos($request, "\r\n\r\n") - 4);
                while ($left > 0 && ($chunk = fread($connection, $left)) !== false && $chunk !== '') {
                    $left -= strlen($chunk);
                }
                // PHP's own client completes the handshake and then hangs up on a certificate it refuses.
                if (str_contains($request, "\r\n\r\n")) {
                    @fwrite($connection, "HTTP/1.1 $argv[3] OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($argv[4]) . "\r\nConnection: close\r\n\r\n$argv[4]");
                }
            }
            fclose($connection);
        }
        PHP;

    public readonly int $port;

    /** @var resource */
    private $process;

    /** @var resource */
    private $output;

    private int $connections = 0;

    /**
     * @param string $directory what makeCertificates() returned
     * @param array<array-key, mixed> $body the JSON that answers every request
     */
    public function __construct(string $directory, string $certificate, int $status = 200, array $body = ['id' => 'me'])
    {
        $command = [
            PHP_BINARY, '-d', 'display_errors=stderr', '-r', self::SERVER, '--',
            "$directory/$certificate.crt", "$directory/$certificate.key", (string) $status, (string) json_encode($body),
        ];
        $process = proc_open($command, [1 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('The TLS endpoint did not start.');
        }
        $this->process = $process;
        $this->output = $pipes[1];
        $port = (int) fgets($this->output);
        if ($port === 0) {
            $this->close();
            throw new \RuntimeException('The TLS endpoint did not say which port it listens on.');
        }
        $this->port = $port;
        stream_set_blocking($this->output, false);
    }

    public function url(): string
    {
        return "https://127.0.0.1:{$this->port}";
    }

    /**
     * How many connections it has accepted. A client learns of the certificate only from the endpoint's answer, so
     * every attempt that failed on the certificate is counted by the time its call returns.
     */
    public function connections(): int
    {
        $this->connections += substr_count((string) stream_get_contents($this->output), "accepted\n");
        return $this->connections;
    }

    public function close(): void
    {
        proc_terminate($this->process);
        fclose($this->output);
        proc_close($this->process);
    }

    /**
     * Makes a CA and the four certificates, each with its key, as files named by the constants above. PHP's openssl
     * extension does it all: no validity can end before it starts there, so the expired one is valid for no time at
     * all, which fails verification as any validity that has ended does.
     *
     * @return string the directory, for removeCertificates()
     */
    public static function makeCertificates(): string
    {
        $directory = sys_get_temp_dir() . '/rask-sdk-tls-' . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700)) {
            throw new \RuntimeException("Cannot create $directory.");
        }
        $config = "$directory/openssl.cnf";
        file_put_contents($config, implode("\n", [
            '[req]',
            'default_bits = 2048',
            'distinguished_name = dn',
            '[dn]',
            '[ca]',
            'basicConstraints = critical, CA:TRUE',
            'keyUsage = critical, keyCertSign, cRLSign',
            '[here]',
            'basicConstraints = CA:FALSE',
            'subjectAltName = IP:127.0.0.1',
            '[elsewhere]',
            'basicConstraints = CA:FALSE',
            'subjectAltName = DNS:wrong.example',
            '',
        ]));
        [$ca, $caKey] = self::certificate($directory, 'ca', 'Rask SDK test CA', 'ca', 1);
        self::certificate($directory, self::GOOD, '127.0.0.1', 'here', 1, $ca, $caKey);
        self::certificate($directory, self::SELF_SIGNED, '127.0.0.1', 'here', 1);
        self::certificate($directory, self::EXPIRED, '127.0.0.1', 'here', 0, $ca, $caKey);
        self::certificate($directory, self::WRONG_HOST, 'wrong.example', 'elsewhere', 1, $ca, $caKey);
        return $directory;
    }

    public static function removeCertificates(string $directory): void
    {
        foreach (glob("$directory/*") ?: [] as $file) {
            unlink($file);
        }
        rmdir($directory);
    }

    /**
     * Signed by the issuer, or by its own key when there is none.
     *
     * @return array{\OpenSSLCertificate, \OpenSSLAsymmetricKey}
     */
    private static function certificate(
        string $directory,
        string $name,
        string $commonName,
        string $extensions,
        int $days,
        ?\OpenSSLCertificate $issuer = null,
        ?\OpenSSLAsymmetricKey $issuerKey = null,
    ): array {
        $config = "$directory/openssl.cnf";
        $key = openssl_pkey_new(['config' => $config, 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $request = $key === false ? false : openssl_csr_new(['commonName' => $commonName], $key, ['config' => $config, 'digest_alg' => 'sha256']);
        $certificate = $key === false || !$request instanceof \OpenSSLCertificateSigningRequest ? false : openssl_csr_sign(
            $request,
            $issuer,
            $issuerKey ?? $key,
            $days,
            ['config' => $config, 'digest_alg' => 'sha256', 'x509_extensions' => $extensions],
            random_int(1, PHP_INT_MAX),
        );
        if ($key === false || $certificate === false
            || !openssl_x509_export_to_file($certificate, "$directory/$name.crt")
            || !openssl_pkey_export_to_file($key, "$directory/$name.key", null, ['config' => $config])
        ) {
            throw new \RuntimeException("Cannot make the certificate $name: " . openssl_error_string());
        }
        return [$certificate, $key];
    }
}
