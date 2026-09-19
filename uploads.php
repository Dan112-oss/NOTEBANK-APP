<?php
/**
 * Deliberately inert. The guide's project layout lists a root-level
 * uploads.php, but every actual file transfer in Note Bank goes
 * through an authenticated, ownership-checked endpoint instead:
 *   - admin/uploads.php     (admin document upload form + handler)
 *   - student/payment.php   (payment proof upload)
 *   - api/preview.php       (signed, session-bound preview streaming)
 *   - api/downloads.php     (entitlement-checked download streaming)
 *
 * Protected files are never served from a predictable, unauthenticated
 * URL (see the guide, section 9), so this entry point always refuses.
 */
declare(strict_types=1);

http_response_code(403);
header('Content-Type: text/plain; charset=utf-8');
echo "Direct access is not permitted. Files are served through authenticated endpoints only.";
