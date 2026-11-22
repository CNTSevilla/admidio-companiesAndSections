<?php
/**
 * Companies and Sections Plugin - Bootstrap and helper functions
 *
 * Every page of the plugin includes this file first. It loads Admidio, defines the
 * plugin paths and provides the helpers for authorization, CSRF, escaping and
 * database access. The plugin uses Admidio's own database connection ($gDb):
 * it never opens a second connection and never stores credentials.
 *
 * @package CompaniesAndSections
 */

use Admidio\Infrastructure\Utils\SecurityUtils;
use Ramsey\Uuid\Uuid;

require_once(__DIR__ . '/../../system/common.php');

// Name of the plugin folder: <admidio>/adm_plugins/companies_and_sections/
const CAS_FOLDER = 'companies_and_sections';
define('CAS_URL', ADMIDIO_URL . FOLDER_PLUGINS . '/' . CAS_FOLDER);

// Plugin tables (kept without the Admidio prefix for compatibility with existing installs)
const CAS_TBL_ORGS       = 'crm_organizations';
const CAS_TBL_MEMBERS    = 'crm_member_organizations';
const CAS_TBL_WORKPLACES = 'crm_workplaces';

/**
 * HTML-escapes a value for output.
 */
function e($value): string
{
    return SecurityUtils::encodeHTML((string)($value ?? ''));
}

/**
 * Hidden input with the CSRF token of the current session. Must be inside every POST form.
 */
function casCsrfField(): string
{
    global $gCurrentSession;
    return '<input type="hidden" name="adm_csrf_token" value="' . e($gCurrentSession->getCsrfToken()) . '">';
}

/**
 * Validates the CSRF token of a POST request. Stops the request if it is missing or wrong.
 */
function casCheckCsrf(): void
{
    global $gMessage;
    try {
        SecurityUtils::validateCsrfToken((string)($_POST['adm_csrf_token'] ?? ''));
    } catch (\Throwable $e) {
        http_response_code(400);
        $gMessage->show('La sesión ha caducado o la petición no es válida. Recarga la página e inténtalo de nuevo.');
        exit;
    }
}

/**
 * Redirects to a page of the plugin (relative file name + optional query parameters).
 */
function casRedirect(string $file, array $params = []): void
{
    $url = CAS_URL . '/' . $file . ($params ? '?' . http_build_query($params) : '');
    header('Location: ' . $url, true, 303);
    exit;
}

/**
 * Shows a generic error and logs the real one (never print SQL errors to the user).
 */
function casFail(string $userMessage, ?\Throwable $e = null): void
{
    global $gMessage;
    if ($e !== null) {
        error_log('[companies_and_sections] ' . $e->getMessage());
    }
    $gMessage->show($userMessage);
    exit;
}

function casUuid(): string
{
    return Uuid::uuid4()->toString();
}

/**
 * Plugin configuration (config.php). Loaded once per request.
 */
function casConfig(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $file = __DIR__ . '/config.php';
        $loaded = is_file($file) ? require $file : [];
        $cfg = is_array($loaded) ? $loaded : [];
    }
    return $cfg;
}

/**
 * SECRETARIAT (full access): administrators and users with a CURRENT membership
 * (mem_begin <= today < mem_end) in one of the roles of config.php -> allowed_roles,
 * belonging to the current organization. They see and edit every company.
 */
function casCanEdit(): bool
{
    global $gCurrentUser, $gDb, $gCurrentOrgId;
    static $result = null;

    if ($result !== null) {
        return $result;
    }
    if (empty($gCurrentUser) || !$gCurrentUser->getValue('usr_id')) {
        return $result = false;
    }
    if ($gCurrentUser->isAdministrator()) {
        return $result = true;
    }

    $allowed = array_values(array_filter(array_map('strval', casConfig()['allowed_roles'] ?? [])));
    if (!$allowed) {
        return $result = false;
    }

    $placeholders = implode(',', array_fill(0, count($allowed), '?'));
    $stmt = $gDb->queryPrepared(
        'SELECT rol_id
           FROM ' . TBL_ROLES . '
     INNER JOIN ' . TBL_CATEGORIES . ' ON cat_id = rol_cat_id
          WHERE rol_name IN (' . $placeholders . ')
            AND rol_valid = true
            AND (cat_org_id = ? OR cat_org_id IS NULL)',
        array_merge($allowed, [$gCurrentOrgId])
    );
    // getRoleMemberships() only returns memberships valid today
    $current = array_map('intval', $gCurrentUser->getRoleMemberships());
    foreach ($stmt->fetchAll() as $row) {
        if (in_array((int)$row['rol_id'], $current, true)) {
            return $result = true;
        }
    }
    return $result = false;
}

