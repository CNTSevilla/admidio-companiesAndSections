<?php
/**
 * Companies and Sections Plugin - API Endpoint
 *
 * Get data from company data for AJAX/JavaScript usage.
 * Returns a JSON with active organizations.
 *
 * USAGE: This file is called by profile_js.php to fetch organization data
 * when needed. It checks user permissions based on roles defined in `config.php` and returns a JSON response with the list of active organizations from the `crm_organizations` table.
 *
 * @package CompaniesAndSections
 */

require_once(__DIR__ . '/../../system/common.php');

// Permissions check: only allowed roles or administrators can access
require_once(__DIR__ . '/organization_functions.php');
if (!isUserAuthorized($gDb)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Acceso denegado']);
    exit;
}

// Get active organizations
$stmt = $gDb->queryPrepared(
    "SELECT org_id, org_name FROM crm_organizations WHERE org_active = 1 ORDER BY org_name",
    []
);

$organizations = $stmt->fetchAll();

// Return JSON
header('Content-Type: application/json');
echo json_encode([
    'organizations' => $organizations,
    'count' => count($organizations)
]);
