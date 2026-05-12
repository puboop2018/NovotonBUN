<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\FgoInvoicing\Api\FgoApiClient;
use Tygh\Addons\FgoInvoicing\Api\FgoApiException;
use Tygh\Addons\FgoInvoicing\Constants;
use Tygh\Addons\FgoInvoicing\Repository\InvoiceRepository;
use Tygh\Addons\FgoInvoicing\Services\BillingMapper;
use Tygh\Addons\FgoInvoicing\Services\ConfigProvider;
use Tygh\Addons\FgoInvoicing\Services\InvoiceIssuer;

#[CoversClass(InvoiceIssuer::class)]
final class InvoiceIssuerTest extends TestCase
{
    protected function setUp(): void
    {
        ConfigProvider::seed([
            'invoice_type' => 'Factura',
            'invoice_series' => 'F',
            'verify_duplicate' => 'Y',
            'sanitize_vat' => 'N',
            'article_id_field' => 'sku',
            'product_description' => 'N',
            'additional_info' => 'Y',
            'shipping_tax_vat' => 'vat_included',
            'shipping_code' => 'SHIPPING',
            'discount_code' => 'DISCOUNT',
            'administration_code' => '',
            'auto_email_pdf' => 'N',
            'debug_logging' => 'N',
        ]);
    }

    protected function tearDown(): void
    {
        ConfigProvider::reset();
    }

    private function order(): array
    {
        return [
            'order_id' => 1234,
            'user_id' => 7,
            'b_firstname' => 'Ion',
            'b_lastname' => 'Popescu',
            'b_country' => 'RO',
            'b_state' => 'Cluj',
            'b_city' => 'Cluj-Napoca',
            'b_address' => 'Str. Mare 1',
            'email' => 'ion@example.ro',
            'phone' => '+40700111222',
            'currency' => 'RON',
            'subtotal' => 100.0,
            'subtotal_tax_amount' => 21.0,
            'shipping_cost' => 0,
            'products' => [
                ['product' => 'Pernă', 'product_code' => 'SKU-1', 'amount' => 1, 'subtotal' => 100.0, 'tax_value' => 21.0],
            ],
        ];
    }

    public function testSuccessPathPersistsIssuedRowAndReturnsInvoiceNumber(): void
    {
        $api = $this->successApi();
        $repo = $this->fakeRepository();
        $issuer = new InvoiceIssuer($api, $repo, new BillingMapper());

        $result = $issuer->issueForOrder(1234, $this->order());

        self::assertSame(Constants::STATUS_ISSUED, $result['status']);
        self::assertSame('INV-1', $result['invoice_id']);
        $row = $repo->findByOrderId(1234);
        self::assertNotNull($row);
        self::assertSame(Constants::STATUS_ISSUED, $row['status']);
        self::assertSame('INV-1', $row['invoice_number']);
        self::assertSame('F', $row['invoice_series']);
        self::assertSame('https://files.fgo.ro/x.pdf', $row['pdf_link']);
    }

    public function testIdempotencyShortCircuitsWhenRowAlreadyIssued(): void
    {
        $api = $this->successApi();
        $repo = $this->fakeRepository();
        $issuer = new InvoiceIssuer($api, $repo, new BillingMapper());

        $issuer->issueForOrder(1234, $this->order());
        $callsAfterFirst = $api->callCount;

        $second = $issuer->issueForOrder(1234, $this->order());
        self::assertSame(Constants::STATUS_ISSUED, $second['status']);
        self::assertSame('already-issued', $second['invoice_id']);
        self::assertSame($callsAfterFirst, $api->callCount, 'API not called again after issued');
    }

    public function testFgoFailurePersistsLastErrorAndIncrementsRetryCount(): void
    {
        $api = $this->failingApi('CIF invalid');
        $repo = $this->fakeRepository();
        $issuer = new InvoiceIssuer($api, $repo, new BillingMapper());

        $result = $issuer->issueForOrder(1234, $this->order());
        self::assertSame(Constants::STATUS_FAILED, $result['status']);
        self::assertSame('CIF invalid', $result['error']);

        $row = $repo->findByOrderId(1234);
        self::assertNotNull($row);
        self::assertSame(Constants::STATUS_FAILED, $row['status']);
        self::assertSame('CIF invalid', $row['last_error']);
        self::assertSame(1, $row['retry_count']);
    }

    public function testRetryAfterFailureBumpsRetryCountAndStaysFailed(): void
    {
        $api = $this->failingApi('still bad');
        $repo = $this->fakeRepository();
        $issuer = new InvoiceIssuer($api, $repo, new BillingMapper());

        $issuer->issueForOrder(1234, $this->order());
        $issuer->issueForOrder(1234, $this->order());

        $row = $repo->findByOrderId(1234);
        self::assertNotNull($row);
        self::assertSame(Constants::STATUS_FAILED, $row['status']);
        self::assertSame(2, $row['retry_count']);
    }