/**
 * DELEGATES (read only): ids of the companies where the current user is marked as
 * delegate/a in the assignment, or is the spokesperson (portavoz) of the section.
 * Delegates only see their own companies and cannot change anything.
 *
 * @return int[]
 */
function casDelegateOrgIds(): array
{
    global $gCurrentUser, $gDb;
    static $ids = null;

    if ($ids !== null) {
        return $ids;
    }
    $usrId = (int)($gCurrentUser ? $gCurrentUser->getValue('usr_id') : 0);
    if ($usrId <= 0 || !casSchemaReady()) {
        return $ids = [];
    }
    $stmt = $gDb->queryPrepared(
        'SELECT mro_org_id AS id FROM ' . CAS_TBL_MEMBERS . '
          WHERE mro_usr_id = ? AND mro_active = 1 AND mro_delegado = 1
         UNION
         SELECT org_id FROM ' . CAS_TBL_ORGS . '
          WHERE org_portavoz_seccion = ? AND org_active = 1 AND org_tiene_seccion = 1',
        [$usrId, $usrId]
    );
    return $ids = array_values(array_unique(array_map('intval', array_column($stmt->fetchAll(), 'id'))));
}

/** True if the plugin tables exist with the current columns (installer/upgrade was run). */
function casSchemaReady(): bool
{
    global $gDb;
    static $ok = null;
    if ($ok === null) {
        $ok = $gDb->queryPrepared('SELECT mro_delegado, mro_wkp_id FROM ' . CAS_TBL_MEMBERS . ' LIMIT 1', [], false) !== false
           && $gDb->queryPrepared('SELECT 1 FROM ' . CAS_TBL_WORKPLACES . ' LIMIT 1', [], false) !== false;
    }
    return $ok;
}

/** Secretariat or delegate of at least one company. */
function isUserAuthorized(): bool
{
    return casCanEdit() || casDelegateOrgIds() !== [];
}

function casCanView(int $orgId): bool
{
    return casCanEdit() || in_array($orgId, casDelegateOrgIds(), true);
}

/**
 * Stops the request if the user may not use the plugin at all.
 */
function casRequireAuth(bool $json = false): void
{
    if (!isUserAuthorized()) {
        casDeny($json);
    }
}

/** Stops the request if the user may not edit (only the secretariat edits). */
function casRequireEdit(): void
{
    if (!casCanEdit()) {
        casDeny(false, 'Solo la secretaría puede modificar empresas, centros y asignaciones.');
    }
}

function casDeny(bool $json = false, string $msg = 'Acceso denegado. No tienes permiso para usar este plugin.'): void
{
    global $gMessage;
    http_response_code(403);
    if ($json) {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo json_encode(['error' => 'Acceso denegado']);
        exit;
    }
    $gMessage->show($msg);
    exit;
}

/**
 * Profile field ids of first and last name (looked up by internal name, not hardcoded).
 */
function casNameFieldIds(): array
{
    global $gProfileFields;
    return [
        (int)$gProfileFields->getProperty('FIRST_NAME', 'usf_id'),
        (int)$gProfileFields->getProperty('LAST_NAME', 'usf_id'),
    ];
}

/**
 * All valid users as [usr_id => ['usr_id','usr_uuid','first_name','last_name']], ordered by name.
 */
