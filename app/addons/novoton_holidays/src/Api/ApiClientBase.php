<?php
declare(strict_types=1);
namespace Tygh\Addons\NovotonHolidays\Api;

use Tygh\Addons\NovotonHolidays\NovotonHttpClient;
use Tygh\Addons\NovotonHolidays\NovotonXmlParser;
use Tygh\Addons\NovotonHolidays\Services\CacheService;
use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Exceptions\ApiException;
use Tygh\Addons\NovotonHolidays\ValueObjects\Occupancy;

abstract class ApiClientBase
{
    protected NovotonHttpClient $httpClient;
    protected NovotonXmlParser $xmlParser;
    protected ?CacheService $cache;
    protected bool $enableCache;

    /** @var array Cache TTL by function (seconds) */
    protected array $cacheTtl = [];

    /** @var string[] Functions that bypass cache */
    protected array $noCacheFunctions = [];

    // Debug state (synced from parent NovotonApi)
    public string $lastRequest = '';
    public string $lastResponse = '';
    public string $lastResponseRaw = '';
    public array $lastRequestFormatted = [];
    public string $lastError = '';
    public int $lastHttpCode = 0;

    public function __construct(
        NovotonHttpClient $httpClient,
        NovotonXmlParser $xmlParser,
        ?CacheService $cache,
        bool $enableCache
    ) {
        $this->httpClient = $httpClient;
        $this->xmlParser = $xmlParser;
        $this->cache = $cache;
        $this->enableCache = $enableCache;
    }

    protected function callApi(string $function, string $xml, string $lang = 'UK'): string
    {
        try {
            $raw = $this->httpClient->sendRequest($function, $xml, $lang);
        } catch (ApiException $e) {
            $this->syncDebugState();
            throw $e;
        }

        $this->syncDebugState();
        $cleaned = $this->xmlParser->clean($raw);
        $this->lastResponse = $cleaned;
        return $cleaned;
    }

    protected function callApiAndParse(string $function, string $xml, string $lang = 'UK'): \SimpleXMLElement
    {
        $response = $this->callApi($function, $xml, $lang);
        return $this->xmlParser->parse($response);
    }

    public function syncDebugState(): void
    {
        $this->lastResponseRaw = $this->httpClient->lastResponseRaw;
        $this->lastError = $this->httpClient->lastError;
        $this->lastHttpCode = $this->httpClient->lastHttpCode;
    }

    protected function getFromCache(string $function, string $cacheKey): mixed
    {
        if (in_array($function, $this->noCacheFunctions)) {
            return null;
        }
        if (!$this->enableCache || !$this->cache) {
            return null;
        }
        return $this->cache->get($cacheKey);
    }

    protected function saveToCache(string $function, string $cacheKey, $data): void
    {
        if (in_array($function, $this->noCacheFunctions)) {
            return;
        }
        if (!$this->enableCache || !$this->cache || $data === null) {
            return;
        }
        $ttl = $this->cacheTtl[$function] ?? ConfigProvider::getCacheTtlSearch();
        $this->cache->set($cacheKey, $data, $ttl);
    }

    /**
     * Build XML header with encoding declaration.
     */
    protected function xmlHeader(): string
    {
        return '<?xml version="1.0" encoding="windows-1251"?>';
    }

    /**
     * Build <usr> and <psw> credential elements.
     */
    protected function xmlCredentials(): string
    {
        return '<usr>' . htmlspecialchars($this->httpClient->getApiUser()) . '</usr>'
             . '<psw>' . htmlspecialchars($this->httpClient->getApiPassword()) . '</psw>';
    }

    /**
     * Wrap a value in CDATA for safe XML embedding.
     *
     * Resort/city/hotel names may contain characters like & which, when
     * encoded via htmlspecialchars(), produce &amp; — technically valid XML,
     * but the Novoton API performs literal string matching and expects the
     * raw value.  CDATA preserves the exact string as returned by the API's
     * own resort_list / hotel_list responses.
     */
    protected function xmlCdata(string $value): string
    {
        // CDATA sections cannot contain the literal ']]>' sequence.
        // If present (extremely unlikely in resort names), split into two CDATA sections.
        $safe = str_replace(']]>', ']]]]><![CDATA[>', $value);
        return '<![CDATA[' . $safe . ']]>';
    }

    /**
     * Build children ages XML (<Age> elements).
     * Delegates to Occupancy::buildAgeXml() — single source of truth.
     */
    protected function buildChildrenAgesXml(array $children): string
    {
        return Occupancy::buildAgeXml($children);
    }

    /**
     * Build adult ages XML (<Age> elements).
     * Uses default adult age from Constants for missing entries.
     */
    protected function buildAdultAgesXml(int $count, array $adultAges = []): string
    {
        $ages = [];
        for ($i = 0; $i < $count; $i++) {
            $ages[] = isset($adultAges[$i]) ? (int) $adultAges[$i] : Constants::DEFAULT_ADULT_AGE;
        }
        return Occupancy::buildAgeXml($ages);
    }

    protected function buildCacheKey(string $function, array $params): string
    {
        // Include hotel_id before the hash so cache invalidation per hotel
        // can use index-friendly prefix matching (no leading wildcards).
        $hotelId = $params['hotel_id'] ?? '';
        $hotelPart = $hotelId !== '' ? $hotelId . '_' : '';
        return 'nvt_api_' . $function . '_' . $hotelPart . md5(json_encode($params));
    }
}
