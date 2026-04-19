<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Api\SphinxNormalizer;
use Tygh\Addons\SphinxHolidays\Services\SphinxFeatureAssigner;
use Tygh\Addons\SphinxHolidays\Tests\Support\DbStub;
use Tygh\Addons\TravelCore\Contracts\FeatureMapRepositoryInterface;
use Tygh\Addons\TravelCore\Services\FeatureMapper;
use Tygh\Registry;

/**
 * Coverage for SphinxFeatureAssigner.
 *
 * Uses FeatureMapper's documented test-injection hooks
 * (setRepository / setVariantResolver) to control the static collaborators
 * from outside the SUT, per their docblock intent. Happy-path coverage
 * focuses on the trait-backed DB writes (assignSelectBoxValue); facility +
 * travel-group orchestration are exercised via short-circuit paths plus
 * the cache-reset behaviour of assignAll().
 */
#[CoversClass(SphinxFeatureAssigner::class)]
class SphinxFeatureAssignerTest extends TestCase
{
    /** @var FeatureMapRepositoryInterface&MockObject */
    private FeatureMapRepositoryInterface $repo;

    private SphinxFeatureAssigner $assigner;

    protected function setUp(): void
    {
        DbStub::reset();

        // Inject the mock FIRST so any clearCache() flush that batch-updates
        // last_used_at hits the mock rather than the lazy real repo.
        $this->repo = $this->createMock(FeatureMapRepositoryInterface::class);
        FeatureMapper::setRepository($this->repo);
        FeatureMapper::clearCache();

        // Clear any feature_id registry entries from prior tests. Keys
        // come from FeatureMapper::FEATURE_SETTING_KEYS (some feature types
        // remap — e.g. 'stars' → 'feature_id_property_rating').
        foreach ([
            'feature_id_property_rating',
            'feature_id_property_type',
            'feature_id_location',
            'feature_id_meals',
            'feature_id_region',
            'feature_id_city',
            'feature_id_travel_group',
            'feature_id_hotel_facility',
        ] as $key) {
            Registry::set('addons.travel_core.' . $key, null);
        }

        $this->assigner = new SphinxFeatureAssigner(new SphinxNormalizer());
    }

    protected function tearDown(): void
    {
        // Flush with the mock still active so batch-updates don't hit the
        // lazy real repo when setRepository(null) takes effect.
        FeatureMapper::clearCache();
        FeatureMapper::setRepository(null);
        DbStub::reset();
    }

    // ── Constructor ─────────────────────────────────────────────────────────

    public function testConstructorStoresNormalizerAsReadonlyProperty(): void
    {
        $normalizer = new SphinxNormalizer();
        $assigner = new SphinxFeatureAssigner($normalizer);

        $ref = new \ReflectionProperty($assigner, 'normalizer');
        $this->assertSame($normalizer, $ref->getValue($assigner));
    }

    // ── assignAll: all short-circuits, no DB writes ─────────────────────────

    public function testAssignAllPerformsNoDbWritesWhenNoFeaturesConfigured(): void
    {
        // No Registry feature_ids, no mappings from repo → every private
        // method short-circuits before hitting db_query.
        $this->repo->method('findByAlias')->willReturn(null);

        DbStub::$query = function () {
            $this->fail('db_query should not be called when no features are configured');
        };

        $this->assigner->assignAll(42, $this->fullHotel());

        $this->addToAssertionCount(1); // reached end without DbStub $query tripping
    }

    // ── assignBoards: JSON guard paths ──────────────────────────────────────

    public function testAssignBoardsSkipsWhenBoardsJsonMissingOrEmpty(): void
    {
        DbStub::$query = function () {
            $this->fail('db_query should not be called when boards_json is empty');
        };

        $this->assigner->assignAll(42, ['boards_json' => null]);
        $this->assigner->assignAll(42, ['boards_json' => '']);
        $this->assigner->assignAll(42, []);

        $this->addToAssertionCount(1);
    }

    public function testAssignBoardsSkipsWhenBoardsJsonIsMalformed(): void
    {
        Registry::set('addons.travel_core.feature_id_board', 10);
        // Even with feature_id configured, malformed JSON decodes to null →
        // !is_array → short-circuit before touching repo or DB.
        $this->repo->expects($this->never())->method('findByAlias');

        DbStub::$query = function () {
            $this->fail('db_query should not be called for malformed boards JSON');
        };

        $this->assigner->assignAll(42, ['boards_json' => '{not valid']);

        $this->addToAssertionCount(1);
    }

    // ── assignFacilities: JSON guard path ───────────────────────────────────

    public function testAssignFacilitiesSkipsWhenFacilitiesJsonMissing(): void
    {
        // With facilities_json missing, resolveHotelFacilities returns [] →
        // both assignFacilities and getHotelFacilityCodes (used by
        // assignTravelGroup) short-circuit.
        DbStub::$query = function () {
            $this->fail('db_query should not be called when facilities_json is missing');
        };

        $this->assigner->assignAll(42, []);

        $this->addToAssertionCount(1);
    }

