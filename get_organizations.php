<?php
/**
 * Companies and Sections Plugin - JSON endpoint
 *
 * Returns the active companies (id, uuid, name) as JSON for authorized users.
 *
 * @package CompaniesAndSections
 */

require_once(__DIR__ . '/organization_functions.php');

casRequireAuth(true);

$organizations = $gDb->queryPrepared(
    'SELECT org_id, org_uuid, org_name FROM ' . CAS_TBL_ORGS . ' WHERE org_active = 1 ORDER BY org_name'
)->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
echo json_encode(['organizations' => $organizations, 'count' => count($organizations)], JSON_UNESCAPED_UNICODE);
