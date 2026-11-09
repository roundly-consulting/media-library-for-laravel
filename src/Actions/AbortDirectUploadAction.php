<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Actions;

use RoundlyConsulting\MediaLibrary\Enums\DirectUploadStatus;
use RoundlyConsulting\MediaLibrary\Exceptions\DirectUploadIncomplete;
use RoundlyConsulting\MediaLibrary\Uploads\UploadTickets;
use SensitiveParameter;

/**
 * Cancels a pending direct upload: `MediaLibrary::abortUpload($token)`. The ticket becomes
 * `aborted` (one conditional update, so it never races an adoption) and its object — or its
 * unfinished multipart upload — is removed. Aborting an adopted, rejected or aborted ticket
 * changes nothing; one being adopted right now throws {@see DirectUploadIncomplete}.
 */
final class AbortDirectUploadAction
{
    public function __construct(
        private readonly UploadTickets $tickets,
    ) {}

    public function execute(#[SensitiveParameter] string $token): void
    {
        $upload = $this->tickets->find($token);

        if ($this->tickets->transition($upload, DirectUploadStatus::Pending, DirectUploadStatus::Aborted)) {
            $this->tickets->discardObject($upload);

            return;
        }

        if ($upload->refresh()->status === DirectUploadStatus::Adopting) {
            throw DirectUploadIncomplete::inProgress();
        }
    }
}
