<?php
/**
 * Fake upstream for the integration suite: an HTTP proxy that terminates TLS itself.
 *
 *   php tests/integration/fake_upstream.php <port> <cert.pem> <key.pem> <log.jsonl>
 *
 * The local Developer API and its webhook worker run with `https_proxy=http://127.0.0.1:<port>`
 * and `-d curl.cainfo=<test CA>`, so every https call they make arrives here as a CONNECT:
 *
 * - webhook deliveries (POST to the endpoint URL)     -> recorded, answered 200;
 * - the HEAD probe of a video source URL               -> 200, video/mp4, a Content-Length;
 * - media-server calls (delete_file.php, captions)     -> 200 {"status":"success"}.
 *
 * Nothing leaves the machine, and the API's code runs unchanged: its SSRF guard sees a public
 * IP literal (203.0.113.10, a documentation range), and curl's TLS check passes against a
 * certificate signed by a test-only CA. Every request is appended to the log as one JSON line,
 * the body base64-encoded so the exact bytes survive.
 *
 * Test tooling only. It is not part of the SDK.
 */

if (PHP_SAPI !== 'cli' || $argc < 5) {
    fwrite(STDERR, "usage: php fake_upstream.php <port> <cert.pem> <key.pem> <log.jsonl>\n");
    exit(2);
}

[, $port, $cert, $key, $log] = $argv;

$context = stream_context_create(['ssl' => [
    'local_cert'        => $cert,
    'local_pk'          => $key,
    'verify_peer'       => false,
    'allow_self_signed' => true,
]]);

$server = stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if ($server === false) {
    fwrite(STDERR, "cannot listen on {$port}: {$errstr}\n");
    exit(1);
}
fwrite(STDOUT, "fake upstream listening on 127.0.0.1:{$port}\n");

/** Reads one HTTP request (head and Content-Length body) from a stream. */
function read_request($conn): ?array
{
    $head = '';
    while (strpos($head, "\r\n\r\n") === false) {
        $chunk = fread($conn, 1);
        if ($chunk === false || $chunk === '') {
            return null;
        }
        $head .= $chunk;
        if (strlen($head) > 65536) {
            return null;
        }
    }
    $lines = explode("\r\n", rtrim($head));
    $requestLine = array_shift($lines);
    $parts = explode(' ', $requestLine, 3);
    if (count($parts) < 2) {
        return null;
    }
    $headers = [];
    foreach ($lines as $line) {
        $colon = strpos($line, ':');
        if ($colon !== false) {
            $headers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
        }
    }
    $body = '';
    $length = (int) ($headers['content-length'] ?? 0);
    while (strlen($body) < $length) {
        $chunk = fread($conn, $length - strlen($body));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $body .= $chunk;
    }
    return ['method' => $parts[0], 'target' => $parts[1], 'headers' => $headers, 'body' => $body];
}

function respond($conn, int $status, array $headers, string $body, bool $headOnly = false): void
{
    $reason = [200 => 'OK', 400 => 'Bad Request', 404 => 'Not Found'][$status] ?? 'OK';
    $out = "HTTP/1.1 {$status} {$reason}\r\n";
    $headers += ['Content-Length' => (string) strlen($body), 'Connection' => 'close'];
    foreach ($headers as $name => $value) {
        $out .= "{$name}: {$value}\r\n";
    }
    $out .= "\r\n" . ($headOnly ? '' : $body);
    fwrite($conn, $out);
}

while (true) {
    $conn = @stream_socket_accept($server, -1);
    if ($conn === false) {
        continue;
    }
    stream_set_timeout($conn, 15);

    $first = read_request($conn);
    if ($first === null) {
        fclose($conn);
        continue;
    }

    $host = '';
    if ($first['method'] === 'CONNECT') {
        $host = $first['target'];
        fwrite($conn, "HTTP/1.1 200 Connection established\r\n\r\n");
        stream_set_blocking($conn, true);
        if (!@stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) {
            fclose($conn);
            continue;
        }
        $request = read_request($conn);
        if ($request === null) {
            fclose($conn);
            continue;
        }
    } else {
        $request = $first; // plain HTTP through the proxy (not used by the suite)
        $host = $request['headers']['host'] ?? '';
    }

    $path = (string) parse_url($request['target'], PHP_URL_PATH);
    file_put_contents($log, json_encode([
        'time'    => time(),
        'host'    => $host,
        'method'  => $request['method'],
        'path'    => $path,
        'headers' => $request['headers'],
        'body'    => base64_encode($request['body']),
    ], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);

    if (preg_match('#/(delete_file|upload_caption|delete_caption|upload_and_notify)\.php$#', $path)) {
        respond($conn, 200, ['Content-Type' => 'application/json'], '{"status":"success"}');
    } elseif (preg_match('#\.(mp4|mov|mkv|webm|m4v)$#', $path)) {
        $video = str_repeat("\0", 1024);
        respond($conn, 200, ['Content-Type' => 'video/mp4'], $video, $request['method'] === 'HEAD');
    } elseif (substr($path, -4) === '.vtt') {
        respond($conn, 200, ['Content-Type' => 'text/vtt'], "WEBVTT\n\n00:00:00.000 --> 00:00:01.000\nHello\n", $request['method'] === 'HEAD');
    } else {
        respond($conn, 200, ['Content-Type' => 'text/plain'], 'ok');
    }
    fclose($conn);
}
