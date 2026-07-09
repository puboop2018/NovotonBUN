<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Api;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * The ONE place Sphinx API response envelopes are interpreted.
 *
 * SphinxHttpClient returns the whole decoded JSON body; historically every
 * consumer re-derived the payload shape itself (~35 ad-hoc unwrap sites:
 * `['results'] ?? ['data'] ?? []`, raw `['data']`, …). Three production
 * incidents came from that drift surface — an offers-collection shape change,
 * a verify field change, and a verify ENVELOPE change that made every
 * "Rezervă" click fail. SphinxApi now routes responses through this decoder,
 * so a future shape drift is absorbed here (and pinned by the JSON fixtures
 * in tests/Fixtures/api) instead of breaking storefront consumers.
 *
 * Conventions handled:
 *  - single resources wrapped as {success, data: {...}} / {offer: {...}} /
 *    {result: {...}} (possibly nested one level);
 *  - collections under results|data|items|hotels|packages|offers;
 *  - search/poll responses carrying a list AND a token AND a status —
 *    canonicalized (original keys preserved) to guaranteed
 *    {status: string, results: list, cursor: ?string}.
 */
final class ResponseDecoder
{
    /** Envelope keys single-resource payloads hide under. */
    private const array ENVELOPE_KEYS = ['data', 'offer', 'result'];

    /** Keys that mark a level as being the offer payload itself. */
    private const array OFFER_SIGNALS = ['available', 'confirmation', 'price', 'selling_price', 'pricing'];

    /** Collection keys, in priority order. */
    private const array LIST_KEYS = ['results', 'data', 'items', 'hotels', 'packages', 'offers'];

    /** @var array<string, mixed> Diagnostics for the last decode (top-level keys + matched branch). */
    private array $lastEnvelope = [];

    /**
     * Descend through known envelopes to the offer payload. Flat
     * (un-enveloped) responses pass through unchanged; unknown shapes are
     * returned as reached so the caller's gate/diagnostics judge them.
     *
     * Static so OfferAvailability::unwrapOffer can share the single
     * implementation.
     *
     * @param array<array-key, mixed>|null $response
     * @return array<array-key, mixed>|null
     */
    public static function descendToPayload(?array $response): ?array
    {
        if ($response === null || $response === []) {
            return null;
        }

        $level = $response;
        for ($depth = 0; $depth < 3; $depth++) {
            foreach (self::OFFER_SIGNALS as $signal) {
                if (array_key_exists($signal, $level)) {
                    return $level;
                }
            }

            $descended = false;
            foreach (self::ENVELOPE_KEYS as $key) {
                $inner = $level[$key] ?? null;
                if (is_array($inner) && $inner !== []) {
                    $level = $inner;
                    $descended = true;
                    break;
                }
            }

            if (!$descended) {
                break;
            }
        }

        return $level;
    }

    /**
     * Decode a verify-offer response to the offer payload (string-keyed map,
     * as offer payloads are by contract).
     *
     * @param array<array-key, mixed>|null $response
     * @return array<string, mixed>|null
     */
    public function offer(?array $response): ?array
    {
        $payload = self::descendToPayload($response);
        $this->record($response, $payload === $response ? 'offer:flat' : 'offer:unwrapped');

        return $payload === null ? null : TypeCoerce::toStringMap($payload);
    }

    /**
     * Decode a single-resource response ({data: {...}} envelope or the
     * resource itself). Mirrors the probe consumers used ad hoc
     * (`isset($raw['data']) && !isset($raw['id'])`).
     *
     * @param array<array-key, mixed>|null $response
     * @return array<array-key, mixed>|null
     */
    public function single(?array $response): ?array
    {
        if ($response === null || $response === []) {
            $this->record($response, 'single:empty');
            return null;
        }

        $data = $response['data'] ?? null;
        if (is_array($data) && $data !== [] && !array_key_exists('id', $response)) {
            $this->record($response, 'single:data');
            /** @var array<array-key, mixed> $data */
            return $data;
        }

        $this->record($response, 'single:flat');
        return $response;
    }

    /**
     * Decode a collection response to a re-indexed list of rows. A bare list
     * body (numeric keys) is accepted as the collection itself.
     *
     * @param array<array-key, mixed>|null $response
     * @return list<array<string, mixed>>
     */
    public function list(?array $response): array
    {
        if ($response === null || $response === []) {
            $this->record($response, 'list:empty');
            return [];
        }

        foreach (self::LIST_KEYS as $key) {
            $inner = $response[$key] ?? null;
            if (is_array($inner)) {
                $this->record($response, "list:{$key}");
                return TypeCoerce::toRowList($inner);
            }
        }

        if (array_is_list($response)) {
            $this->record($response, 'list:bare');
            return TypeCoerce::toRowList($response);
        }

        $this->record($response, 'list:none');
        return [];
    }

    /**
     * Canonicalize a search/poll response. These carry a list AND a
     * continuation token AND a status, so they must not collapse to the list:
     * the original keys are preserved and the canonical trio is guaranteed on
     * top —
     *   status:  string ('' when absent)
     *   results: list<row>  (results|data)
     *   cursor:  ?string    (next_cursor|cursor|search_id)
     *
     * @param array<array-key, mixed>|null $response
     * @return array<string, mixed>|null
     */
    public function search(?array $response): ?array
    {
        if ($response === null) {
            $this->record($response, 'search:null');
            return null;
        }

        $rows = null;
        foreach (['results', 'data'] as $key) {
            if (is_array($response[$key] ?? null)) {
                $rows = TypeCoerce::toRowList($response[$key]);
                break;
            }
        }

        $cursor = null;
        foreach (['next_cursor', 'cursor', 'search_id'] as $key) {
            $value = TypeCoerce::toString($response[$key] ?? '');
            if ($value !== '') {
                $cursor = $value;
                break;
            }
        }

        $this->record($response, 'search:canonical');

        /** @var array<string, mixed> $canonical */
        $canonical = array_merge($response, [
            'status' => TypeCoerce::toString($response['status'] ?? ''),
            'results' => $rows ?? [],
            'cursor' => $cursor,
        ]);

        return $canonical;
    }

    /**
     * Diagnostics for the last decode: which top-level keys the response had
     * and which decode branch matched. Feeds the zero-results logging so
     * shape drift is visible in the logs before it becomes an incident.
     *
     * @return array<string, mixed>
     */
    public function lastEnvelope(): array
    {
        return $this->lastEnvelope;
    }

    /**
     * @param array<array-key, mixed>|null $response
     */
    private function record(?array $response, string $branch): void
    {
        $this->lastEnvelope = [
            'branch' => $branch,
            'keys' => $response === null ? [] : array_slice(array_map('strval', array_keys($response)), 0, 12),
        ];
    }
}