    // ── assignRegion: guards + handleUnmapped path ─────────────────────────

    public function testAssignRegionSkipsWhenRegionIdEmptyOrZero(): void
    {
        $this->repo->expects($this->never())->method('findByAlias');

        $this->assigner->assignAll(42, ['region_id' => '']);
        $this->assigner->assignAll(42, ['region_id' => '0']);

        $this->addToAssertionCount(1);
    }

    public function testAssignRegionTracksUnmappedWhenNoMappingReturned(): void
    {
        // For region lookup specifically the repo returns null → the SUT
        // calls FeatureMapper::handleUnmapped which in turn calls
        // trackUnmapped on the repo with the region_name as the label.
        $this->repo->method('findByAlias')->willReturn(null);
        $this->repo->expects($this->atLeastOnce())
            ->method('trackUnmapped')
            ->with(
                'sphinx',
                $this->isType('string'),
                $this->isType('string'),
                $this->isType('string'),
            );

        $this->assigner->assignAll(42, ['region_id' => '55', 'region_name' => 'Sunny Beach']);
    }

    // ── assignAll: per-hotel cache reset ────────────────────────────────────

    public function testAssignAllResetsResolvedFacilitiesCacheBetweenCalls(): void
    {
        $ref = new \ReflectionProperty($this->assigner, 'resolvedFacilitiesCache');

        // Prime the cache via reflection to look non-empty.
        $ref->setValue($this->assigner, [['id' => 'old', 'name' => 'cached', 'mapping' => null]]);

        $this->assigner->assignAll(1, []);

        // First line of assignAll resets the cache to null.
        $this->assertNull($ref->getValue($this->assigner));
    }

    // ── assignMappedSelectBox: happy-path DB branches ───────────────────────

    public function testAssignStarRatingSkipsDbWritesWhenVariantAlreadyCorrect(): void
    {
        Registry::set('addons.travel_core.feature_id_property_rating', 10);

        // Mapping returned with cscart_variant_id=50.
        $this->repo->method('findByAlias')->willReturn([
            'cscart_variant_id' => 50,
            'map_id' => 1,
        ]);

        // assignSelectBoxValue reads the existing variant — returns 50, so
        // skip-if-same optimisation should bail out without any db_query.
        DbStub::$getField = static fn (): int => 50;
        DbStub::$query = function () {
            $this->fail('db_query should not be called when variant is already correct');
        };

        $this->assigner->assignAll(42, ['classification' => 4]);

        $this->addToAssertionCount(1);
    }

    public function testAssignStarRatingWritesDeleteAndInsertWhenVariantDiffers(): void
    {
        Registry::set('addons.travel_core.feature_id_property_rating', 10);

        $this->repo->method('findByAlias')->willReturn([
            'cscart_variant_id' => 77,
            'map_id' => 1,
        ]);

        // Existing variant (25) differs from wanted (77) → DELETE + INSERT
        // per active language (just 'en' in our getFields stub).
        DbStub::$getField = static fn (): int => 25;
        DbStub::$getFields = static fn (): array => ['en'];

        $queries = [];
        DbStub::$query = static function (string $query) use (&$queries): int {
            $queries[] = $query;
            return 1;
        };

        $this->assigner->assignAll(42, ['classification' => 4]);

        $this->assertCount(2, $queries);
        $this->assertStringContainsString('DELETE FROM', $queries[0]);
        $this->assertStringContainsString('INSERT INTO', $queries[1]);
    }

    // ── assignTravelGroup: empty-groups path ────────────────────────────────

    public function testAssignTravelGroupSyncsEmptyWhenNoGroupCodesDerived(): void
    {
        // travel_group feature configured but no facility codes means the
        // group resolver produces []. The SUT still syncs checkbox values
        // with [] to clear stale assignments — that routes through
        // syncCheckboxValues which reads current state via db_get_fields.
        Registry::set('addons.travel_core.feature_id_travel_group', 99);
        // Resort feature also triggers a FeatureMapper::resolve() call; we
        // want to ensure the 'resort' path short-circuits so the test
        // stays focused on travel_group behaviour.

        // No mappings for any facility lookup.
        $this->repo->method('findByAlias')->willReturn(null);

        // syncCheckboxValues with wanted=[] reads current variants —
        // stub returns [] so toRemove is empty, toAdd is empty, no writes.
        DbStub::$getFields = static fn (): array => [];
        DbStub::$query = function () {
            $this->fail('db_query should not be called when there is nothing to add or remove');
        };

        $this->assigner->assignAll(42, []);

        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, mixed>
     */
    private function fullHotel(): array
    {
        return [
            'classification' => 4,
            'property_type' => 'hotel',
            'destination_name' => 'Sunny Beach',
            'facilities_json' => '[]',
            'boards_json' => '[]',
            'region_id' => '0',
            'is_adults_only' => 'N',
        ];
    }
}