function casUserList(): array
{
    global $gDb;
    [$fn, $ln] = casNameFieldIds();
    $stmt = $gDb->queryPrepared(
        'SELECT u.usr_id, u.usr_uuid, fn.usd_value AS first_name, ln.usd_value AS last_name
           FROM ' . TBL_USERS . ' u
      LEFT JOIN ' . TBL_USER_DATA . ' fn ON fn.usd_usr_id = u.usr_id AND fn.usd_usf_id = ?
      LEFT JOIN ' . TBL_USER_DATA . ' ln ON ln.usd_usr_id = u.usr_id AND ln.usd_usf_id = ?
          WHERE u.usr_valid = true
       ORDER BY ln.usd_value, fn.usd_value',
        [$fn, $ln]
    );
    $users = [];
    foreach ($stmt->fetchAll() as $row) {
        $users[(int)$row['usr_id']] = $row;
    }
    return $users;
}

function casUserLabel(?array $u): string
{
    if (!$u) {
        return '';
    }
    return trim(($u['last_name'] ?? '') . ', ' . ($u['first_name'] ?? ''), ', ');
}

/**
 * Reads and sanitizes the organization form fields from POST.
 * Returns [data, errors]. Lengths are cut to the column sizes.
 */
function casReadOrgForm(array $users): array
{
    $text = static function (string $key, int $max): string {
        return mb_substr(trim((string)($_POST[$key] ?? '')), 0, $max);
    };
    $errors = [];
    $d = [
        'org_name'             => $text('org_name', 100),
        'org_nombre_comercial' => $text('org_nombre_comercial', 100),
        'org_razon_social'     => $text('org_razon_social', 100),
        'org_cif'              => mb_strtoupper($text('org_cif', 20)),
        'org_forma_juridica'   => $text('org_forma_juridica', 50),
        'org_sector'           => $text('org_sector', 100),
        'org_description'      => $text('org_description', 5000),
        'org_email'            => $text('org_email', 100),
        'org_telefono'         => $text('org_telefono', 20),
        'org_web'              => $text('org_web', 255),
        'org_tiene_seccion'    => isset($_POST['org_tiene_seccion']) ? 1 : 0,
        'org_nombre_seccion'   => $text('org_nombre_seccion', 100),
        'org_email_seccion'    => $text('org_email_seccion', 100),
        'org_portavoz_seccion' => (int)($_POST['org_portavoz_seccion'] ?? 0) ?: null,
        'org_calles'           => $text('org_calles', 255),
        'org_cp'               => $text('org_cp', 10),
        'org_localidad'        => $text('org_localidad', 100),
        'org_provincia'        => $text('org_provincia', 100),
    ];

    if ($d['org_name'] === '') {
        $errors[] = 'El nombre de la empresa es obligatorio.';
    }
    foreach (['org_email' => 'Correo', 'org_email_seccion' => 'Correo de la sección'] as $k => $label) {
        if ($d[$k] !== '' && !filter_var($d[$k], FILTER_VALIDATE_EMAIL)) {
            $errors[] = $label . ' no es una dirección de correo válida.';
        }
    }
    if ($d['org_web'] !== '' && !preg_match('#^https?://#i', $d['org_web'])) {
        $errors[] = 'La web debe empezar por http:// o https://';
    }
    if ($d['org_portavoz_seccion'] !== null && !isset($users[$d['org_portavoz_seccion']])) {
        $errors[] = 'El portavoz seleccionado no existe.';
    }
    if (!$d['org_tiene_seccion']) {
        $d['org_nombre_seccion'] = '';
        $d['org_email_seccion'] = '';
        $d['org_portavoz_seccion'] = null;
    }
    return [$d, $errors];
}

/**
 * SEPA integration (optional): if the profile fields SEPA_PAYMENT_METHOD and SEPA_QUOTA_TYPE
 * exist, returns per PERSON the payment method and, for payroll deduction, the monthly fee.
 * Payroll deduction is personal: there are no totals per company.
 * Dropdown values in Admidio 5 are stored as option ids (ufo_id).
 *
 * @return array{enabled:bool, amounts:array<int,float>, methods:array<int,string>}
 */
