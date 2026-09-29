<?php

namespace NepalCauseList\Http;

use NepalCauseList\CauseListClient;
use NepalCauseList\Support\FileCache;
use Throwable;

/**
 * Framework-agnostic HTTP API for the Nepal cause list scrapers.
 *
 * Run it with the built-in PHP server or Docker (see README). It exposes a
 * small JSON REST surface and caches upstream responses on disk so the court
 * websites are not hit on every request.
 */
class Api
{
    private CauseListClient $client;
    private FileCache $cache;
    private int $ttl;

    public function __construct(?CauseListClient $client = null, ?FileCache $cache = null, int $ttlSeconds = 1800)
    {
        $this->client = $client ?? new CauseListClient();
        $this->cache = $cache ?? new FileCache();
        $this->ttl = $ttlSeconds;
    }

    /** Dispatch the current request and emit a JSON response. */
    public function handle(?string $method = null, ?string $path = null, array $query = []): void
    {
        $method = $method ?? ($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = $path ?? parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        $query = $query ?: $_GET;

        $this->cors();
        if ($method === 'OPTIONS') {
            http_response_code(204);
            return;
        }

        $path = '/' . trim($path, '/');

        try {
            [$status, $body] = $this->route($path, $query);
        } catch (\InvalidArgumentException $e) {
            [$status, $body] = [400, ['success' => false, 'error' => $e->getMessage()]];
        } catch (Throwable $e) {
            [$status, $body] = [500, ['success' => false, 'error' => 'Internal error: ' . $e->getMessage()]];
        }

        $this->json($status, $body);
    }

    private function route(string $path, array $query): array
    {
        // Health / index
        if ($path === '/' || $path === '') {
            return [200, [
                'success' => true,
                'name' => 'Nepal Cause List API',
                'version' => '1.0.0',
                'endpoints' => [
                    'GET /v1/courts',
                    'GET /v1/today',
                    'GET /v1/highcourt/courts',
                    'GET /v1/districtcourt/courts',
                    'GET /v1/{court}/cause-lists/{type}?date=YYYY-MM-DD[&court_id=N]',
                ],
                'courts' => array_keys(CauseListClient::COURTS),
                'list_types' => CauseListClient::LIST_TYPES,
            ]];
        }

        if ($path === '/v1/courts') {
            return [200, ['success' => true, 'courts' => $this->client->courts()]];
        }

        if ($path === '/v1/today') {
            return [200, ['success' => true, 'today' => $this->client->today()]];
        }

        if ($path === '/v1/highcourt/courts') {
            return [200, ['success' => true, 'courts' => $this->client->highCourts()]];
        }

        if ($path === '/v1/districtcourt/courts') {
            return [200, ['success' => true, 'courts' => $this->client->districtCourts()]];
        }

        // GET /v1/{court}/cause-lists/{type}
        if (preg_match('#^/v1/([a-z]+)/cause-lists/([a-z]+)$#', $path, $m)) {
            return $this->causeList($m[1], $m[2], $query);
        }

        return [404, ['success' => false, 'error' => 'Not found']];
    }

    private function causeList(string $court, string $listType, array $query): array
    {
        if (!isset(CauseListClient::COURTS[$court])) {
            return [404, ['success' => false, 'error' => "Unknown court: {$court}"]];
        }
        if (!in_array($listType, CauseListClient::LIST_TYPES, true)) {
            return [400, ['success' => false, 'error' => "Unknown list type: {$listType}"]];
        }

        $date = isset($query['date']) ? (string) $query['date'] : $this->client->today()['formatted'];
        $courtId = isset($query['court_id']) && $query['court_id'] !== '' ? (int) $query['court_id'] : null;

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return [400, ['success' => false, 'error' => 'Invalid date. Use YYYY-MM-DD (BS).', 'example' => '2083-06-12']];
        }
        if (CauseListClient::COURTS[$court]['needs_court_id'] && $courtId === null) {
            return [400, ['success' => false, 'error' => "The {$court} requires ?court_id=<id>."]];
        }

        $cacheKey = implode(':', ['cl', $court, $listType, $date, $courtId ?? '-']);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            $cached['cached'] = true;
            return [200, $cached];
        }

        $result = $this->client->fetch($court, $listType, $date, $courtId);

        if (!($result['success'] ?? false)) {
            return [502, [
                'success' => false,
                'error' => $result['error'] ?? 'Failed to fetch cause list',
                'court' => $court,
                'list_type' => $listType,
                'date' => $date,
            ]];
        }

        $payload = [
            'success' => true,
            'cached' => false,
            'court' => $court,
            'list_type' => $listType,
            'date' => $date,
            'data' => $result,
        ];
        $this->cache->put($cacheKey, $payload, $this->ttl);
        return [200, $payload];
    }

    private function cors(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
    }

    private function json(int $status, array $body): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
}