    public function testInvalidOrderIdReturnsInvalidStatus(): void
    {
        $api = $this->successApi();
        $repo = $this->fakeRepository();
        $issuer = new InvoiceIssuer($api, $repo, new BillingMapper());

        $result = $issuer->issueForOrder(0);
        self::assertSame('invalid', $result['status']);
        self::assertSame(0, $api->callCount);
    }

    // ── Test doubles ─────────────────────────────────────────────────────

    private function successApi(): FgoApiClient
    {
        return new class () extends FgoApiClient {
            public int $callCount = 0;
            public function __construct()
            {
            }
            public function check(): array
            {
                return ['Success' => true];
            }
            public function issueInvoice(array $payload): array
            {
                $this->callCount++;
                return [
                    'Success' => true,
                    'Message' => 'OK',
                    'Factura' => [
                        'Numar' => 'INV-1',
                        'Serie' => 'F',
                        'Link' => 'https://files.fgo.ro/x.pdf',
                        'LinkPlata' => 'https://pay.fgo.ro/x',
                    ],
                ];
            }
            public function cancelInvoice(string $s, string $n): array
            {
                return ['Success' => true];
            }
            public function stornoInvoice(string $s, string $n): array
            {
                return ['Success' => true];
            }
            public function deleteInvoice(string $s, string $n): array
            {
                return ['Success' => true];
            }
            public function attachAwb(string $s, string $n, string $a): array
            {
                return ['Success' => true];
            }
        };
    }

    private function failingApi(string $msg): FgoApiClient
    {
        return new class ($msg) extends FgoApiClient {
            public int $callCount = 0;
            public function __construct(private string $msg)
            {
            }
            public function check(): array
            {
                return ['Success' => true];
            }
            public function issueInvoice(array $payload): array
            {
                $this->callCount++;
                throw new FgoApiException($this->msg);
            }
            public function cancelInvoice(string $s, string $n): array
            {
                return ['Success' => true];
            }
            public function stornoInvoice(string $s, string $n): array
            {
                return ['Success' => true];
            }
            public function deleteInvoice(string $s, string $n): array
            {
                return ['Success' => true];
            }
            public function attachAwb(string $s, string $n, string $a): array
            {
                return ['Success' => true];
            }
        };
    }

    /**
     * In-memory repository that mimics the `cscart_fgo_invoices` table.
     */
    private function fakeRepository(): InvoiceRepository
    {
        return new class () extends InvoiceRepository {
            /** @var array<int, array<string, mixed>> */
            private array $rows = [];

            public function findByOrderId(int $orderId): ?array
            {
                return $this->rows[$orderId] ?? null;
            }

            public function insertPending(int $orderId, ?int $cartId = null): array
            {
                if (isset($this->rows[$orderId])) {
                    return [
                        'id' => (int) $this->rows[$orderId]['id'],
                        'isExisting' => true,
                        'status' => (string) $this->rows[$orderId]['status'],
                    ];
                }
                $this->rows[$orderId] = [
                    'id' => count($this->rows) + 1,
                    'order_id' => $orderId,
                    'cart_id' => $cartId ?? 0,
                    'status' => Constants::STATUS_PENDING,
                    'invoice_number' => null,
                    'invoice_series' => null,
                    'pdf_link' => null,
                    'payment_link' => null,
                    'message' => null,
                    'last_error' => null,
                    'retry_count' => 0,
                    'request_payload' => null,
                    'payload' => null,
                ];
                return ['id' => $this->rows[$orderId]['id'], 'isExisting' => false, 'status' => Constants::STATUS_PENDING];
            }

            public function markIssued(int $orderId, \Tygh\Addons\FgoInvoicing\Dto\Invoice\IssueInvoiceResponse $r, array $form): void
            {
                $this->rows[$orderId] = array_merge($this->rows[$orderId] ?? [], [
                    'status' => Constants::STATUS_ISSUED,
                    'invoice_number' => $r->invoiceNumber,
                    'invoice_series' => $r->invoiceSeries,
                    'pdf_link' => $r->pdfLink,
                    'payment_link' => $r->paymentLink,
                    'message' => $r->message,
                    'last_error' => null,
                    'request_payload' => json_encode($form),
                    'payload' => json_encode($r->raw),
                ]);
            }

            public function markFailed(int $orderId, string $err, array $form, ?array $raw = null): void
            {
                $existing = $this->rows[$orderId] ?? [];
                $this->rows[$orderId] = array_merge($existing, [
                    'status' => Constants::STATUS_FAILED,
                    'last_error' => $err,
                    'message' => mb_substr($err, 0, 250),
                    'retry_count' => (int) ($existing['retry_count'] ?? 0) + 1,
                    'request_payload' => json_encode($form),
                    'payload' => $raw !== null ? json_encode($raw) : '',
                ]);
            }
        };
    }
}