function casSepaInfo(array $userIds): array
{
    global $gDb;
    $res = ['enabled' => false, 'amounts' => [], 'methods' => []];

    $fields = [];
    $stmt = $gDb->queryPrepared(
        'SELECT usf_name_intern, usf_id FROM ' . TBL_USER_FIELDS . '
          WHERE usf_name_intern IN (\'SEPA_PAYMENT_METHOD\', \'SEPA_QUOTA_TYPE\')'
    );
    foreach ($stmt->fetchAll() as $row) {
        $fields[$row['usf_name_intern']] = (int)$row['usf_id'];
    }
    if (!isset($fields['SEPA_PAYMENT_METHOD'])) {
        return $res;
    }
    $res['enabled'] = true;
    if (!$userIds) {
        return $res;
    }

    $fees = ['normal' => 12, 'reduced' => 5];
    $sepaCfgFile = __DIR__ . '/../sepa_export/config.php';
    if (is_file($sepaCfgFile)) {
        // the SEPA plugin config may define $sepaFees
        (static function () use ($sepaCfgFile, &$fees) {
            include $sepaCfgFile;
            if (isset($sepaFees) && is_array($sepaFees)) {
                $fees = array_merge($fees, $sepaFees);
            }
        })();
    }

    // option texts by ufo_id
    $options = [];
    $stmt = $gDb->queryPrepared(
        'SELECT ufo_id, ufo_value FROM ' . TBL_USER_FIELD_OPTIONS . ' WHERE ufo_usf_id IN (?, ?)',
        [$fields['SEPA_PAYMENT_METHOD'], $fields['SEPA_QUOTA_TYPE'] ?? 0]
    );
    foreach ($stmt->fetchAll() as $row) {
        $options[(int)$row['ufo_id']] = (string)$row['ufo_value'];
    }

    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $stmt = $gDb->queryPrepared(
        'SELECT usd_usr_id, usd_usf_id, usd_value FROM ' . TBL_USER_DATA . '
          WHERE usd_usf_id IN (?, ?) AND usd_usr_id IN (' . $placeholders . ')',
        array_merge([$fields['SEPA_PAYMENT_METHOD'], $fields['SEPA_QUOTA_TYPE'] ?? 0], array_map('intval', $userIds))
    );
    $method = [];
    $quota = [];
    foreach ($stmt->fetchAll() as $row) {
        $text = $options[(int)$row['usd_value']] ?? (string)$row['usd_value'];
        if ((int)$row['usd_usf_id'] === $fields['SEPA_PAYMENT_METHOD']) {
            $method[(int)$row['usd_usr_id']] = $text;
        } else {
            $quota[(int)$row['usd_usr_id']] = mb_strtolower($text);
        }
    }

    foreach ($userIds as $uid) {
        $uid = (int)$uid;
        $m = $method[$uid] ?? '';
        $res['methods'][$uid] = $m;
        if (preg_match('/n[oó]mina/iu', $m)) {
            $q = $quota[$uid] ?? 'normal';
            $res['amounts'][$uid] = (float)(str_contains($q, 'reducida') ? $fees['reduced'] : $fees['normal']);
        }
    }
    return $res;
}

/**
 * Reads and validates the workplace (centro de trabajo) form from POST.
 * Returns [data, errors].
 */
function casReadWorkplaceForm(): array
{
    $text = static function (string $key, int $max): string {
        return mb_substr(trim((string)($_POST[$key] ?? '')), 0, $max);
    };
    $d = [
        'wkp_name'      => $text('wkp_name', 100),
        'wkp_calles'    => $text('wkp_calles', 255),
        'wkp_cp'        => $text('wkp_cp', 10),
        'wkp_localidad' => $text('wkp_localidad', 100),
        'wkp_provincia' => $text('wkp_provincia', 100),
        'wkp_telefono'  => $text('wkp_telefono', 20),
        'wkp_notas'     => $text('wkp_notas', 2000),
    ];
    $errors = [];
    if ($d['wkp_name'] === '') {
        $errors[] = 'El nombre del centro de trabajo es obligatorio.';
    }
    return [$d, $errors];
}

/**
 * Workplaces of a company, ordered by name: [wkp_id => row].
 */
function casWorkplaces(int $orgId): array
{
    global $gDb;
    $out = [];
    $stmt = $gDb->queryPrepared(
        'SELECT * FROM ' . CAS_TBL_WORKPLACES . ' WHERE wkp_org_id = ? ORDER BY wkp_name',
        [$orgId]
    );
    foreach ($stmt->fetchAll() as $row) {
        $out[(int)$row['wkp_id']] = $row;
    }
    return $out;
}
