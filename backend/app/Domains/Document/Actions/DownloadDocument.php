<?php

namespace App\Domains\Document\Actions;

use App\Domains\Document\DocumentStorage;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Policies\DocumentPolicy;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stream a Document's current file to an already-authorized caller (M5.2, D-117).
 *
 * **Streamed from a surface that authorized the actor first, never by URL**
 * (D-114). No signed URL, no temporary URL, no path the client could try: the
 * bytes travel through a request the Policy has already judged, and the storage
 * path never appears in any response.
 *
 * **Authorization is the caller's job and is not optional.** This class knows
 * nothing about who is asking; handing it a Document is a statement that
 * {@see DocumentPolicy::download()} said yes — which includes the
 * D-115 gate that refuses every sensitive download until an audit store exists.
 *
 * ## Why the version is re-read rather than trusted
 *
 * `current_version_id` is followed to a real row. A Document with no version is a
 * 404 rather than an empty file: it means the pointer was never set, which after
 * M5.2's upload path cannot happen for a document that was uploaded — so answering
 * "here is nothing" would hide a defect behind a successful response.
 *
 * A version whose **file** is missing throws from {@see DocumentStorage}, which is
 * also right: the metadata says it exists, so either somebody removed it outside
 * the application or a write failed without rolling back, and both are worth an
 * error rather than a zero-byte download.
 *
 * ## The filename
 *
 * The download is named with `original_filename` — the name the uploader knew —
 * which is exactly why it is stored: the file on disk is called a ULID, and
 * handing that to somebody would be useless. It is passed through
 * {@see HeaderUtils::makeDisposition()}, which quotes and escapes it and supplies
 * an ASCII fallback, so a name containing quotes, semicolons or non-Latin
 * characters cannot break out of the header. **The name is data the uploader
 * supplied**, and a header is exactly where unescaped user data goes wrong.
 */
class DownloadDocument
{
    public function __construct(private readonly DocumentStorage $storage) {}

    public function handle(Document $document): StreamedResponse
    {
        $version = $this->currentVersion($document);

        $stream = $this->storage->readStream($version);

        $response = new StreamedResponse(function () use ($stream): void {
            fpassthru($stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        });

        $response->headers->set('Content-Type', $version->mime_type);
        $response->headers->set('Content-Length', (string) $version->file_size);

        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $version->original_filename,
            // ASCII fallback for clients that cannot read the RFC 5987 form. The
            // document number rather than a generic name, so a downloaded file is
            // still identifiable — and it carries no identity, unlike the original
            // name, which for a KTP scan is often the subject's own.
            $document->document_number.'.bin',
        ));

        // A legal document is not a cacheable public asset. Said explicitly rather
        // than left to defaults, because an intermediary that cached this would
        // undo the authorization the request just performed.
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    private function currentVersion(Document $document): DocumentVersion
    {
        $version = $document->current_version_id === null
            ? null
            : $document->versions()->whereKey($document->current_version_id)->first();

        if ($version === null) {
            abort(404, 'This document has no file.');
        }

        return $version;
    }
}
