<?php
declare(strict_types=1);
namespace Tygh\Addons\NovotonHolidays\Api;

use Tygh\Addons\NovotonHolidays\NovotonHttpClient;
use Tygh\Addons\NovotonHolidays\NovotonXmlParser;
use Tygh\Addons\NovotonHolidays\Services\CacheService;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Exceptions\ApiException;

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

    protected function getFromCache(string $function, string $cacheKey)
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

    protected function buildCacheKey(string $function, array $params): string
    {
        return 'nvt_api_' . $function . '_' . md5(json_encode($params));
    }
}
