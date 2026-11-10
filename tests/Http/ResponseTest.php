<?php
declare(strict_types=1);

namespace Winglet\Core\Tests\Http;

use Winglet\Core\Http\Response;
use Winglet\Core\Tests\TestCase;

final class ResponseTest extends TestCase
{
    public function testRawResponseKeepsBodyAndHeadersWithoutJsonEncoding(): void
    {
        $csv = "sep=;\nPantin numero;Rekisteritunnus\n1;ABC-123\n";

        $response = Response::raw($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);

        $this->assertSame(200, $response->status);
        $this->assertSame('text/csv; charset=UTF-8', $response->headers['Content-Type']);
        $this->assertSame($csv, $response->body);
        $this->assertStringStartsWith('sep=;', $response->body);
        $this->assertStringStartsNotWith('{', $response->body);
    }

    public function testDownloadResponseBuildsAttachmentHeaders(): void
    {
        $body = "sep=;\nA;B\n";

        $response = Response::download(
            body: $body,
            filename: 'valid-offers.csv',
            contentType: 'text/csv; charset=UTF-8',
            headers: [
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Pragma' => 'no-cache',
            ]
        );

        $this->assertSame('text/csv; charset=UTF-8', $response->headers['Content-Type']);
        $this->assertSame('nosniff', $response->headers['X-Content-Type-Options']);
        $this->assertSame((string)strlen($body), $response->headers['Content-Length']);
        $this->assertSame('no-store, no-cache, must-revalidate, max-age=0', $response->headers['Cache-Control']);
        $this->assertSame('no-cache', $response->headers['Pragma']);
        $this->assertStringContainsString('attachment', $response->headers['Content-Disposition']);
        $this->assertStringContainsString('filename="valid-offers.csv"', $response->headers['Content-Disposition']);
        $this->assertStringContainsString("filename*=UTF-8''valid-offers.csv", $response->headers['Content-Disposition']);
        $this->assertSame($body, $response->body);
    }

    public function testInlineFileResponseUsesInlineDisposition(): void
    {
        $response = Response::file('body', 'report.csv', 'text/csv; charset=UTF-8', attachment: false);

        $this->assertStringStartsWith('inline;', $response->headers['Content-Disposition']);
    }

    public function testInvalidHeaderValueIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Response::raw('body', headers: [
            'X-Test' => "ok\r\nInjected: bad",
        ]);
    }
}
