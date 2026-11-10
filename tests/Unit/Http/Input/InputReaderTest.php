<?php
declare(strict_types=1);

namespace Winglet\Core\Tests\Unit\Http\Input;

use Winglet\Core\Http\Input\InputReader;
use Winglet\Core\Http\Input\UploadedFile;
use Winglet\Core\Tests\TestCase;

final class InputReaderTest extends TestCase
{
    public function testUsesPostThenQueryThenJsonPrecedence(): void
    {
        $reader = new InputReader($this->makeRequest(
            method: 'POST',
            path: '/input',
            query: ['value' => 'query', 'query_only' => 'yes'],
            post: ['value' => 'post'],
            json: ['value' => 'json', 'json_only' => 'yes'],
        ));

        self::assertSame('post', $reader->get('value'));
        self::assertSame('yes', $reader->get('query_only'));
        self::assertSame('yes', $reader->get('json_only'));
    }

    public function testReadsUploadedFileFromRequestSnapshotInsteadOfMutableGlobals(): void
    {
        $previous = $_FILES;
        $_FILES = [];
        try {
            $reader = new InputReader($this->makeRequest(
                method: 'POST',
                path: '/upload',
                files: [
                    'document' => [
                        'name' => 'report.pdf',
                        'tmp_name' => '/tmp/php-upload',
                        'size' => 123,
                        'error' => UPLOAD_ERR_OK,
                        'type' => 'application/pdf',
                    ],
                ],
            ));

            $file = $reader->get('document');
            self::assertInstanceOf(UploadedFile::class, $file);
            self::assertSame('report.pdf', $file->originalName);
            self::assertSame('/tmp/php-upload', $file->tmpName);
            self::assertSame(123, $file->size);
        } finally {
            $_FILES = $previous;
        }
    }

    public function testPresentMapTreatsNullAsAbsentButEmptyStringAsPresent(): void
    {
        $reader = new InputReader($this->makeRequest(
            method: 'POST',
            path: '/input',
            post: ['null_value' => null, 'empty_value' => ''],
        ));

        self::assertSame([
            'missing' => false,
            'null_value' => false,
            'empty_value' => true,
        ], $reader->presentMap(['missing', 'null_value', 'empty_value']));
    }
}
