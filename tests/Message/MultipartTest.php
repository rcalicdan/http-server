<?php

declare(strict_types=1);

namespace Tests\Message;

use Hibla\EventLoop\Loop;
use Hibla\HttpServer\Message\MultipartForm;
use Hibla\HttpServer\Message\Request;
use Hibla\HttpServer\Message\UploadedFile;
use Hibla\Stream\ThroughStream;

use function Hibla\await;

afterEach(function () {
    Loop::reset();

    $files = glob(sys_get_temp_dir() . '/hibla_up_*');

    foreach ($files as $file) {
        if (file_exists($file)) {
            @unlink($file);
        }
    }
});

/**
 * Helper to build a valid multipart payload block
 */
function createMultipartPayload(string $boundary, array $fields, array $files): string
{
    $body = '';
    foreach ($fields as $name => $value) {
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n";
        $body .= "{$value}\r\n";
    }
    foreach ($files as $name => $file) {
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"{$name}\"; filename=\"{$file['filename']}\"\r\n";
        $body .= "Content-Type: {$file['mime']}\r\n\r\n";
        $body .= "{$file['content']}\r\n";
    }
    $body .= "--{$boundary}--\r\n";

    return $body;
}

describe('MultipartParser & Message Integration', function () {

    it('parses form fields and uploaded files successfully', function () {
        $boundary = '----WebKitFormBoundary7MA4YWxkTrZu0gW';
        $payload = createMultipartPayload($boundary, ['username' => 'john_doe', 'role' => 'admin'], [
            'avatar' => ['filename' => 'avatar.png', 'mime' => 'image/png', 'content' => 'fake_png_binary_data'],
        ]);

        $request = new Request(
            'POST',
            '/',
            ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
            $payload
        );

        $form = await($request->getParsedBody());

        expect($form)->toBeInstanceOf(MultipartForm::class)
            ->and($form->get('username'))->toBe('john_doe')
            ->and($form->get('role'))->toBe('admin')
        ;

        $file = $form->getFile('avatar');
        expect($file)->toBeInstanceOf(UploadedFile::class);

        expect($file->clientFilename)->toBe('avatar.png')
            ->and($file->clientMediaType)->toBe('image/png')
            ->and($file->size)->toBe(20)
            ->and(file_exists($file->tmpPath))->toBeTrue()
        ;
    });

    it('asynchronously moves uploaded files and deletes temporary sources', function () {
        $boundary = 'boundary123';
        $payload = createMultipartPayload($boundary, [], [
            'document' => ['filename' => 'contract.pdf', 'mime' => 'application/pdf', 'content' => 'pdf_bytes_data'],
        ]);

        $request = new Request(
            'POST',
            '/',
            ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
            $payload
        );

        $form = await($request->getParsedBody());

        $file = $form->getFile('document');
        $tmpPath = $file->tmpPath;

        $destination = sys_get_temp_dir() . '/hibla_moved_contract.pdf';
        if (file_exists($destination)) {
            unlink($destination);
        }

        await($file->moveTo($destination));

        expect(file_exists($destination))->toBeTrue()
            ->and(file_get_contents($destination))->toBe('pdf_bytes_data')
            ->and(file_exists($tmpPath))->toBeFalse()
        ;

        if (file_exists($destination)) {
            unlink($destination);
        }
    });

    it('automatically unlinks temporary files when UploadedFile is garbage collected', function () {
        $boundary = 'boundary123';
        $payload = createMultipartPayload($boundary, [], [
            'file' => ['filename' => 'trash.txt', 'mime' => 'text/plain', 'content' => 'delete_me_soon'],
        ]);

        $request = new Request(
            'POST',
            '/',
            ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
            $payload
        );

        $form = await($request->getParsedBody());

        $file = $form->getFile('file');
        $tmpPath = $file->tmpPath;

        expect(file_exists($tmpPath))->toBeTrue();

        unset($file, $form);
        gc_collect_cycles();

        expect(file_exists($tmpPath))->toBeFalse();
    });

    it('correctly parses multiple uploaded files under an array key using [] syntax', function () {
        $boundary = 'boundary123';
        $payload = '';

        $payload .= "--{$boundary}\r\n" .
            "Content-Disposition: form-data; name=\"documents[]\"; filename=\"doc1.txt\"\r\n" .
            "Content-Type: text/plain\r\n\r\n" .
            "content_of_doc1\r\n";

        $payload .= "--{$boundary}\r\n" .
            "Content-Disposition: form-data; name=\"documents[]\"; filename=\"doc2.txt\"\r\n" .
            "Content-Type: text/plain\r\n\r\n" .
            "content_of_doc2\r\n";

        $payload .= "--{$boundary}--\r\n";

        $request = new Request(
            'POST',
            '/',
            ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
            $payload
        );

        $form = await($request->getParsedBody());

        $files = $form->getFile('documents');

        expect($files)->toBeArray()->toHaveCount(2)
            ->and($files[0])->toBeInstanceOf(UploadedFile::class)
            ->and($files[1])->toBeInstanceOf(UploadedFile::class)
        ;

        [$f1, $f2] = $files;

        expect($f1->clientFilename)->toBe('doc1.txt')
            ->and($f2->clientFilename)->toBe('doc2.txt')
        ;
    });

    it('handles files that are uploaded with zero-byte content sizes', function () {
        $boundary = 'boundary123';
        $payload = createMultipartPayload($boundary, [], [
            'empty_file' => ['filename' => 'empty.txt', 'mime' => 'text/plain', 'content' => ''],
        ]);

        $request = new Request(
            'POST',
            '/',
            ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
            $payload
        );

        $form = await($request->getParsedBody());

        $file = $form->getFile('empty_file');

        expect($file)->toBeInstanceOf(UploadedFile::class)
            ->and($file->size)->toBe(0)
            ->and(file_exists($file->tmpPath))->toBeTrue()
            ->and(filesize($file->tmpPath))->toBe(0)
        ;
    });

    it('rejects parsing with a clear exception if Content-Type is missing the boundary', function () {
        $request = new Request(
            'POST',
            '/',
            ['Content-Type' => 'multipart/form-data'],
            ''
        );

        expect(fn () => await($request->getParsedBody()))
            ->toThrow(\RuntimeException::class, 'Not a valid multipart/form-data request')
        ;
    });
});

