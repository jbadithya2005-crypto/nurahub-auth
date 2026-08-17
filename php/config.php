<?php
require_once __DIR__ . '/../vendor/autoload.php';
function loadEnv(string $path): void {
    if (!file_exists($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) {
            continue;
        }
        [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
        $key = trim($key);
        $value = trim($value);
        if ($key !== '' && getenv($key) === false) {
            putenv("$key=$value");
        }
    }
}

loadEnv(__DIR__ . '/../.env');

function env(string $key, ?string $default = null): ?string {
    $value = getenv($key);
    return $value === false ? $default : $value;
}

function getMysqlConnection(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $host = env('DB_HOST', '127.0.0.1');
        $port = env('DB_PORT', '3306');
        $db   = env('DB_NAME', 'nurahub_auth');
        $user = env('DB_USER', 'root');
        $pass = env('DB_PASS', '');

        $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

function getMongoCollection(): ?MongoDB\Collection {
    try {
        $uri = env('MONGO_URI');
        $dbName = env('MONGO_DB', 'nurahub_auth');

        if (!$uri) {
            throw new RuntimeException('MONGO_URI is empty or not loaded');
        }

        $client = new MongoDB\Client($uri);

        $db = $client->selectDatabase($dbName);

        // Force Atlas connection
        $db->command(['ping' => 1]);

        return $db->selectCollection('profiles');

    } catch (Throwable $e) {
        // TEMPORARY: show the real error
        throw new RuntimeException(
            'MongoDB error: ' . $e->getMessage(),
            0,
            $e
        );
    }
}

function getRedisConnection(): ?Redis {
    static $redis = null;
    static $attempted = false;
    if ($redis === null && !$attempted) {
        $attempted = true;
        try {
            $candidate = new Redis();
            $host = env('REDIS_HOST', '127.0.0.1');
            $port = (int) env('REDIS_PORT', '6379');
            $candidate->connect($host, $port, 2);
            $pass = env('REDIS_PASS', '');
            if ($pass !== '') {
                $candidate->auth($pass);
            }
            $candidate->ping();
            $redis = $candidate;
        } catch (Throwable $e) {
            error_log('Redis unavailable, falling back to native PHP sessions: ' . $e->getMessage());
            $redis = null;
        }
    }
    return $redis;
}

const SESSION_TTL_SECONDS = 3600;
const SESSION_COOKIE_NAME = 'nh_session';

function jsonResponse(bool $ok, string $message = '', array $data = [], int $httpCode = 200): void {
    http_response_code($httpCode);
    header('Content-Type: application/json');
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $data));
    exit;
}
