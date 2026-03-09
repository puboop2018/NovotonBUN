# Feature Mapping System

## Provider-Agnostic Hub for CS-Cart Product Features

**Version:** 1.2
**Date:** 2026-03-09
**Scope:** Novoton XML API + Sphinx REST API (+ future providers)
**Addon:** `novoton_holidays` (v3.3.0+)

---

## Table of Contents

1. [Overview & Purpose](#1-overview--purpose)
2. [The 4-Layer Pipeline](#2-the-4-layer-pipeline)
3. [Layer 1: API Raw Values](#3-layer-1-api-raw-values)
4. [Layer 2: Normalization](#4-layer-2-normalization)
5. [Layer 3: Mapping Table (hotel_feature_mappings)](#5-layer-3-mapping-table-hotel_feature_mappings)
6. [Layer 4: FeatureMapper — Writing to CS-Cart Products](#6-layer-4-featuremapper--writing-to-cs-cart-products)
7. [Feature Types Reference](#7-feature-types-reference)
8. [Strict vs Dynamic Feature Types](#8-strict-vs-dynamic-feature-types)
9. [Seed Data](#9-seed-data)
10. [End-to-End Board Type Walkthrough](#10-end-to-end-board-type-walkthrough)
11. [Complete Data Flow Diagram](#11-complete-data-flow-diagram)
12. [Adding a New Provider (Sphinx Example)](#12-adding-a-new-provider-sphinx-example)
13. [Admin UI Management](#13-admin-ui-management)
14. [Source File Reference](#14-source-file-reference)

---

## 1. Overview & Purpose

### The Problem

Different travel APIs return different raw values for the same concept. The Novoton XML API might return `"ALL INCL"` for a meal plan, while a future Sphinx REST API could return `"ALL INCLUSIVE PLUS"` or `"Mic dejun"`. Star ratings come as `"4*"` from Novoton but as a plain integer `5` from Sphinx. Without a normalization layer, every consumer of this data would need to handle every provider's quirks individually.

### The Solution

The Feature Mapping System is a **provider-agnostic hub** that sits between raw API values and CS-Cart product features. It provides:

1. **Normalization** — Convert any raw API value to a canonical code
2. **Mapping** — Link canonical codes to CS-Cart feature IDs and variant IDs
3. **Assignment** — Write feature values to products using the correct strategy (overwrite or merge)
4. **Auto-creation** — Automatically create CS-Cart variants when they don't exist yet

This means adding a new provider requires implementing only a single normalizer class. The mapping table, variant creation, and product assignment logic are fully shared.

---

## 2. The 4-Layer Pipeline

Every feature value flows through exactly 4 layers, from API response to CS-Cart product:

```
┌─────────────────────────────────────────────────────────────────────────────────┐
│                         THE 4-LAYER PIPELINE                                    │
│                                                                                 │
│  Layer 1              Layer 2              Layer 3              Layer 4          │
│  API Raw Values  ──>  Normalization   ──>  Mapping Table   ──>  CS-Cart Product │
│                                                                                 │
│  "ALL INCL"           "AI"                 mapping_id=7         product_features │
│  "FB+"                "FB+"                variant_id=42        _values table    │
│  "4*"                 "4"                  feature_id=15                         │
│  "B&B"                "BB"                                                      │
└─────────────────────────────────────────────────────────────────────────────────┘
```

Each layer is implemented by a dedicated component:

| Layer | Component | File |
|-------|-----------|------|
| 1 | API Client responses | Various API clients |
| 2 | `NovotonNormalizer` + `BoardType` value object | `src/Api/NovotonNormalizer.php`, `src/ValueObjects/BoardType.php` |
| 3 | `FeatureMappingRepository` | `src/Repository/FeatureMappingRepository.php` |
| 4 | `FeatureMapper` service | `src/Services/FeatureMapper.php` |

---

## 3. Layer 1: API Raw Values

### What Comes From the API

The Novoton API returns board/meal types inconsistently across different endpoints and hotels. The same concept can appear as different strings:

| API Returns | Intended Meaning |
|-------------|-----------------|
| `"AI"` | All Inclusive |
| `"ALL INCL"` | All Inclusive |
| `"ALL INCLUSIVE"` | All Inclusive |
| `"ALLINC"` | All Inclusive |
| `"FB"` | Full Board |
| `"FB+"` | Full Board Plus |
| `"FULL BOARD"` | Full Board |
| `"HB"` | Half Board |
| `"HALF BOARD"` | Half Board |
| `"B&B"` | Bed & Breakfast |
| `"BED AND BREAKFAST"` | Bed & Breakfast |

Similarly, star ratings may come as `"4*"`, `"3 Sup"`, or just `"5"`.

### Where Raw Values Enter the System

In `AddProductsCommand::assignProductFeatures()` (file: `src/Cron/Commands/AddProductsCommand.php`), raw values are extracted from the API response:

```php
// Board types extracted from hotel data
$hotelData = fn_novoton_holidays_get_hotel_data($hotelId);
foreach ($hotelData['boards'] as $board) {
    $raw = is_array($board)
        ? ($board['IdBoard'] ?? $board['Board'] ?? '')
        : (string) $board;
    // $raw might be "ALL INCL", "FB+", "HB", etc.
    $code = $normalizer->normalizeBoardCode((string) $raw);
    if ($code !== null) {
        $boardCodes[] = $code;
    }
}
```

---

## 4. Layer 2: Normalization

### 4.1 BoardType Value Object

**File:** `src/ValueObjects/BoardType.php`

The `BoardType` value object is the **single source of truth** for all board/meal plan codes. It defines:

#### Canonical Codes (9 total)

These match the `<IdBoard>` values returned by the Novoton `room_price` API:

| Constant | Code | Display Name |
|----------|------|-------------|
| `ALL_INCLUSIVE` | `AI` | All Inclusive |
| `ULTRA_ALL_INCLUSIVE` | `UAI` | Ultra All Inclusive |
| `FULL_BOARD` | `FB` | Full Board |
| `FULL_BOARD_PLUS` | `FB+` | Full Board Plus |
| `HALF_BOARD` | `HB` | Half Board |
| `HALF_BOARD_PLUS` | `HB+` | Half Board Plus |
| `BED_AND_BREAKFAST` | `BB` | Bed & Breakfast |
| `ROOM_ONLY` | `RO` | Room Only |
| `SELF_CATERING` | `SC` | Self Catering |

#### Alias Mapping

The API returns alternative spellings that are mapped to canonical codes:

```php
private const ALIASES = [
    'ALL INCL'              => 'AI',
    'ALL INCLUSIVE'         => 'AI',
    'ALLINC'               => 'AI',
    'ULTRA ALL INCL'       => 'UAI',
    'ULTRA ALL INCLUSIVE'   => 'UAI',
    'FULL BOARD'           => 'FB',
    'HALF BOARD'           => 'HB',
    'BED AND BREAKFAST'    => 'BB',
    'B&B'                  => 'BB',
    'ROOM ONLY'            => 'RO',
    'SELF CATERING'        => 'SC',
];
```

#### Key Methods

| Method | Input | Output | Description |
|--------|-------|--------|-------------|
| `fromApiCode($code)` | `"ALL INCL"` | `BoardType` instance or `null` | Creates a value object from any API code or alias |
| `toCanonicalCode($code)` | `"ALL INCL"` | `"AI"` | Resolves any alias to its canonical form. Returns uppercased input if unrecognized |
| `isValid($code)` | `"AI"` | `true` | Checks if the code (or alias) is recognized |
| `toDisplayName($code)` | `"AI"` | `"All Inclusive"` | Converts to human-readable name |
| `matchesMealPlan($boardId, $mealPlan)` | `"FB+", "FB"` | `true` | Handles "plus variant" matching (FB matches FB+) |
| `allDisplayNames()` | — | `['AI' => 'All Inclusive', ...]` | Returns the canonical code to display name map |
| `allCodes()` | — | `['AI', 'UAI', 'FB', ...]` | Returns all canonical codes |

#### How `fromApiCode()` Works

```php
public static function fromApiCode(string $apiCode): ?self
{
    $normalized = strtoupper(trim($apiCode));

    // Step 1: Check if it's already a canonical code
    if (isset(self::DISPLAY_NAMES[$normalized])) {
        return new self($normalized);
    }

    // Step 2: Check alias table
    if (isset(self::ALIASES[$normalized])) {
        return new self(self::ALIASES[$normalized]);
    }

    // Step 3: Unrecognized
    return null;
}
```

#### Plus-Variant Matching

When a user searches for "Full Board" hotels, they should also see "Full Board Plus" results. The `matchesMealPlan()` method handles this:

```php
public static function matchesMealPlan(string $boardId, string $mealPlan): bool
{
    $canonical = self::toCanonicalCode($boardId);
    $mealPlan  = strtoupper(trim($mealPlan));

    // Exact match: "AI" == "AI"
    if ($canonical === $mealPlan) {
        return true;
    }

    // Plus-variant match: mealPlan "FB" matches canonical "FB+"
    if ($canonical === $mealPlan . '+') {
        return true;
    }

    return false;
}
```

### 4.2 NovotonNormalizer

**File:** `src/Api/NovotonNormalizer.php`

Implements `ProviderNormalizerInterface`. This is the **only Novoton-specific class** in the feature mapping pipeline.

```php
class NovotonNormalizer implements ProviderNormalizerInterface
{
    public function getProviderName(): string
    {
        return 'novoton';
    }

    public function normalizeBoardCode(string $rawValue): ?string
    {
        $trimmed = trim($rawValue);
        if ($trimmed === '') return null;

        $canonical = BoardType::toCanonicalCode($trimmed);
        return BoardType::isValid($canonical) ? $canonical : null;
    }

    public function normalizeStarRating(string $rawValue): ?string
    {
        // "4*" → "4", "3 Sup" → "3", "five" → null
        $numeric = preg_replace('/[^0-9]/', '', trim($rawValue));
        if ($numeric === '' || $numeric === null) return null;
        $stars = (int) $numeric;
        return ($stars >= 1 && $stars <= 5) ? (string) $stars : null;
    }

    public function normalizeFacilityCode(int|string $facilityId): ?string
    {
        $id = (int) $facilityId;
        return $id > 0 ? (string) $id : null;
    }

    public function normalizeResort(string $rawValue): ?string
    {
        $trimmed = trim($rawValue);
        return $trimmed !== '' ? mb_convert_case($trimmed, MB_CASE_TITLE, 'UTF-8') : null;
    }

    public function normalizePropertyType(string $rawValue): ?string
    {
        $trimmed = trim($rawValue);
        if ($trimmed === '') return null;
        return (new PropertyTypeDetector())->detectFromName($trimmed);
    }
}
```

#### Normalization Examples

| Method | Input | Output |
|--------|-------|--------|
| `normalizeBoardCode("ALL INCL")` | `"ALL INCL"` | `"AI"` |
| `normalizeBoardCode("FB+")` | `"FB+"` | `"FB+"` |
| `normalizeBoardCode("UNKNOWN_BOARD")` | `"UNKNOWN_BOARD"` | `null` |
| `normalizeStarRating("4*")` | `"4*"` | `"4"` |
| `normalizeStarRating("3 Sup")` | `"3 Sup"` | `"3"` |
| `normalizeFacilityCode(42)` | `42` | `"42"` |
| `normalizeResort("SUNNY BEACH")` | `"SUNNY BEACH"` | `"Sunny Beach"` |

### 4.3 ProviderNormalizerInterface

**File:** `src/Api/ProviderNormalizerInterface.php`

The contract that all providers must implement:

```php
interface ProviderNormalizerInterface
{
    public function getProviderName(): string;
    public function normalizeStarRating(string $rawValue): ?string;
    public function normalizeBoardCode(string $rawValue): ?string;
    public function normalizeFacilityCode(int|string $facilityId): ?string;
    public function normalizeResort(string $rawValue): ?string;
    public function normalizePropertyType(string $rawValue): ?string;
}
```

Each method returns `null` for invalid/unrecognized values, which causes the value to be silently skipped (not assigned to any product).

---

## 5. Layer 3: Mapping Table (`hotel_feature_mappings`)

### 5.1 Table Schema

Defined in `addon.xml` (lines 687–709):

```sql
CREATE TABLE IF NOT EXISTS `?:hotel_feature_mappings` (
    `mapping_id`          int(11) unsigned NOT NULL AUTO_INCREMENT,
    `provider`            varchar(50)  NOT NULL DEFAULT 'novoton',
    `feature_type`        varchar(50)  NOT NULL,
    `provider_code`       varchar(255) NOT NULL,
    `cs_cart_feature_id`  int(11) unsigned NOT NULL,
    `cs_cart_variant_id`  int(11) unsigned DEFAULT NULL,
    `variant_source`      enum('auto','manual') DEFAULT NULL,
    `cs_cart_feature_type` char(1)     NOT NULL DEFAULT 'S',
    `display_name_en`     varchar(255) DEFAULT NULL,
    `display_name_ro`     varchar(255) DEFAULT NULL,
    `position`            int(5)       DEFAULT 0,
    `is_active`           enum('Y','N') NOT NULL DEFAULT 'Y',
    `mapping_source`      enum('seed','auto','manual') NOT NULL DEFAULT 'seed',
    `last_synced_at`      datetime     DEFAULT NULL,
    `created_at`          datetime     DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`mapping_id`),
    UNIQUE KEY `idx_provider_type_code` (`provider`, `feature_type`, `provider_code`(191)),
    KEY `idx_feature_type` (`feature_type`),
    KEY `idx_cs_cart_feature` (`cs_cart_feature_id`),
    KEY `idx_active` (`is_active`),
    KEY `idx_mapping_source` (`mapping_source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 5.2 Column Reference

| Column | Type | Purpose |
|--------|------|---------|
| `mapping_id` | int AUTO_INCREMENT | Primary key |
| `provider` | varchar(50) | Provider name: `'novoton'` or `'sphinx'`. Allows same canonical code to exist for multiple providers |
| `feature_type` | varchar(50) | One of: `star_rating`, `board`, `hotel_facility`, `room_facility`, `resort`, `property_type`, `travel_group`, `beach_access` |
| `provider_code` | varchar(255) | The canonical code after normalization: `'AI'`, `'FB+'`, `'4'`, `'42'` (facility_id), `'SUNNY BEACH'` (resort name) |
| `cs_cart_feature_id` | int | Links to CS-Cart's `product_features.feature_id`. Configured via addon settings |
| `cs_cart_variant_id` | int or NULL | Links to CS-Cart's `product_feature_variants.variant_id`. Auto-populated on first use |
| `variant_source` | enum or NULL | How the variant was resolved: `'auto'` = name-matched or auto-created by `ensureVariantExists()`, `'manual'` = set by admin via dropdown. `NULL` = unresolved. **Manual locks are never overwritten by auto-resolve.** |
| `cs_cart_feature_type` | char(1) | `'S'` = SelectBox (single value), `'M'` = Multiple Checkboxes. Cached from CS-Cart's actual feature type |
| `display_name_en` | varchar(255) | English name for auto-creating CS-Cart variants (e.g., `"All Inclusive"`) |
| `display_name_ro` | varchar(255) | Romanian name for auto-creating CS-Cart variants (e.g., `"All Inclusive"`) |
| `position` | int | Sort order when creating variants (10, 20, 30...) |
| `is_active` | enum('Y','N') | Inactive mappings are skipped during sync |
| `mapping_source` | enum | `'seed'` = created during install, `'auto'` = discovered from API, `'manual'` = admin-created |
| `last_synced_at` | datetime | Last time this code was seen from the API (updated on every sync) |
| `created_at` | datetime | Row creation timestamp |
| `updated_at` | timestamp | Auto-updated on any change |

### 5.3 The Unique Constraint

```sql
UNIQUE KEY `idx_provider_type_code` (`provider`, `feature_type`, `provider_code`(191))
```

This means: **one row per (provider, feature_type, provider_code) combination**. The same canonical code can exist for different providers:

| mapping_id | provider | feature_type | provider_code | display_name_en |
|-----------|----------|-------------|---------------|-----------------|
| 7 | `novoton` | `board` | `AI` | All Inclusive |
| 8 | `novoton` | `board` | `FB` | Full Board |
| 9 | `novoton` | `board` | `FB+` | Full Board Plus |
| 50 | `sphinx` | `board` | `AI` | All Inclusive |
| 51 | `sphinx` | `board` | `FB` | Full Board |

Both providers can point to the **same** `cs_cart_feature_id` (same CS-Cart feature), but they're separate rows so each can be managed independently.

### 5.4 How Rows Get Created

| Source | When | Example |
|--------|------|---------|
| **Seed** (`mapping_source='seed'`) | Addon install or admin re-seed | All 9 board codes, 5 star ratings, 10 property types |
| **Auto** (`mapping_source='auto'`) | During API sync when an unknown code is found in a **dynamic** feature type | New hotel facility ID `"187"` discovered from API |
| **Manual** (`mapping_source='manual'`) | Admin creates mapping via the Feature Mappings UI | Custom mapping added by admin |

**Important:** Seed rows respect manual edits — if a row has `mapping_source='manual'`, re-seeding will NOT overwrite it.

### 5.5 FeatureMappingRepository

**File:** `src/Repository/FeatureMappingRepository.php`

Provides all CRUD operations plus **in-memory caching** to avoid repeated DB queries during batch sync operations (e.g., when syncing 1000+ hotels).

#### Key Methods

| Method | Signature | Description |
|--------|-----------|-------------|
| `findMapping()` | `(provider, featureType, providerCode) → ?array` | Core lookup. Returns full row or null. **Cached in memory** |
| `findByFeatureType()` | `(featureType, provider) → array` | All active mappings for a type, indexed by `provider_code` |
| `getFeatureId()` | `(featureType, provider) → ?int` | Get the CS-Cart feature_id for a feature type. **Cached** |
| `getCsCartFeatureType()` | `(featureType, provider) → ?string` | Returns `'S'` or `'M'` from the mapping table |
| `registerUnmapped()` | `(provider, featureType, providerCode, displayName) → int` | Auto-creates a row with `mapping_source='auto'` for dynamic types. Uses `INSERT IGNORE` to avoid duplicates |
| `save()` | `(data) → int` | Upsert: if unique key exists, update; otherwise insert. Respects `mapping_source='manual'` |
| `updateVariantId()` | `(mappingId, variantId) → bool` | Stores the auto-created CS-Cart variant ID back in the mapping row |
| `updateLastSynced()` | `(mappingId) → bool` | Updates `last_synced_at` timestamp |
| `delete()` | `(mappingId) → bool` | Deletes a mapping row |

#### In-Memory Cache

The repository maintains two caches to prevent repeated DB queries during batch processing:

```php
/** "provider:featureType:providerCode" => row|null */
private array $cache = [];

/** "featureType:provider" => feature_id */
private array $featureIdCache = [];
```

Both caches are cleared on any write operation (`save()`, `delete()`, `updateVariantId()`). This means:
- During a sync of 1000 hotels, `findMapping('novoton', 'board', 'AI')` hits the database once, then returns cached
- After any write, the cache is invalidated to ensure consistency

---

## 6. Layer 4: FeatureMapper — Writing to CS-Cart Products

**File:** `src/Services/FeatureMapper.php`

The FeatureMapper is the **central service** that takes a canonical code and writes it to a CS-Cart product. It handles variant auto-creation, assignment strategy selection, and diff-based sync.

### 6.1 `assignFeatureToProduct()` — Single Value

```php
public function assignFeatureToProduct(
    int $productId,
    string $featureType,
    string $providerCode,
    string $provider = 'novoton'
): bool
```

**Step-by-step flow:**

```
Step 1: findMapping(provider, featureType, providerCode)
        │
        ├── mapping found → continue
        │
        └── mapping is null → handleUnmapped()
            ├── Strict type (board, star_rating, property_type)
            │   → Log warning + return false (SKIP)
            │
            └── Dynamic type (hotel_facility, room_facility, resort)
                → registerUnmapped() → auto-create row + return false

Step 2: updateLastSynced(mapping_id)
        → Track that this code was seen from the API

Step 3: ensureVariantExists(mapping)
        │
        ├── cs_cart_variant_id > 0 AND exists in CS-Cart → use it (fast path)
        │
        ├── variant_source = 'manual' → SKIP auto-resolve (admin locked it)
        │   └── return 0 if variant deleted, or existing id
        │
        ├── findVariantByName(mapping) → 3-pass fuzzy match
        │   ├── Pass 1: Exact name match (EN, then RO)
        │   ├── Pass 2: Case-insensitive LOWER() match
        │   └── Pass 3: Normalized match (strip punctuation, collapse whitespace)
        │   └── if found → updateVariantId(mapping_id, matched_id, 'auto')
        │
        └── No match → createCsCartVariant()
            ├── INSERT INTO product_feature_variants (feature_id, position)
            ├── INSERT INTO product_feature_variant_descriptions (for each active language)
            └── updateVariantId(mapping_id, new_variant_id, 'auto')

Step 4: Assign based on cs_cart_feature_type
        │
        ├── 'S' (SelectBox) → assignSelectBox()
        │   ├── DELETE all old values for (feature_id, product_id)
        │   └── INSERT new value for each active language
        │
        └── 'M' (Multiple Checkboxes) → assignCheckbox()
            └── INSERT if not already present for each active language
```

### 6.2 `assignMultipleToProduct()` — Batch Values with Diff

```php
public function assignMultipleToProduct(
    int $productId,
    string $featureType,
    array $providerCodes,
    string $provider = 'novoton'
): int
```

For **SelectBox (S)** features: only the last code in the array wins (overwrite).

For **Multiple Checkboxes (M)** features: performs a **diff-based merge**:

```
Step 1: Resolve all provider codes to variant IDs
        ['AI', 'FB+', 'HB'] → [42, 45, 48]

Step 2: Get current variant IDs on the product
        SELECT variant_id FROM product_features_values
        WHERE feature_id = 15 AND product_id = 100
        → [42, 50]  (AI and BB were previously assigned)

Step 3: Calculate diff
        New:     [42 (AI), 45 (FB+), 48 (HB)]
        Current: [42 (AI), 50 (BB)]
        ─────────────────────────────────────
        To add:    [45 (FB+), 48 (HB)]   ← new from API
        To remove: [50 (BB)]              ← no longer from API

Step 4: Execute diff
        DELETE variant_id=50 (BB) from product_features_values
        INSERT variant_id=45 (FB+) for each language
        INSERT variant_id=48 (HB) for each language
        KEEP   variant_id=42 (AI) — already present, no action
```

This diff approach is critical for avoiding unnecessary deletes/inserts and for handling hotels whose meal plans change over time.

### 6.3 `ensureVariantExists()` — Auto-Resolving & Creating CS-Cart Variants

When a mapping row's `cs_cart_variant_id` is null (first use) or the variant was deleted from CS-Cart, the FeatureMapper resolves it using a multi-step strategy:

#### Resolution Order

```
┌─────────────────────────────────────────────────────────┐
│ variant_id = 0/NULL, variant_source = NULL → Unresolved │
│   ↓ Step 1: Check if stored variant_id exists in DB     │
│   ↓ Step 2: If variant_source = 'manual' → SKIP         │
│   ↓ Step 3: findVariantByName() — 3-pass fuzzy match    │
│   ↓ Step 4: If no match → createCsCartVariant()         │
│   ↓ Sets variant_id + variant_source = 'auto'           │
│                                                         │
│ variant_source = 'auto' → Auto-resolved                 │
│   ✓ Auto-resolve CAN re-resolve (if variant deleted)    │
│   ✓ Admin can override via dropdown → becomes 'manual'  │
│                                                         │
│ variant_source = 'manual' → LOCKED                      │
│   ✗ Auto-resolve SKIPS this row entirely                │
│   ✓ Admin can change via dropdown (stays 'manual')      │
│   ✓ Admin picks "Not mapped" → resets to NULL (unlocked)│
└─────────────────────────────────────────────────────────┘
```

#### `findVariantByName()` — 3-Pass Fuzzy Match

Searches existing CS-Cart variants by display name. Tries EN first, then RO fallback. For each language:

| Pass | Strategy | Example Match |
|------|----------|---------------|
| 1 | **Exact** — `WHERE vd.variant = ?s` | `"All Inclusive"` = `"All Inclusive"` |
| 2 | **Case-insensitive** — `WHERE LOWER(vd.variant) = LOWER(?s)` | `"all inclusive"` = `"All Inclusive"` |
| 3 | **Normalized** — strip non-alphanumeric, collapse whitespace | `"All-Inclusive"` = `"All Inclusive"`, `"HALF BOARD+"` = `"Half Board +"` |

Returns the first match found. If a match is found, the mapping is updated with `variant_source = 'auto'`.

#### `createCsCartVariant()` — Auto-Creation

When no existing variant matches, a new one is created:

```php
private function createCsCartVariant(array $mapping): int
{
    // 1. Create the variant record
    $variantId = db_query("INSERT INTO ?:product_feature_variants ?e", [
        'feature_id' => $mapping['cs_cart_feature_id'],
        'position'   => $mapping['position'],
    ]);

    // 2. Create descriptions for each active language (EN, RO, etc.)
    foreach ($this->getActiveLanguages() as $langCode) {
        $variantName = ($langCode === 'ro')
            ? $mapping['display_name_ro']
            : $mapping['display_name_en'];

        db_query(
            "INSERT INTO ?:product_feature_variant_descriptions
             (variant_id, lang_code, variant)
             VALUES (?i, ?s, ?s)
             ON DUPLICATE KEY UPDATE variant = ?s",
            $variantId, $langCode, $variantName, $variantName
        );
    }

    // 3. Cache the variant_id back in the mapping row
    $this->mappingRepo->updateVariantId($mapping['mapping_id'], $variantId);

    return $variantId;
}
```

### 6.4 Assignment Strategies

#### SelectBox (S) — Overwrite

Used by: `star_rating`, `resort`, `property_type`, `beach_access`

Only **one value** can be active at a time. Assignment deletes the old value and inserts the new one:

```php
private function assignSelectBox(int $productId, int $featureId, int $variantId): bool
{
    // Optimization: skip if already correct
    $existing = db_get_field(
        "SELECT variant_id FROM ?:product_features_values
         WHERE feature_id = ?i AND product_id = ?i AND lang_code = 'en'",
        $featureId, $productId
    );
    if ((int) $existing === $variantId) {
        return true; // Already set, no work needed
    }

    // Delete old, insert new for each language
    db_query("DELETE FROM ?:product_features_values
              WHERE feature_id = ?i AND product_id = ?i",
              $featureId, $productId);

    foreach ($this->getActiveLanguages() as $langCode) {
        db_query("INSERT INTO ?:product_features_values ?e
                  ON DUPLICATE KEY UPDATE variant_id = ?i, value_int = ?i",
            [
                'feature_id' => $featureId,
                'product_id' => $productId,
                'variant_id' => $variantId,
                'value'      => '',
                'value_int'  => $variantId,
                'lang_code'  => $langCode,
            ],
            $variantId, $variantId
        );
    }
    return true;
}
```

#### Multiple Checkboxes (M) — Merge

Used by: `board`, `hotel_facility`, `room_facility`, `travel_group`

**Multiple values** can be active at the same time. Single-value assignment adds without removing:

```php
private function assignCheckbox(int $productId, int $featureId, int $variantId): bool
{
    // Check if already assigned
    $exists = db_get_field(
        "SELECT 1 FROM ?:product_features_values
         WHERE feature_id = ?i AND product_id = ?i AND variant_id = ?i AND lang_code = 'en'",
        $featureId, $productId, $variantId
    );
    if ($exists) {
        return true; // Already present, no action
    }

    // Insert for each language
    foreach ($this->getActiveLanguages() as $langCode) {
        db_query("INSERT INTO ?:product_features_values ?e
                  ON DUPLICATE KEY UPDATE variant_id = ?i",
            [
                'feature_id' => $featureId,
                'product_id' => $productId,
                'variant_id' => $variantId,
                'value'      => '',
                'value_int'  => $variantId,
                'lang_code'  => $langCode,
            ],
            $variantId
        );
    }
    return true;
}
```

### 6.5 `handleUnmapped()` — What Happens With Unknown Codes

```php
private function handleUnmapped(string $provider, string $featureType, string $providerCode): bool
{
    // Strict types: log warning and skip
    if (in_array($featureType, Constants::STRICT_FEATURE_TYPES, true)) {
        fn_log_event('general', 'runtime', [
            'message'       => "FeatureMapper: Unmapped strict value skipped",
            'provider'      => $provider,
            'feature_type'  => $featureType,
            'provider_code' => $providerCode,
        ]);
        return false;
    }

    // Dynamic types: auto-register in mapping table
    if (in_array($featureType, Constants::DYNAMIC_FEATURE_TYPES, true)) {
        $this->mappingRepo->registerUnmapped($provider, $featureType, $providerCode);
        return false; // Still returns false — auto-registered but NOT assigned this run
    }

    return false;
}
```

**Key behavior:** Even auto-registered dynamic mappings return `false` on first encounter. They are assigned on the *next* sync run after the mapping row exists and is activated.

---

## 7. Feature Types Reference

Defined in `Constants.php` (lines 172–223):

| Feature Type | Constant | CS-Cart Type | Classification | Unknown Code Behavior | Addon Setting Key | Example Codes |
|-------------|----------|-------------|----------------|----------------------|-------------------|--------------|
| Star Rating | `FEATURE_TYPE_STAR_RATING` | `S` (SelectBox) | **Strict** | Logged + skipped | `feature_id_star_rating` | `1`, `2`, `3`, `4`, `5` |
| Board / Meal | `FEATURE_TYPE_BOARD` | `M` (Multiple Checkboxes) | **Strict** | Logged + skipped | `feature_id_board` | `AI`, `FB`, `FB+`, `HB`, `BB`, `RO` |
| Property Type | `FEATURE_TYPE_PROPERTY_TYPE` | `S` (SelectBox) | **Strict** | Logged + skipped | `feature_id_property_type` | `hotel`, `villa`, `apartment` |
| Travel Group | `FEATURE_TYPE_TRAVEL_GROUP` | `M` (Multiple Checkboxes) | **Strict** | Logged + skipped | `feature_id_travel_group` | `adults_only`, `3`, `23`, `26` |
| Beach Access | `FEATURE_TYPE_BEACH_ACCESS` | `S` (SelectBox) | **Strict** | Logged + skipped | `feature_id_beach_access` | `31` |
| Hotel Facility | `FEATURE_TYPE_HOTEL_FACILITY` | `M` (Multiple Checkboxes) | **Dynamic** | Auto-registered | `feature_id_hotel_facility` | `4`, `15`, `42` (facility IDs) |
| Room Facility | `FEATURE_TYPE_ROOM_FACILITY` | `M` (Multiple Checkboxes) | **Dynamic** | Auto-registered | `feature_id_room_facility` | `8`, `23` (facility IDs) |
| Resort / City | `FEATURE_TYPE_RESORT` | `S` (SelectBox) | **Dynamic** | Auto-registered | `feature_id_resort` | `Sunny Beach`, `Golden Sands` |

### Addon Settings

Each feature type is linked to a CS-Cart feature via an addon setting. The mapping is defined in `Constants::FEATURE_TYPE_TO_SETTING`:

```php
public const FEATURE_TYPE_TO_SETTING = [
    'star_rating'    => 'addons.novoton_holidays.feature_id_star_rating',
    'board'          => 'addons.novoton_holidays.feature_id_board',
    'hotel_facility' => 'addons.novoton_holidays.feature_id_hotel_facility',
    'room_facility'  => 'addons.novoton_holidays.feature_id_room_facility',
    'resort'         => 'addons.novoton_holidays.feature_id_resort',
    'property_type'  => 'addons.novoton_holidays.feature_id_property_type',
    'travel_group'   => 'addons.novoton_holidays.feature_id_travel_group',
    'beach_access'   => 'addons.novoton_holidays.feature_id_beach_access',
];
```

These settings are configured as **selectbox dropdowns** in the admin panel (Settings → Feature IDs Mapping tab), showing each CS-Cart feature by name and ID. Each setting holds the `feature_id` from CS-Cart's `product_features` table.

---

## 8. Strict vs Dynamic Feature Types

### Strict Feature Types

```php
public const STRICT_FEATURE_TYPES = [
    'star_rating',      // 5 values: 1-5
    'board',            // 9 values: AI, UAI, FB, FB+, HB, HB+, BB, RO, SC
    'property_type',    // 10 values: hotel, motel, hostel, villa, apartment, etc.
    'travel_group',     // 4 values: adults_only, 3 (pets), 23 (disabilities), 26 (families)
    'beach_access',     // 1 value: 31 (beachfront / first line)
];
```

**Behavior:**
- All valid values are **pre-seeded** during addon installation
- Unknown codes from the API are **logged and skipped** — never auto-created
- This prevents junk data from accumulating (e.g., a typo in an API response creating a new board type)
- Admin must manually add new codes if the API introduces new values

### Dynamic Feature Types

```php
public const DYNAMIC_FEATURE_TYPES = [
    'hotel_facility',   // Hundreds of possible facility IDs
    'room_facility',    // Dozens of possible facility IDs
    'resort',           // New resorts appear as hotels are added
];
```

**Behavior:**
- Unknown codes are **auto-registered** via `registerUnmapped()` with `mapping_source='auto'`
- New hotel facilities and resorts are discovered naturally during API sync
- Auto-registered facilities default to `is_active='N'` (admin must activate)
- Auto-registered resorts default to `is_active='Y'` (immediately usable)
- Display name defaults to the provider code itself (admin can rename later)

---

## 9. Seed Data

### Seeding Function

**File:** `functions/hotels.php` — `fn_novoton_holidays_seed_feature_mappings()`

Called during addon install and via the admin "Re-seed" button. Seeds all strict feature type values.

### Facility-to-Feature Routing

Certain hotel facility IDs are **rerouted** from the generic `hotel_facility` feature to more specific feature types during seeding. This routing is **data-driven via the `hotel_feature_mappings` table** — not hardcoded constants — so admins can edit the mappings from the admin UI.

| Facility ID | Facility Name | Routed To Feature Type | Display Name (EN) |
|------------|---------------|----------------------|-------------------|
| 3 | Pets | `travel_group` | Pets allowed |
| 23 | Disabilities | `travel_group` | Suitable for people with disabilities |
| 26 | Families | `travel_group` | Suitable for families with children |
| 31 | First line | `beach_access` | Beachfront |
| `adults_only` | (detected from hotel name) | `travel_group` | Adults only |

During product sync, `AddProductsCommand::assignProductFeatures()` looks up each facility's `feature_type` from the mapping table via `findFeatureTypeForCode()`. If a facility is seeded under `travel_group` or `beach_access`, it gets assigned to that feature instead of `hotel_facility`. Facilities not found in the mapping table default to `hotel_facility`.

### Adults-Only Detection

The `AdultOnlyDetector` class (`src/Api/AdultOnlyDetector.php`) scans hotel names for patterns like "Adults Only", "18+", "No Children", etc. Detection results are stored in the `is_adults_only` column of `novoton_hotels`. During product sync, hotels with `is_adults_only = 'Y'` get the `adults_only` code assigned to `travel_group`.

### Board Codes (9 values)

```php
$boards = [
    'AI'  => ['en' => 'All Inclusive',       'ro' => 'All Inclusive',           'pos' => 10],
    'UAI' => ['en' => 'Ultra All Inclusive',  'ro' => 'Ultra All Inclusive',     'pos' => 20],
    'FB'  => ['en' => 'Full Board',           'ro' => 'Pensiune Completă',      'pos' => 30],
    'FB+' => ['en' => 'Full Board Plus',      'ro' => 'Pensiune Completă Plus', 'pos' => 40],
    'HB'  => ['en' => 'Half Board',           'ro' => 'Demipensiune',           'pos' => 50],
    'HB+' => ['en' => 'Half Board Plus',      'ro' => 'Demipensiune Plus',      'pos' => 60],
    'BB'  => ['en' => 'Bed & Breakfast',      'ro' => 'Mic Dejun Inclus',       'pos' => 70],
    'RO'  => ['en' => 'Room Only',            'ro' => 'Doar Cazare',            'pos' => 80],
    'SC'  => ['en' => 'Self Catering',        'ro' => 'Self Catering',          'pos' => 90],
];
```

### Property Types (10 values)

```php
$propTypes = [
    'hotel'          => ['en' => 'Hotel',          'ro' => 'Hotel',          'pos' => 10],
    'motel'          => ['en' => 'Motel',          'ro' => 'Motel',          'pos' => 20],
    'hostel'         => ['en' => 'Hostel',         'ro' => 'Hostel',         'pos' => 30],
    'villa'          => ['en' => 'Villa',          'ro' => 'Vilă',           'pos' => 40],
    'apartment'      => ['en' => 'Apartment',      'ro' => 'Apartament',     'pos' => 50],
    'boarding-house' => ['en' => 'Boarding House',  'ro' => 'Pensiune',       'pos' => 60],
    'cabin'          => ['en' => 'Cabin',          'ro' => 'Cabană',         'pos' => 70],
    'chalet'         => ['en' => 'Chalet',         'ro' => 'Chalet',         'pos' => 80],
    'guest-house'    => ['en' => 'Guest House',    'ro' => 'Pensiune',       'pos' => 90],
    'resort'         => ['en' => 'Resort',         'ro' => 'Resort',         'pos' => 100],
];
```

### Travel Group (4 values)

```php
$travelGroupItems = [
    'adults_only' => ['en' => 'Adults only',                          'ro' => 'Exclusiv pentru adulți',               'pos' => 10],
    '3'           => ['en' => 'Pets allowed',                          'ro' => 'Acceptă animale de companie',          'pos' => 20],
    '26'          => ['en' => 'Suitable for families with children',   'ro' => 'Ideal pentru familii cu copii',        'pos' => 30],
    '23'          => ['en' => 'Suitable for people with disabilities', 'ro' => 'Accesibil persoanelor cu dizabilități', 'pos' => 40],
];
```

### Beach Access (1 value)

```php
// provider_code '31' maps to Novoton facility ID 31 (First line / Beachfront)
$repo->save([..., 'provider_code' => '31', 'display_name_en' => 'Beachfront', 'display_name_ro' => 'La malul mării']);
```

### Star Ratings (5 values)

Codes `"1"` through `"5"`, with display names like `"1 Star"` / `"1 Stea"`, `"5 Stars"` / `"5 Stele"`.

### Seeding Flow

For each code:
1. Read `cs_cart_feature_id` from addon settings via `Constants::FEATURE_TYPE_TO_SETTING`
2. Read actual CS-Cart feature type (`'S'` or `'M'`) from `product_features` table
3. Call `repo->save()` to upsert — inserts if new, updates if exists (but respects `mapping_source='manual'`)
4. If the addon setting is not configured (feature_id = 0), the entire group is **skipped** with a count

---

## 10. End-to-End Board Type Walkthrough

Let's trace what happens when a hotel with boards `["ALL INCL", "FB+", "HB"]` is synced as a CS-Cart product.

### Step 1: API Data Extraction

In `AddProductsCommand::assignProductFeatures()`:

```php
$hotelData = fn_novoton_holidays_get_hotel_data('HTL-12345');
// $hotelData['boards'] = [
//     ['IdBoard' => 'ALL INCL'],
//     ['IdBoard' => 'FB+'],
//     ['IdBoard' => 'HB'],
// ]
```

### Step 2: Normalization

Each raw value passes through `NovotonNormalizer::normalizeBoardCode()`:

```
"ALL INCL"  →  BoardType::toCanonicalCode("ALL INCL")
            →  ALIASES["ALL INCL"] = "AI"
            →  isValid("AI") = true
            →  returns "AI"

"FB+"       →  BoardType::toCanonicalCode("FB+")
            →  DISPLAY_NAMES["FB+"] exists
            →  isValid("FB+") = true
            →  returns "FB+"

"HB"        →  BoardType::toCanonicalCode("HB")
            →  DISPLAY_NAMES["HB"] exists
            →  isValid("HB") = true
            →  returns "HB"
```

Result: `$boardCodes = ['AI', 'FB+', 'HB']`

### Step 3: Batch Assignment

```php
$featureMapper->assignMultipleToProduct(
    $productId,                    // 100
    Constants::FEATURE_TYPE_BOARD, // 'board'
    ['AI', 'FB+', 'HB'],          // canonical codes
    'novoton'
);
```

### Step 4: Resolve Codes to Mappings

For each code, `findMapping('novoton', 'board', $code)` is called:

```
findMapping('novoton', 'board', 'AI')  → {mapping_id: 7,  cs_cart_variant_id: 42, ...}
findMapping('novoton', 'board', 'FB+') → {mapping_id: 9,  cs_cart_variant_id: 45, ...}
findMapping('novoton', 'board', 'HB')  → {mapping_id: 11, cs_cart_variant_id: 48, ...}
```

### Step 5: Ensure Variants Exist

For each mapping, `ensureVariantExists()` checks if the variant still exists in CS-Cart:

```
variant_id=42 → SELECT from product_feature_variants → exists ✓
variant_id=45 → SELECT from product_feature_variants → exists ✓
variant_id=48 → SELECT from product_feature_variants → exists ✓
```

If a variant had been deleted, `createCsCartVariant()` would auto-create it and cache the new ID.

### Step 6: Diff-Based Merge

```
New variant IDs from API:    [42 (AI), 45 (FB+), 48 (HB)]
Current on product (from DB): [42 (AI), 50 (BB)]

Diff:
  To add:    [45 (FB+), 48 (HB)]   ← new meal plans from API
  To remove: [50 (BB)]              ← hotel no longer offers B&B
  Unchanged: [42 (AI)]              ← keep as-is, no DB queries
```

### Step 7: Execute Database Operations

```sql
-- Remove stale BB variant
DELETE FROM product_features_values
WHERE feature_id = 15 AND product_id = 100 AND variant_id = 50;

-- Add FB+ for each language
INSERT INTO product_features_values
(feature_id, product_id, variant_id, value, value_int, lang_code)
VALUES (15, 100, 45, '', 45, 'en')
ON DUPLICATE KEY UPDATE variant_id = 45;

INSERT INTO product_features_values
(feature_id, product_id, variant_id, value, value_int, lang_code)
VALUES (15, 100, 45, '', 45, 'ro')
ON DUPLICATE KEY UPDATE variant_id = 45;

-- Add HB for each language (same pattern)
INSERT INTO product_features_values ... (variant_id=48, lang_code='en');
INSERT INTO product_features_values ... (variant_id=48, lang_code='ro');
```

### Final Result

Product 100 now has these board/meal features in CS-Cart:

| variant_id | Display Name (EN) | Display Name (RO) |
|-----------|-------------------|-------------------|
| 42 | All Inclusive | All Inclusive |
| 45 | Full Board Plus | Pensiune Completă Plus |
| 48 | Half Board | Demipensiune |

---

## 11. Complete Data Flow Diagram

```
NOVOTON API                          SPHINX API (future)
  │                                    │
  │ "ALL INCL", "FB+", "HB"           │ "PENSION COMPLETA", "ALL INCLUSIVE"
  │ "4*"                               │ classification: 5
  │ facility_ids: [4, 15, 42]          │ amenities: ["pool", "spa"]
  │                                    │
  ▼                                    ▼
┌──────────────────────┐   ┌──────────────────────┐
│  NovotonNormalizer   │   │  SphinxNormalizer     │
│  (implements         │   │  (implements          │
│   ProviderNormalizer │   │   ProviderNormalizer  │
│   Interface)         │   │   Interface)          │
│                      │   │                       │
│  normalizeBoardCode: │   │  normalizeBoardCode:  │
│  "ALL INCL" → "AI"   │   │  "PENSION COMPLETA"   │
│  "FB+" → "FB+"       │   │       → "FB"          │
│  "HB" → "HB"        │   │  "ALL INCLUSIVE"       │
│                      │   │       → "AI"           │
│  normalizeStarRating:│   │                       │
│  "4*" → "4"          │   │  normalizeStarRating: │
│                      │   │  5 → "5"              │
└──────────┬───────────┘   └──────────┬────────────┘
           │                          │
           │  Canonical codes         │  Canonical codes
           │  "AI", "FB+", "HB"      │  "FB", "AI"
           │  "4"                     │  "5"
           ▼                          ▼
┌─────────────────────────────────────────────────────────┐
│              hotel_feature_mappings (hub table)          │
│                                                         │
│  provider   │ feature_type │ provider_code │ variant_id │
│  ───────────┼──────────────┼───────────────┼──────────  │
│  novoton    │ board        │ AI            │ 42         │
│  novoton    │ board        │ FB+           │ 45         │
│  novoton    │ board        │ HB            │ 48         │
│  novoton    │ star_rating  │ 4             │ 30         │
│  sphinx     │ board        │ AI            │ 42         │  ← same variant!
│  sphinx     │ board        │ FB            │ 44         │
│  sphinx     │ star_rating  │ 5             │ 31         │
└────────────────────────┬────────────────────────────────┘
                         │
                         ▼
                ┌─────────────────┐
                │  FeatureMapper  │
                │                 │
                │  S-type:        │
                │  DELETE + INSERT │
                │  (overwrite)    │
                │                 │
                │  M-type:        │
                │  diff-based     │
                │  merge          │
                │  (add/remove)   │
                └────────┬────────┘
                         │
                         ▼
              ┌────────────────────────┐
              │  product_features      │
              │  _values               │
              │                        │
              │  product_id │ variant  │
              │  ───────────┼────────  │
              │  100        │ 42 (AI)  │
              │  100        │ 45 (FB+) │
              │  100        │ 48 (HB)  │
              │  100        │ 30 (4★)  │
              └────────────────────────┘
```

---

## 12. Adding a New Provider (Sphinx Example)

The feature mapping system is designed for easy provider extension. Only **one class** needs to be created — the normalizer. Everything else is shared.

### Step 1: Create SphinxNormalizer

```php
// src/Api/SphinxNormalizer.php
class SphinxNormalizer implements ProviderNormalizerInterface
{
    public function getProviderName(): string
    {
        return 'sphinx';
    }

    public function normalizeBoardCode(string $rawValue): ?string
    {
        // Sphinx returns free-text meal types + meal_type_category_id
        // Map Sphinx-specific values to same canonical codes
        $map = [
            'ALL INCLUSIVE'       => 'AI',
            'ALL INCLUSIVE PLUS'  => 'UAI',
            'PENSION COMPLETA'   => 'FB',
            'DEMIPENSIUNE'       => 'HB',
            'MIC DEJUN'          => 'BB',
            // ...
        ];

        $upper = strtoupper(trim($rawValue));
        $canonical = $map[$upper] ?? null;

        return ($canonical !== null && BoardType::isValid($canonical))
            ? $canonical
            : null;
    }

    public function normalizeStarRating(string $rawValue): ?string
    {
        // Sphinx returns classification as plain int
        $stars = (int) $rawValue;
        return ($stars >= 1 && $stars <= 5) ? (string) $stars : null;
    }

    // ... other methods
}
```

### Step 2: Seed Sphinx Mapping Rows

Run a seeding function (or let auto-registration handle it) to create `provider='sphinx'` rows:

```php
$repo->save([
    'provider'           => 'sphinx',
    'feature_type'       => 'board',
    'provider_code'      => 'AI',
    'cs_cart_feature_id' => $boardFeatureId,  // Same CS-Cart feature!
    'cs_cart_feature_type' => 'M',
    'display_name_en'    => 'All Inclusive',
    'display_name_ro'    => 'All Inclusive',
    'mapping_source'     => 'seed',
]);
```

### Step 3: Use FeatureMapper with Provider Parameter

```php
$sphinxNormalizer = new SphinxNormalizer();
$code = $sphinxNormalizer->normalizeBoardCode('PENSION COMPLETA'); // → "FB"

$featureMapper->assignFeatureToProduct(
    $productId,
    Constants::FEATURE_TYPE_BOARD,
    $code,
    'sphinx'  // ← only difference from Novoton
);
```

### What's Shared vs Provider-Specific

| Component | Shared? | Notes |
|-----------|---------|-------|
| `ProviderNormalizerInterface` | Shared contract | Both providers implement it |
| `NovotonNormalizer` / `SphinxNormalizer` | **Provider-specific** | Only provider-specific code |
| `BoardType` value object | Shared | Canonical codes are universal |
| `hotel_feature_mappings` table | Shared | Provider column distinguishes rows |
| `FeatureMappingRepository` | Shared | Queries by provider parameter |
| `FeatureMapper` | Shared | Provider parameter passed through |
| CS-Cart product features | Shared | Same feature_id for both providers |

---

## 13. Admin UI Management

**File:** `controllers/backend/novoton_feature_mappings.php`

Accessible at `admin.php?dispatch=novoton_feature_mappings.manage`

### Available Actions

| Action | Mode | Description |
|--------|------|-------------|
| **List/Filter** | `manage` | View all mappings grouped by feature type with **Variant Name** column. Filter by: feature type, mapping source (seed/auto/manual), active status |
| **Edit** | `edit` | Edit a single mapping: display names (EN/RO), position, active status, CS-Cart feature ID, **CS-Cart variant dropdown** |
| **Bulk Activate** | `bulk_update` (activate) | Activate multiple selected mappings |
| **Bulk Deactivate** | `bulk_update` (deactivate) | Deactivate multiple selected mappings |
| **Bulk Delete** | `bulk_update` (delete) | Delete multiple selected mappings |
| **Re-seed** | `reseed` | Re-run `fn_novoton_holidays_seed_feature_mappings()` to restore/update seed data |
| **Auto-resolve Variants** | `resolve_variants` | Batch name-match unmapped variants using `findVariantByName()`. Skips `variant_source='manual'` rows. Creates new variants when no match found |

### Variant Source Tracking

The `variant_source` column tracks how each mapping's variant was resolved:

| Value | Meaning | Auto-resolve behavior |
|-------|---------|----------------------|
| `NULL` | Unresolved — no variant assigned yet | Will be auto-resolved |
| `'auto'` | Set by `ensureVariantExists()` or "Auto-resolve Variants" button | Can be re-resolved if variant deleted |
| `'manual'` | Set by admin via the variant dropdown in edit form | **Locked** — auto-resolve skips this row |

**Admin workflow:**
1. Click "Auto-resolve Variants" → bulk-matches all unmapped rows by display name
2. Edit a mapping → pick a variant from the dropdown → `variant_source = 'manual'` (locked)
3. Edit a mapping → pick "Not mapped" → `variant_source = NULL` (unlocked for auto-resolve)

### Dashboard Stats

The manage page shows:
- **Total** mappings count
- **Active** mappings count
- **Auto-discovered** mappings count (from API sync)
- **Unmapped** count (rows without a `cs_cart_variant_id`)

### Permission Note

The controller is restricted:
- Not available in Multivendor mode
- Not available for restricted admin users
- Only full admin access can manage feature mappings

---

## 14. Source File Reference

| File | Component | Description |
|------|-----------|-------------|
| `Constants.php` | Constants | Feature type constants (8 types), strict/dynamic classification, addon setting mappings |
| `src/ValueObjects/BoardType.php` | Value Object | Canonical board codes, alias mapping, validation, plus-variant matching |
| `src/Api/ProviderNormalizerInterface.php` | Interface | Contract for provider-specific normalization (5 methods) |
| `src/Api/NovotonNormalizer.php` | Normalizer | Novoton-specific implementation: raw API values → canonical codes. Resort names normalized to Title Case |
| `src/Api/AdultOnlyDetector.php` | Detector | Regex-based detection of adults-only hotels from hotel names |
| `src/Repository/FeatureMappingRepository.php` | Repository | CRUD for `hotel_feature_mappings` table with in-memory cache. Includes `findFeatureTypeForCode()` for data-driven routing |
| `src/Repository/FeatureMappingRepositoryInterface.php` | Interface | Contract for mapping repository (15 methods) |
| `src/Services/FeatureMapper.php` | Service | Central assignment engine: mapping lookup → variant creation → S/M assignment |
| `functions/hotels.php` | Seed Function | Pre-populates all 8 feature type mappings. Reroutes facility IDs 3/23/26/31 to travel_group/beach_access |
| `addon.xml` | Schema | `hotel_feature_mappings` table DDL. Feature ID settings as selectbox dropdowns |
| `controllers/backend/novoton_feature_mappings.php` | Controller | Admin UI for listing (with variant names), editing, bulk operations, and re-seeding |
| `design/backend/templates/.../manage.tpl` | Template | Feature Mappings list view with Variant Name column |
| `src/Cron/Commands/AddProductsCommand.php` | Cron Command | Product creation with data-driven facility routing, adults_only and property_type assignment |
| `func.php` | Settings | `fn_settings_variants_addons_novoton_holidays_feature_ids()` — dynamic selectbox for Feature ID settings |

All file paths are relative to `app/addons/novoton_holidays/`.
