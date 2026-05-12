<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\FgoInvoicing\Api\FgoApiClient;
use Tygh\Addons\FgoInvoicing\Api\FgoApiException;
use Tygh\Addons\FgoInvoicing\Api\FgoHttpClient;
use Tygh\Addons\FgoInvoicing\Api\FgoSigner;
use Tygh\Addons\FgoInvoicing\Constants;

#[CoversClass(FgoApiClient::class)]
final class FgoApiClientTest extends TestCase
{
    /**
     * Recording HTTP transport — intercepts the post() call and returns a
     * canned response while remembering the path and form fields.
     */
    private function recordingHttp(?array $cannedResponse = null, ?string $errorMessage = null): FgoHttpClient
    {
        return new class ($cannedResponse, $errorMessage) extends FgoHttpClient {
            public ?string $lastPath = null;
            /** @var array<string, scalar>|null */
            public ?array $lastForm = null;

            public function __construct(
                private readonly ?array $canned,
                private readonly ?string $errorMessage,
            ) {
                parent::__construct(baseUrl: 'https://test/');
            }

            public function post(string $path, array $form): ?array
            {
                $this->lastPath = $path;
                $this->lastForm = $form;
                return $this->canned;
            }

            public function getLastError(): string
            {
                return $this->errorMessage ?? '';
            }
        };
    }

    public function testCheckEndpointSendsHashAndTokenAndReturnsBody(): void
    {
        $http = $this->recordingHttp(['Success' => true, 'Message' => 'ok']);
        $api = new FgoApiClient($http, 'CODUNIC', 'PRIVATEKEY', 'https://shop.test', '4.20.1', '0.1.0');

        $resp = $api->check();

        self::assertSame(Constants::PATH_CHECK, $http->lastPath);
        self::assertSame('CODUNIC', $http->lastForm['CodUnic']);
        self::assertSame(FgoSigner::checkHash('CODUNIC', 'PRIVATEKEY'), $http->lastForm['Hash']);
        self::assertSame(FgoSigner::checkToken(), $http->lastForm['Token']);
        self::assertSame('CS-Cart', $http->lastForm['Platforma']);
        self::assertSame('https://shop.test', $http->lastForm['PlatformaUrl']);
        self::assertSame('4.20.1', $http->lastForm['Versiune']);
        self::assertSame('0.1.0', $http->lastForm['VersiuneAddon']);
        self::assertTrue($resp['Success']);
    }

    public function testCheckThrowsOnSuccessFalse(): void
    {
        $http = $this->recordingHttp(['Success' => false, 'Message' => 'invalid creds']);
        $api = new FgoApiClient($http, 'X', 'Y', '', '', '0.1.0');

        $this->expectException(FgoApiException::class);
        $this->expectExceptionMessage('invalid creds');
        $api->check();
    }

    public function testCheckThrowsOnHttpFailure(): void
    {
        $http = $this->recordingHttp(null, 'cURL connection refused');
        $api = new FgoApiClient($http, 'X', 'Y', '', '', '0.1.0');

        $this->expectException(FgoApiException::class);
        $this->expectExceptionMessage('cURL connection refused');
        $api->check();
    }

    public function testIssueInvoiceComputesHashFromCustomerNameAndTokenFromIds(): void
    {
        $http = $this->recordingHttp([
            'Success' => true,
            'Factura' => ['Numar' => '1', 'Serie' => 'F', 'Link' => 'http://x', 'LinkPlata' => 'http://y'],
        ]);
        $api = new FgoApiClient($http, 'CODUNIC', 'PRIVATEKEY', '', '', '0.1.0');

        $payload = [
            'Valuta' => 'RON',
            'TipFactura' => 'Factura',
            'IdExtern' => 1234,
            'Client[Denumire]' => 'Ștefan Țăndărei',
            'Client[IdExtern]' => 7,
            'Continut[0][Denumire]' => 'A',
            'Continut[0][NrProduse]' => 1,
            'Continut[0][PretTotal]' => 100.0,
            'Continut[0][CotaTVA]' => 21,
        ];

        $resp = $api->issueInvoice($payload);

        self::assertSame(Constants::PATH_EMITERE, $http->lastPath);
        // Hash uses the diacritics-flattened form of the customer name.
        self::assertSame(
            FgoSigner::issueHash('CODUNIC', 'PRIVATEKEY', 'Ștefan Țăndărei'),
            $http->lastForm['Hash'],
        );
        self::assertSame(FgoSigner::issueToken('1234', '7'), $http->lastForm['Token']);
        self::assertSame('A', $http->lastForm['Continut[0][Denumire]']);
        self::assertSame(21, $http->lastForm['Continut[0][CotaTVA]']);
        self::assertTrue($resp['Success']);
    }

    public function testIssueInvoiceDropsNullsFromPayload(): void
    {
        $http = $this->recordingHttp(['Success' => true]);
        $api = new FgoApiClient($http, 'X', 'Y', '', '', '0.1.0');

        $api->issueInvoice([
            'Valuta' => 'RON',
            'TipFactura' => 'Factura',
            'IdExtern' => 1,
            'Client[Denumire]' => 'X',
            'Client[IdExtern]' => 1,
            'Serie' => null,
            'Explicatii' => null,
        ]);

        self::assertArrayNotHasKey('Serie', $http->lastForm);
        self::assertArrayNotHasKey('Explicatii', $http->lastForm);
    }

    public function testCancelInvoiceUsesExistingInvoiceHashAndTokenAndCorrectPath(): void
    {
        $http = $this->recordingHttp(['Success' => true]);
        $api = new FgoApiClient($http, 'CODUNIC', 'PRIVATEKEY', '', '', '0.1.0');

        $api->cancelInvoice('F', '12345');

        self::assertSame(Constants::PATH_ANULARE, $http->lastPath);
        self::assertSame('F', $http->lastForm['Serie']);
        self::assertSame('12345', $http->lastForm['Numar']);
        self::assertSame(
            FgoSigner::existingInvoiceHash('CODUNIC', 'PRIVATEKEY', '12345'),
            $http->lastForm['Hash'],
        );
        self::assertSame(FgoSigner::existingInvoiceToken('F', '12345'), $http->lastForm['Token']);
    }

    public function testStornoInvoiceHitsStornarePath(): void
    {
        $http = $this->recordingHttp(['Success' => true]);
        $api = new FgoApiClient($http, 'X', 'Y', '', '', '0.1.0');
        $api->stornoInvoice('F', '12345');
        self::assertSame(Constants::PATH_STORNARE, $http->lastPath);
    }

    public function testDeleteInvoiceHitsStergerePath(): void
    {
        $http = $this->recordingHttp(['Success' => true]);
        $api = new FgoApiClient($http, 'X', 'Y', '', '', '0.1.0');
        $api->deleteInvoice('F', '12345');
        self::assertSame(Constants::PATH_STERGERE, $http->lastPath);
    }

    public function testAttachAwbHitsAwbPathAndCarriesAwbField(): void
    {
        $http = $this->recordingHttp(['Success' => true]);
        $api = new FgoApiClient($http, 'X', 'Y', '', '', '0.1.0');
        $api->attachAwb('F', '12345', 'AWB-XYZ');
        self::assertSame(Constants::PATH_AWB, $http->lastPath);
        self::assertSame('AWB-XYZ', $http->lastForm['AWB']);
    }
}