describe('Multipart Advanced Cancellation Testing', function () {

    it('aborts active writes and instantly deletes partial temp files when request is cancelled mid-stream', function () {
        $boundary = 'boundary123';

        $chunk1 = "--{$boundary}\r\n" .
            "Content-Disposition: form-data; name=\"avatar\"; filename=\"big_file.bin\"\r\n" .
            "Content-Type: application/octet-stream\r\n\r\n" .
            str_repeat('X', 1024 * 100);

        $bodyStream = new ThroughStream();

        $request = new Request(
            'POST',
            '/',
            ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
            $bodyStream
        );

        $parsePromise = $request->getParsedBody();

        $tempFilesBefore = glob(sys_get_temp_dir() . '/hibla_up_*');

        $bodyStream->write($chunk1);

        Loop::runOnce();

        $tempFilesAfter = glob(sys_get_temp_dir() . '/hibla_up_*');
        $newFiles = array_diff($tempFilesAfter, $tempFilesBefore);

        expect(\count($newFiles))->toBe(1);
        $partialTempFile = array_shift($newFiles);
        expect(file_exists($partialTempFile))->toBeTrue();

        $parsePromise->cancel();

        Loop::runOnce();

        expect(file_exists($partialTempFile))->toBeFalse();
        expect($parsePromise->isCancelled())->toBeTrue();
    });

    it('aborts async copying and deletes target files when moveTo() is cancelled mid-progress', function () {
        $tmpPath = tempnam(sys_get_temp_dir(), 'hibla_move_cancel_');
        file_put_contents($tmpPath, str_repeat('Y', 5 * 1024 * 1024));

        $uploadedFile = new UploadedFile(
            $tmpPath,
            'heavy.bin',
            'application/octet-stream',
            5 * 1024 * 1024
        );

        $destPath = sys_get_temp_dir() . '/hibla_interrupted_destination.bin';
        if (file_exists($destPath)) {
            unlink($destPath);
        }

        $movePromise = $uploadedFile->moveTo($destPath);

        Loop::runOnce();

        $movePromise->cancel();

        Loop::run();

        $existsAtEnd = file_exists($destPath);

        expect($existsAtEnd)->toBeFalse();
        expect(file_exists($tmpPath))->toBeTrue();
        expect($movePromise->isCancelled())->toBeTrue();

        if (file_exists($tmpPath)) {
            unlink($tmpPath);
        }
    });
});
