<?php

declare(strict_types=1);

namespace Tests\Message;

use Hibla\EventLoop\Loop;
use Hibla\HttpServer\Message\MultipartParser;

afterEach(function () {
    Loop::reset();
});

describe('MultipartParser Unit Tests', function () {

    it('parses standard form fields fed in a single chunk', function () {
        $boundary = 'boundary123';
        $parser = new MultipartParser($boundary);

        $parsedFields = [];

        $parser->on('field', function ($name, $value) use (&$parsedFields) {
            $parsedFields[$name] = $value;
        });

        $payload = "--boundary123\r\n" .
            "Content-Disposition: form-data; name=\"username\"\r\n\r\n" .
            "reymart\r\n" .
            "--boundary123\r\n" .
            "Content-Disposition: form-data; name=\"role\"\r\n\r\n" .
            "administrator\r\n" .
            "--boundary123--\r\n";

        $parser->write($payload);
        $parser->end();

        expect($parsedFields)->toBe([
            'username' => 'reymart',
            'role' => 'administrator',
        ]);
    });

    it('correctly reconstructs fields when stream data is fed byte-by-byte', function () {
        $boundary = 'boundary123';
        $parser = new MultipartParser($boundary);

        $parsedFields = [];

        $parser->on('field', function ($name, $value) use (&$parsedFields) {
            $parsedFields[$name] = $value;
        });

        $payload = "--boundary123\r\n" .
            "Content-Disposition: form-data; name=\"status\"\r\n\r\n" .
            "active\r\n" .
            "--boundary123--\r\n";

        for ($i = 0; $i < \strlen($payload); $i++) {
            $parser->write($payload[$i]);
        }
        $parser->end();

        expect($parsedFields)->toBe([
            'status' => 'active',
        ]);
    });

    it('streams file contents chunk-by-chunk through the emitted ThroughStream', function () {
        $boundary = 'boundary123';
        $parser = new MultipartParser($boundary);

        $fileEvents = [];
        $fileData = '';

        $parser->on('file', function ($name, $filename, $mime, $fileStream) use (&$fileEvents, &$fileData) {
            $fileEvents[] = [
                'name' => $name,
                'filename' => $filename,
                'mime' => $mime,
            ];

            $fileStream->on('data', function ($chunk) use (&$fileData) {
                $fileData .= $chunk;
            });
        });

        $payload = "--boundary123\r\n" .
            "Content-Disposition: form-data; name=\"avatar\"; filename=\"photo.jpg\"\r\n" .
            "Content-Type: image/jpeg\r\n\r\n" .
            'binary_data_chunk_1_' .
            'binary_data_chunk_2' .
            "\r\n--boundary123--\r\n";

        $parser->write($payload);
        $parser->end();

        expect($fileEvents)->toHaveCount(1)
            ->and($fileEvents[0]['name'])->toBe('avatar')
            ->and($fileEvents[0]['filename'])->toBe('photo.jpg')
            ->and($fileEvents[0]['mime'])->toBe('image/jpeg')
            ->and($fileData)->toBe('binary_data_chunk_1_binary_data_chunk_2')
        ;
    });

    it('ignores preamble and epilogue text surrounding the body boundaries', function () {
        $boundary = 'boundary123';
        $parser = new MultipartParser($boundary);

        $parsedFields = [];

        $parser->on('field', function ($name, $value) use (&$parsedFields) {
            $parsedFields[$name] = $value;
        });

        $payload = "This is preamble noise that should be ignored by the parser\r\n" .
            "--boundary123\r\n" .
            "Content-Disposition: form-data; name=\"clean\"\r\n\r\n" .
            "value\r\n" .
            "--boundary123--\r\n" .
            "This is epilogue noise that should also be ignored\r\n";

        $parser->write($payload);
        $parser->end();

        expect($parsedFields)->toBe(['clean' => 'value']);
    });

    it('does not false-trigger when content contains values mimicking a boundary', function () {
        $boundary = 'boundary123';
        $parser = new MultipartParser($boundary);

        $parsedFields = [];

        $parser->on('field', function ($name, $value) use (&$parsedFields) {
            $parsedFields[$name] = $value;
        });

        $payload = "--boundary123\r\n" .
            "Content-Disposition: form-data; name=\"mimic\"\r\n\r\n" .
            "Text containing --boundary123 and --boundary123-- within it\r\n" .
            "--boundary123--\r\n";

        $parser->write($payload);
        $parser->end();

        expect($parsedFields['mimic'])->toBe('Text containing --boundary123 and --boundary123-- within it');
    });

    it('emits an error and closes if part headers exceed the 8192-byte limit', function () {
        $boundary = 'boundary123';
        $parser = new MultipartParser($boundary);

        $errorTriggered = false;

        $parser->on('error', function (\Throwable $e) use (&$errorTriggered) {
            if (str_contains($e->getMessage(), 'headers too large')) {
                $errorTriggered = true;
            }
        });

        $oversizedHeader = str_repeat('X-Header: value\r\n', 500);

        $payload = "--boundary123\r\n" .
            $oversizedHeader;

        $parser->write($payload);

        expect($errorTriggered)->toBeTrue()
            ->and($parser->isWritable())->toBeFalse()
        ;
    });

    it('handles fields with empty values cleanly', function () {
        $boundary = 'boundary123';
        $parser = new MultipartParser($boundary);

        $parsedFields = [];

        $parser->on('field', function ($name, $value) use (&$parsedFields) {
            $parsedFields[$name] = $value;
        });

        $payload = "--boundary123\r\n" .
            "Content-Disposition: form-data; name=\"empty_field\"\r\n\r\n" .
            "\r\n" .
            "--boundary123--\r\n";

        $parser->write($payload);
        $parser->end();

        expect($parsedFields)->toHaveKey('empty_field')
            ->and($parsedFields['empty_field'])->toBe('')
        ;
    });
});
