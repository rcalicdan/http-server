<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\HttpServer\Message\MultipartForm;
use Hibla\HttpServer\Message\Request;
use Hibla\HttpServer\Message\UploadedFile;
use Hibla\Stream\ThroughStream;

afterEach(function () {
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

describe("MultipartParser & Message Integration", function () {

//     it('parses form fields and uploaded files successfully', function () {
//         $boundary = '----WebKitFormBoundary7MA4YWxkTrZu0gW';
//         $payload = createMultipartPayload($boundary, ['username' => 'john_doe', 'role' => 'admin'], [
//             'avatar' => ['filename' => 'avatar.png', 'mime' => 'image/png', 'content' => 'fake_png_binary_data']
//         ]);

//         $request = new Request(
//             'POST',
//             '/',
//             ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
//             $payload
//         );

//         $form = $request->getParsedBody()->wait();

//         expect($form)->toBeInstanceOf(MultipartForm::class);
//         expect($form->get('username'))->toBe('john_doe');
//         expect($form->get('role'))->toBe('admin');

//         $file = $form->getFile('avatar');
//         expect($file)->toBeInstanceOf(UploadedFile::class);

//         /** @var UploadedFile $file */
//         expect($file->clientFilename)->toBe('avatar.png');
//         expect($file->clientMediaType)->toBe('image/png');
//         expect($file->size)->toBe(20);
//         expect(file_exists($file->tmpPath))->toBeTrue();
//     });

//     it('asynchronously moves uploaded files and deletes temporary sources', function () {
//         $boundary = 'boundary123';
//         $payload = createMultipartPayload($boundary, [], [
//             'document' => ['filename' => 'contract.pdf', 'mime' => 'application/pdf', 'content' => 'pdf_bytes_data']
//         ]);

//         $request = new Request(
//             'POST',
//             '/',
//             ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
//             $payload
//         );

//         $form = $request->getParsedBody()->wait();

//         /** @var UploadedFile $file */
//         $file = $form->getFile('document');
//         $tmpPath = $file->tmpPath;

//         $destination = sys_get_temp_dir() . '/hibla_moved_contract.pdf';
//         if (file_exists($destination)) {
//             unlink($destination);
//         }

//         $file->moveTo($destination)->wait();

//         expect(file_exists($destination))->toBeTrue();
//         expect(file_get_contents($destination))->toBe('pdf_bytes_data');
//         expect(file_exists($tmpPath))->toBeFalse();
//         if (file_exists($destination)) {
//             unlink($destination);
//         }
//     });

//     it('automatically unlinks temporary files when UploadedFile is garbage collected', function () {
//         $boundary = 'boundary123';
//         $payload = createMultipartPayload($boundary, [], [
//             'file' => ['filename' => 'trash.txt', 'mime' => 'text/plain', 'content' => 'delete_me_soon']
//         ]);

//         $request = new Request(
//             'POST',
//             '/',
//             ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
//             $payload
//         );

//         $form = $request->getParsedBody()->wait();

//         /** @var UploadedFile $file */
//         $file = $form->getFile('file');
//         $tmpPath = $file->tmpPath;

//         expect(file_exists($tmpPath))->toBeTrue();

//         unset($file, $form);
//         gc_collect_cycles();

//         expect(file_exists($tmpPath))->toBeFalse();
//     });
// });

// describe("Multipart Advanced Cancellation Testing", function () {

//     it('aborts active writes and instantly deletes partial temp files when request is cancelled mid-stream', function () {
//         $boundary = 'boundary123';

//         $chunk1 = "--{$boundary}\r\n" .
//             "Content-Disposition: form-data; name=\"avatar\"; filename=\"big_file.bin\"\r\n" .
//             "Content-Type: application/octet-stream\r\n\r\n" .
//             str_repeat('X', 1024 * 100);

//         $bodyStream = new ThroughStream();

//         $request = new Request(
//             'POST',
//             '/',
//             ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
//             $bodyStream
//         );

//         $parsePromise = $request->getParsedBody();

//         $tempFilesBefore = glob(sys_get_temp_dir() . '/hibla_up_*');

//         $bodyStream->write($chunk1);

//         Loop::runOnce();

//         $tempFilesAfter = glob(sys_get_temp_dir() . '/hibla_up_*');
//         $newFiles = array_diff($tempFilesAfter, $tempFilesBefore);

//         expect(count($newFiles))->toBe(1);
//         $partialTempFile = array_shift($newFiles);
//         expect(file_exists($partialTempFile))->toBeTrue();

//         $parsePromise->cancel();

//         Loop::runOnce();

//         expect(file_exists($partialTempFile))->toBeFalse();
//         expect($parsePromise->isCancelled())->toBeTrue();
//     });

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

        debug("--- STARTING TEST ---");

        $movePromise = $uploadedFile->moveTo($destPath);
        debug("Promise created. State: " . $movePromise->state);

        // Run exactly one loop tick to start the copy pipeline
        Loop::runOnce();
        debug("Loop::runOnce() completed.");
        debug("File size at destPath after runOnce: " . (file_exists($destPath) ? filesize($destPath) : "FILE_NOT_FOUND"));

        // Cancel the in-progress promise deterministically
        debug("Calling cancel() on promise...");
        $movePromise->cancel();
        debug("Promise cancelled. State: " . $movePromise->state);
        debug("File exists at destPath immediately after cancel: " . (file_exists($destPath) ? "YES" : "NO"));

        // Run the remaining loop ticks
        debug("Running Loop::run()...");
        Loop::run();
        debug("Loop completed.");

        $existsAtEnd = file_exists($destPath);
        debug("File exists at destPath at the very end: " . ($existsAtEnd ? "YES (Size: " . filesize($destPath) . ")" : "NO"));

        // ASSERT: The destination copy must be unlinked, and the source file preserved
        expect($existsAtEnd)->toBeFalse();
        expect(file_exists($tmpPath))->toBeTrue();
        expect($movePromise->isCancelled())->toBeTrue();

        if (file_exists($tmpPath)) {
            unlink($tmpPath);
        }
    });
});
