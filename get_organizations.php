<?php
/**
 * Companies and Sections Plugin - JSON endpoint
 *
 * Returns the active companies (id, uuid, name) as JSON: all for the secretariat,
 * only their own for delegates.
 *
 * @package CompaniesAndSections
 */

require_once(__DIR__ . '/organization_functions.php');

casRequireAuth(true);

$sql = 'SELECT org_id, org_uuid, org_name FROM ' . CAS_TBL_ORGS . ' WHERE org_active = 1';
$params = [];
if (!casCanEdit()) {   // delegates: only their companies
    $own = casDelegateOrgIds();
    $sql .= ' AND org_id IN (' . implode(',', array_fill(0, count($own), '?')) . ')';
    $params = $own;
}
$organizations = $gDb->queryPrepared($sql . ' ORDER BY org_name', $params)->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
echo json_encode(['organizations' => $organizations, 'count' => count($organizations)], JSON_UNESCAPED_UNICODE);
