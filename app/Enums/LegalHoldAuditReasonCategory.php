<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Enums;

enum LegalHoldAuditReasonCategory: string
{
    case Completed = 'completed';
    case InvalidInput = 'invalid_input';
    case CaseReferenceConflict = 'case_reference_conflict';
    case HoldNotActive = 'hold_not_active';
    case DuplicateActiveAttachment = 'duplicate_active_attachment';
    case AttachmentAlreadyDetached = 'attachment_already_detached';
    case TargetUnavailable = 'target_unavailable';
    case PersistenceFailure = 'persistence_failure';
}
