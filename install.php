<?php
/**
 * Organization Members Plugin — Automatic installer for Admidio 5.x
 *
 * This script installs the "Organization Members" plugin, which allows managing
 * organizations/companies and assigning members to them within Admidio.
 *
 * INSTALLER ACTIONS:
 * 1. Creates the CRM tables in the database
 * 2. Creates the "Company" profile field (TEXT type)
 * 3. Adds the field to the Contacts list
 * 4. Injects JS into index.tpl to display links in profiles
 * 5. Adds an item to the administration menu
 * 6. Modifies contacts_data.php to display links to organizations
 * 7. Adds CSS styles for .crm-link in contacts.list.tpl
 *
 * Usage: Access this URL as an Admidio administrator:
 *   http://your-server/adm_plugins/organization_members/install.php
 */

use Admidio\ProfileFields\Entity\ProfileField;
use Admidio\Menu\Entity\MenuEntry;

require_once(__DIR__ . '/../../system/common.php');

// Security verification: only administrators can execute this installer
if (!$gCurrentUser->isAdministrator()) {
    http_response_code(403);
    die('<h2>Acceso denegado. Debes ser administrador de Admidio.</h2>');
}

// Define constants for paths and configuration
define('PLUGIN_DIR', __DIR__);
define('ADMIDIO_ROOT', realpath(__DIR__ . '/../../'));
define('INDEX_TPL', ADMIDIO_ROOT . '/themes/simple/templates/index.tpl');
define('CONTACTS_DATA_PHP', ADMIDIO_ROOT . '/modules/contacts/contacts_data.php');
define('CONTACTS_LIST_TPL', ADMIDIO_ROOT . '/themes/simple/templates/modules/contacts.list.tpl');
define('CONFIG_FILE', PLUGIN_DIR . '/config.php');
define('SELF', __FILE__);

// initialize variables for error and success messages
$errors   = [];
$warnings = [];
$success  = false;

// Process installation form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF validation to prevent request forgery attacks
    $submittedToken = $_POST['adm_csrf_token'] ?? '';
    if ($submittedToken !== $gCurrentSession->getCsrfToken()) {
        die('Token CSRF inválido. Recarga la página e inténtalo de nuevo.');
    }

    // Step 1: Create database tables
    [$tableErrors, $tableWarnings] = createTables($gDb);
    $errors   = array_merge($errors, $tableErrors);
    $warnings = array_merge($warnings, $tableWarnings);

    // Step 2: Create profile field and add it to the contacts list
    if (empty($errors)) {
        [$fieldErrors, $fieldWarnings] = createProfileFields($gDb, $gCurrentOrgId);
        $errors   = array_merge($errors, $fieldErrors);
        $warnings = array_merge($warnings, $fieldWarnings);
    }

    // Step 3: Modify contacts_data.php to display links to organizations
    if (empty($errors)) {
        $contactsResult = injectContactsDataHandling();
        if ($contactsResult !== true) {
            $warnings[] = $contactsResult;
        }
    }

    // Step 4: Add CSS styles for .crm-link in contacts.list.tpl
    if (empty($errors)) {
        $cssResult = injectContactsCss();
        if ($cssResult !== true) {
            $warnings[] = $cssResult;
        }
    }

    // Step 5: Inject JS into index.tpl
    if (empty($errors)) {
        $tplResult = injectJsInTemplate();
        if ($tplResult !== true) {
            $warnings[] = $tplResult;
        }
    }

    // Step 6: Add item to the menu
    if (empty($errors)) {
        $menuResult = addMenuItem($gDb);
        if ($menuResult !== true) {
            $warnings[] = $menuResult;
        }
    }

    // If no errors, mark installation as successful and create default config file if it doesn't exist
    if (empty($errors)) {
        $success = true;
        // create default config.php if it doesn't exist, with empty allowed_roles (only admins can access)
        $cfgPath = PLUGIN_DIR . '/config.php';
        if (!file_exists($cfgPath)) {
            $defaultCfg = <<<'PHP'
<?php
return [
    // Example: ['Organization Manager', 'HR']
    'allowed_roles' => [],
];
PHP;
            @file_put_contents($cfgPath, $defaultCfg);
        }

        register_shutdown_function(function () { @unlink(SELF); });
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Installation form HTML output
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Create database tables for the CRM.
 *
 * Tables created:
 * - crm_organizations: Stores organization/company data
 * - crm_member_organizations: Relationship between users and organizations
 *
 * @param Database $db Database object of Admidio
 * @return array Array with ['errors' => [], 'warnings' => []]
 */
function createTables($db): array
{
    $errors   = [];
    $warnings = [];

    // Main table for organizations
    // Contains all data for each company: address, contact, union section, etc.
    $tableSql = "
        CREATE TABLE IF NOT EXISTS `crm_organizations` (
            `org_id` INT AUTO_INCREMENT PRIMARY KEY,
            `org_uuid` VARCHAR(36) NOT NULL UNIQUE,
            `org_name` VARCHAR(100) NOT NULL,
            `org_description` TEXT,
            `org_active` TINYINT(1) DEFAULT 1,
            `org_usr_id_create` INT DEFAULT 1,
            `org_timestamp_create` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `org_usr_id_change` INT DEFAULT NULL,
            `org_timestamp_change` TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
            `org_tiene_seccion` TINYINT(1) DEFAULT 0,
            `org_seccion_sindical` VARCHAR(100) DEFAULT NULL,
            `org_calles` VARCHAR(255) DEFAULT NULL,
            `org_cp` VARCHAR(10) DEFAULT NULL,
            `org_localidad` VARCHAR(100) DEFAULT NULL,
            `org_provincia` VARCHAR(100) DEFAULT NULL,
            `org_telefono` VARCHAR(20) DEFAULT NULL,
            `org_email` VARCHAR(100) DEFAULT NULL,
            `org_web` VARCHAR(255) DEFAULT NULL,
            `org_nombre_comercial` VARCHAR(100) DEFAULT NULL,
            `org_razon_social` VARCHAR(100) DEFAULT NULL,
            `org_cif` VARCHAR(20) DEFAULT NULL,
            `org_forma_juridica` VARCHAR(50) DEFAULT NULL,
            `org_sector` VARCHAR(100) DEFAULT NULL,
            `org_email_seccion` VARCHAR(100) DEFAULT NULL,
            `org_portavoz_seccion` INT DEFAULT NULL,
            `org_nombre_seccion` VARCHAR(100) DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    try {
        $db->query($tableSql);
        $warnings[] = 'Tabla <code>crm_organizations</code> creada/verificada.';
    } catch (\Throwable $e) {
        $errors[] = 'Error creando tabla crm_organizations: ' . $e->getMessage();
        return [$errors, $warnings];
    }

    // Table for the relationship between members and organizations
    // Allows assigning multiple members to an organization and vice versa
    $memberSql = "
        CREATE TABLE IF NOT EXISTS `crm_member_organizations` (
            `mro_id` INT AUTO_INCREMENT PRIMARY KEY,
            `mro_uuid` VARCHAR(36) NOT NULL UNIQUE,
            `mro_usr_id` INT NOT NULL,
            `mro_org_id` INT NOT NULL,
            `mro_role` VARCHAR(100) DEFAULT NULL,
            `mro_active` TINYINT(1) DEFAULT 1,
            `mro_timestamp_create` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `mro_usr_id_create` INT DEFAULT 1,
            UNIQUE KEY `uk_member_org` (`mro_usr_id`, `mro_org_id`),
            KEY `idx_org_id` (`mro_org_id`),
            KEY `idx_usr_id` (`mro_usr_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    try {
        $db->query($memberSql);
        $warnings[] = 'Tabla <code>crm_member_organizations</code> creada/verificada.';
    } catch (\Throwable $e) {
        $errors[] = 'Error creando tabla crm_member_organizations: ' . $e->getMessage();
    }

    return [$errors, $warnings];
}

/**
 * Create the profile field "Company" and add it to the list of Contacts.
 *
 * The field is of type TEXT (not dropdown) to allow writing any company name
 * freely. The synchronization with organizations is done through the
 * syncOrgNameInContacts() function.
 *
 * If the field already exists as a DROPDOWN, it converts it to TEXT, migrating the data.
 *
 * @param Database $db Database object of Admidio
 * @param int $orgId ID of the current organization
 * @return array Array with ['errors' => [], 'warnings' => []]
 */
function createProfileFields($db, int $orgId): array
{
    $errors   = [];
    $warnings = [];

    // Search for a category for profile fields of type USF (User Fields) that belongs to the current organization or is global (org_id IS NULL).
    // The categories organize the fields into groups within the profile. We need to find the appropriate category to add our "Company" field.
    $sql = "SELECT cat_id FROM " . TBL_CATEGORIES . "
            WHERE cat_type = 'USF'
            AND (cat_org_id = ? OR cat_org_id IS NULL)
            ORDER BY cat_org_id DESC, cat_sequence ASC
            LIMIT 1";
    $stmt = $db->queryPrepared($sql, [$orgId]);
    $catRow = $stmt->fetch();

    if (!$catRow) {
        $errors[] = 'No se encontró ninguna categoría de campos de perfil.';
        return [$errors, $warnings];
    }
    $catId = (int)$catRow['cat_id'];

    // Obtain the next sequence number to position the field at the end of the category
    $stmtMax = $db->queryPrepared(
        "SELECT COALESCE(MAX(usf_sequence), 0) FROM " . TBL_USER_FIELDS . " WHERE usf_cat_id = ?",
        [$catId]
    );
    $maxOrder = (int)$stmtMax->fetchColumn();

    $fieldNameIntern = 'CRM_EMPRESA';

    // Verify if the field already exists
    $stmtCheck = $db->queryPrepared(
        "SELECT usf_id, usf_type FROM " . TBL_USER_FIELDS . " WHERE usf_name_intern = ?",
        [$fieldNameIntern]
    );
    $existingField = $stmtCheck->fetch();

    if ($existingField) {
        $existingFieldId = (int)$existingField['usf_id'];

        // If it is a DROPDOWN (previous version), convert to TEXT and migrate data
        // This is necessary because the structure changed from dropdown to free text
        if ($existingField['usf_type'] === 'DROPDOWN') {
            try {
                // Migrate data: the saved values are ufo_id, we need the name
                // We do a JOIN with the dropdown options to get the text
                $migrateSql = "
                    UPDATE " . TBL_USER_DATA . " ud
                    INNER JOIN " . TBL_USER_FIELDS . " uf ON ud.usd_usf_id = uf.usf_id
                    LEFT JOIN adm_user_field_select_options ufo ON ud.usd_value = ufo.ufo_id AND ufo.ufo_usf_id = uf.usf_id
                    SET ud.usd_value = COALESCE(ufo.ufo_value, ud.usd_value)
                    WHERE uf.usf_name_intern = 'CRM_EMPRESA'
                ";
                $db->query($migrateSql);
                $warnings[] = 'Datos migrados de ufo_id a nombres de organización.';

                // Convert the field type from DROPDOWN to TEXT
                $db->query("UPDATE " . TBL_USER_FIELDS . " SET usf_type = 'TEXT' WHERE usf_id = " . $existingFieldId);
                $warnings[] = 'Campo <code>Empresa</code> convertido de DROPDOWN a TEXT.';

                // Delete the old dropdown options since they are no longer needed
                $db->query("DELETE FROM adm_user_field_select_options WHERE ufo_usf_id = " . $existingFieldId);
                $warnings[] = 'Opciones antiguas del dropdown eliminadas.';

            } catch (\Throwable $e) {
                $warnings[] = 'Error migrando datos: ' . $e->getMessage();
            }
        } else {
            $warnings[] = 'Campo <code>Empresa</code> ya existe y es TEXT — omitido.';
        }
    } else {
        // Create the new field if it doesn't exist
        try {
            $profileField = new ProfileField($db);
            $profileField->setValue('usf_cat_id', $catId);
            $profileField->setValue('usf_type', 'TEXT');
            $profileField->setValue('usf_name_intern', $fieldNameIntern);
            $profileField->setValue('usf_name', 'Empresa');
            $profileField->setValue('usf_description', 'Empresa a la que pertenece el miembro (CRM CNT)');
            $profileField->setValue('usf_icon', 'bi-building');
            $profileField->setValue('usf_required_input', 0);
            $profileField->setValue('usf_registration', 2);
            $profileField->setValue('usf_sequence', $maxOrder + 1);
            $profileField->setValue('usf_system', 0);
            $profileField->setValue('usf_hidden', 0);
            $profileField->save();

            $fieldId = $profileField->getValue('usf_id');
            $warnings[] = 'Campo <code>Empresa</code> creado en perfil de miembros (ID: ' . $fieldId . ').';
        } catch (\Throwable $e) {
            $errors[] = 'Error creando campo Empresa: ' . $e->getMessage();
        }
    }

    // Add the field to the Contacts list so it appears in the table
    $listResult = addFieldToContactsList($db);
    if ($listResult !== true) {
        $warnings[] = $listResult;
    }

    return [$errors, $warnings];
}

/**
 * Adds the "Company" field to the Contacts list in Admidio.
 *
 * This makes the field appear as a column in the contacts list.
 *
 * @param Database $db Admidio database object
 * @return bool|string True if added, string with error if failed
 */
function addFieldToContactsList($db): bool|string
{
    // Obtain the ID of the Company field
    $stmt = $db->queryPrepared(
        "SELECT usf_id FROM " . TBL_USER_FIELDS . " WHERE usf_name_intern = 'CRM_EMPRESA'"
    );
    $field = $stmt->fetch();

    if (!$field) {
        return 'Campo CRM_EMPRESA no encontrado, no se pudo añadir a la lista de Contacts.';
    }

    $usfId = (int)$field['usf_id'];

    // Obtain the "Contacts" list from Admidio
    $stmt = $db->queryPrepared(
        "SELECT lst_id FROM " . TBL_LISTS . " WHERE lst_name = 'Contacts' LIMIT 1"
    );
    $list = $stmt->fetch();

    if (!$list) {
        return 'Lista de Contacts no encontrada.';
    }

    $lstId = (int)$list['lst_id'];

    // Verify if it's already added to the list
    $stmt = $db->queryPrepared(
        "SELECT lsc_id FROM " . TBL_LIST_COLUMNS . " WHERE lsc_lst_id = ? AND lsc_usf_id = ?",
        [$lstId, $usfId]
    );

    if ($stmt->fetch()) {
        return true; // Already added, no action needed
    }

    // Obtain the next sequence number for the column to position it at the end of the list
    $stmt = $db->queryPrepared(
        "SELECT COALESCE(MAX(lsc_number), 0) FROM " . TBL_LIST_COLUMNS . " WHERE lsc_lst_id = ?",
        [$lstId]
    );
    $maxNum = (int)$stmt->fetchColumn();

    try {
        $db->queryPrepared(
            "INSERT INTO " . TBL_LIST_COLUMNS . " (lsc_lst_id, lsc_number, lsc_usf_id) VALUES (?, ?, ?)",
            [$lstId, $maxNum + 1, $usfId]
        );
        return true;
    } catch (\Throwable $e) {
        return 'No se pudo añadir el campo a la lista de Contacts: ' . $e->getMessage();
    }
}

/**
 * Injects JavaScript code into index.tpl to display links to organizations
 * in the member profiles.
 *
 * The script profile_js.php is loaded asynchronously to avoid affecting
 * the performance of the main page.
 *
 * @return bool|string True if injected, string with error if failed
 */
function injectJsInTemplate(): bool|string
{
    if (!file_exists(INDEX_TPL)) {
        return 'No se encontró index.tpl. Añade manualmente el script si es necesario.';
    }

    $content = file_get_contents(INDEX_TPL);

    // Verify if it's already injected (avoid duplicates)
    if (str_contains($content, 'organization_members/profile_js.php')) {
        return true;
    }

    // Insert the script before the closing body tag
    $scriptTag  = '    <script src="{$urlAdmidio}/adm_plugins/organization_members/profile_js.php" defer></script>';
    $newContent = str_replace('</body>', $scriptTag . "\n</body>", $content);

    if ($newContent === $content) {
        return 'No se encontró </body> en index.tpl. Añade manualmente el script.';
    }

    if (file_put_contents(INDEX_TPL, $newContent) === false) {
        return 'No se pudo escribir index.tpl. Comprueba los permisos.';
    }

    return true;
}

/**
 * Adds the "Organizations & Members" menu item to the administration menu.
 *
 * The item is placed under the administration node and uses the building icon.
 *
 * @param Database $db Admidio database object
 * @return bool|string True if created, string with error if failed
 */
function addMenuItem($db): bool|string
{
    // Check if the menu item already exists by men_name_intern
    $stmtCheck = $db->queryPrepared(
        "SELECT men_id FROM " . TBL_MENU . " WHERE men_name_intern = 'crm_organizations' LIMIT 1"
    );
    $existingMenu = $stmtCheck->fetch();

    if ($existingMenu) {
        // Already exists, update the URL to organizations.php
        try {
            $db->queryPrepared(
                "UPDATE " . TBL_MENU . " SET men_url = '/adm_plugins/organization_members/organizations.php' WHERE men_id = ?",
                [(int)$existingMenu['men_id']]
            );
            return true;
        } catch (\Throwable $e) {
            return 'No se pudo actualizar el ítem de menú: ' . $e->getMessage();
        }
    }

    // No exists, create new item
    // Search for the administration parent node
    $stmtParent = $db->queryPrepared(
        "SELECT men_id FROM " . TBL_MENU . "
         WHERE men_node = 1
           AND (men_name_intern = 'administration' OR men_name_intern LIKE '%admin%')
         ORDER BY men_id ASC LIMIT 1"
    );
    $parentRow = $stmtParent->fetch();
    $parentId  = $parentRow ? (int)$parentRow['men_id'] : null;

    // Obtain the next order within the parent
    $stmtMax  = $db->queryPrepared(
        "SELECT COALESCE(MAX(men_order), 0) FROM " . TBL_MENU . " WHERE men_men_id_parent " . ($parentId ? "= ?" : "IS NULL"),
        $parentId ? [$parentId] : []
    );
    $maxOrder = (int)$stmtMax->fetchColumn();

    try {
        $menuEntry = new MenuEntry($db);
        $menuEntry->setValue('men_men_id_parent', $parentId);
        $menuEntry->setValue('men_node',          0);
        $menuEntry->setValue('men_order',         $maxOrder + 1);
        $menuEntry->setValue('men_standard',      0);
        $menuEntry->setValue('men_name_intern',   'crm_organizations');
        $menuEntry->setValue('men_name',          'Organizations & Members');
        $menuEntry->setValue('men_url',           '/adm_plugins/organization_members/organizations.php');
        $menuEntry->setValue('men_icon',          'bi-building');
        $menuEntry->save();
    } catch (\Throwable $e) {
        return 'No se pudo crear el ítem de menú: ' . $e->getMessage()
            . '. Créalo manualmente.';
    }

    return true;
}

/**
 * Injects special handling for the CRM_EMPRESA field in contacts_data.php.
 *
 * This modification allows the company name to appear as a clickable link in the Contacts list,
 * leading to the organization's profile.
 *
 * IMPORTANT: This function modifies a core Admidio file, not the plugin.
 *
 * @return bool|string True if modified, string with error if failed
 */
function injectContactsDataHandling(): bool|string
{
    if (!file_exists(CONTACTS_DATA_PHP)) {
        return 'No se encontró contacts_data.php.';
    }

    $content = file_get_contents(CONTACTS_DATA_PHP);

    // Verify if the code is already injected to avoid duplicates
    if (str_contains($content, 'CRM_EMPRESA field')) {
        return true;
    }

    // Search for the line where columns of the list are processed
    // We need to insert the code before processing each column
    $searchPattern = '// add all columns of the list configuration';

    if (!str_contains($content, $searchPattern)) {
        return 'No se encontró el lugar para insertar el código en contacts_data.php.';
    }

    // Code to insert: detect the Empresa field and convert it to a link
    // Code to inject: dynamically detect the CRM_EMPRESA field ID
    $crmInjectionCode = <<<'PHP'

            // Organization Members Plugin: Get CRM_EMPRESA field ID dynamically (cached)
            static $crmUsfId = null;
            if ($crmUsfId === null) {
                foreach ($gProfileFields->getProfileFields() as $pf) {
                    if ($pf->getValue('usf_name_intern') === 'CRM_EMPRESA') {
                        $crmUsfId = (int)$pf->getValue('usf_id');
                        break;
                    }
                }
            }
            
            // Special handling for CRM_EMPRESA field - show as clickable link
            if ($crmUsfId !== null && $usfId === $crmUsfId && !empty($row[$ColumnNumberSql])) {
                $empresaName = $contactsListConfig->convertColumnContentForOutput($columnNumber, "html", $row[$ColumnNumberSql], $row["usr_uuid"], false);
                if (!empty($empresaName) && $empresaName !== "Deleted Entry") {
                    $host = getenv("ADMIDIO_DB_HOST") ?: "db";
                    $dbname = getenv("ADMIDIO_DB_NAME") ?: "admidio";
                    $dbuser = getenv("ADMIDIO_DB_USER") ?: "admidio";
                    $dbpass = getenv("ADMIDIO_DB_PASSWORD") ?: "admidiopass";
                    try {
                        $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";
                        $crmDb = new PDO($dsn, $dbuser, $dbpass);
                        $stmt = $crmDb->prepare("SELECT org_id FROM crm_organizations WHERE org_name = ? AND org_active = 1 LIMIT 1");
                        $stmt->execute([$empresaName]);
                        $org = $stmt->fetch();
                        if ($org) {
                            $columnValues[(string)$columnNumberJson] = "<a href=\"" . ADMIDIO_URL . "/adm_plugins/organization_members/organization_profile.php?org_id=" . $org["org_id"] . "\" class=\"crm-link\"><i class=\"bi bi-building\"></i> " . $empresaName . "</a>";
                        } else {
                            $columnValues[(string)$columnNumberJson] = $empresaName;
                        }
                    } catch (Exception $e) {
                        $columnValues[(string)$columnNumberJson] = $empresaName;
                    }
                } else {
                    $columnValues[(string)$columnNumberJson] = $empresaName;
                }
            } else {
PHP;

    // Search for the line where each column is processed
    $searchLine = '$usfId = (int)$column->getValue("lsc_usf_id");';

    if (!str_contains($content, $searchLine)) {
        return 'No se encontró la ubicación para insertar el código en contacts_data.php.';
    }

    // Replace the line and add the code
    $newContent = str_replace(
        $searchLine,
        $searchLine . "\n" . $crmInjectionCode,
        $content
    );

    if ($newContent === $content) {
        return 'No se pudo modificar contacts_data.php.';
    }

    if (file_put_contents(CONTACTS_DATA_PHP, $newContent) === false) {
        return 'No se pudo escribir contacts_data.php. Comprueba los permisos.';
    }

    return true;
}

/**
 * Injects CSS styles for the .crm-link class in contacts.list.tpl.
 *
 * These styles make the links to organizations appear
 * in red (CNT color) and bold.
 *
 * @return bool|string True if added, string with error if failed
 */
function injectContactsCss(): bool|string
{
    if (!file_exists(CONTACTS_LIST_TPL)) {
        return 'No se encontró contacts.list.tpl.';
    }

    $content = file_get_contents(CONTACTS_LIST_TPL);

    // Verify if the style is already added
    if (str_contains($content, '.crm-link')) {
        return true;
    }

    // CSS styles to add
    $crmLinkCss = <<<'CSS'

/* Companies and Sections Plugin - CRM Link Styles */
.crm-link { color: #DA291C; text-decoration: none; font-weight: 600; }
.crm-link:hover { text-decoration: underline; }

CSS;

    // Insert at the beginning of the <style> block or before the closing </style>
    if (str_contains($content, '<style>')) {
        $newContent = str_replace('<style>', "<style>\n" . $crmLinkCss, $content);
    } else {
        return 'No se encontró el bloque <style> en contacts.list.tpl.';
    }

    if ($newContent === $content) {
        return 'No se pudo modificar contacts.list.tpl.';
    }

    if (file_put_contents(CONTACTS_LIST_TPL, $newContent) === false) {
        return 'No se pudo escribir contacts.list.tpl. Comprueba los permisos.';
    }

    return true;
}

// ─────────────────────────────────────────────────────────────────────────────
// User Interface for the installer form and success message
// ─────────────────────────────────────────────────────────────────────────────
$token = $gCurrentSession->getCsrfToken();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Instalador — Organization Members Plugin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background: #f8f9fa; }
        .installer-card { max-width: 780px; margin: 2rem auto; }
        .step-header { background: #DA291C; color: #fff; border-radius: .375rem .375rem 0 0; padding: 1.2rem 1.5rem; }
        .step-header h1 { font-size: 1.4rem; margin: 0; }
        code { background: #e9ecef; padding: .15rem .4rem; border-radius: .25rem; font-size: .9em; }
        .field-group { border-left: 3px solid #DA291C; padding-left: 1rem; margin-bottom: 1.5rem; }
        .success-icon { font-size: 4rem; color: #198754; }
    </style>
</head>
<body>
<div class="installer-card">

<?php if ($success): ?>
    <!-- ── Success Screen ─────────────────────────────────────────────── -->
    <div class="card shadow">
        <div class="step-header" style="background:#198754">
            <h1><i class="bi bi-check-circle"></i> Instalación completada</h1>
        </div>
        <div class="card-body text-center py-5">
            <div class="success-icon mb-3"><i class="bi bi-building"></i></div>
            <h2 class="mb-3">Plugin Organization Members instalado correctamente</h2>

            <?php if (!empty($warnings)): ?>
            <div class="alert alert-info text-start mb-4">
                <strong><i class="bi bi-info-circle"></i> Información:</strong>
                <ul class="mb-0 mt-2">
                    <?php foreach ($warnings as $w): ?><li><?= $w ?></li><?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <div class="alert alert-success text-start mb-4">
                <strong><i class="bi bi-check-lg"></i> Qué se ha creado:</strong>
                <ul class="mb-0 mt-2">
                    <li>✅ Tablas <code>crm_organizations</code> y <code>crm_member_organizations</code></li>
                    <li>✅ Campo <code>Empresa</code> en perfil de miembros</li>
                    <li>✅ Campo <code>Empresa</code> añadido a la lista de Contacts</li>
                    <li>✅ Enlaces clicables a organizaciones en Contacts</li>
                    <li>✅ Ítem de menú <code>Organizations & Members</code></li>
                    <li>✅ Script JS inyectado en <code>index.tpl</code></li>
                    <li>✅ Estilos CSS añadidos en <code>contacts.list.tpl</code></li>
                </ul>
            </div>

            <div class="alert alert-info text-start mb-4">
                <strong><i class="bi bi-lightbulb"></i> Próximos pasos:</strong>
                <ol class="mb-0 mt-2">
                    <li>Ve a <strong>Organizations & Members</strong> desde el menú</li>
                    <li>Crea tu primera empresa</li>
                    <li>Asigna miembros a las empresas</li>
                    <li>Los miembros verán su empresa en Contacts y en su perfil</li>
                </ol>
            </div>

            <a href="organizations.php" class="btn btn-success btn-lg">
                <i class="bi bi-building"></i> Ir a Organizations & Members
            </a>
        </div>
    </div>

<?php else: ?>
    <!-- ── INSTALLATION FORM ────────────────────────────────────── -->
    <div class="card shadow">
        <div class="step-header">
            <h1><i class="bi bi-download"></i> Instalador — Organization Members Plugin</h1>
            <small class="opacity-75">Plugin para gestionar organizaciones y asignar miembros</small>
        </div>
        <div class="card-body p-4">

            <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <strong><i class="bi bi-x-circle"></i> Errores:</strong>
                <ul class="mb-0 mt-2">
                    <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <?php
            $preChecks = [];
            if (file_exists(CONFIG_FILE))
                $preChecks[] = ['warn', 'Ya existe <code>config.php</code>.'];
            if (!is_writable(PLUGIN_DIR))
                $preChecks[] = ['error', 'El directorio del plugin no tiene permisos de escritura.'];
            if (!is_writable(INDEX_TPL))
                $preChecks[] = ['warn', '<code>index.tpl</code> no tiene permisos de escritura.'];
            if (!is_writable(CONTACTS_DATA_PHP))
                $preChecks[] = ['warn', '<code>contacts_data.php</code> no tiene permisos de escritura.'];
            if (!is_writable(CONTACTS_LIST_TPL))
                $preChecks[] = ['warn', '<code>contacts.list.tpl</code> no tiene permisos de escritura.'];
            if (!file_exists(INDEX_TPL))
                $preChecks[] = ['warn', 'No se encontró <code>index.tpl</code>.'];
            if (!file_exists(CONTACTS_DATA_PHP))
                $preChecks[] = ['warn', 'No se encontró <code>contacts_data.php</code>.'];
            if (!file_exists(CONTACTS_LIST_TPL))
                $preChecks[] = ['warn', 'No se encontró <code>contacts.list.tpl</code>.'];
            ?>
            <?php if (!empty($preChecks)): ?>
            <div class="mb-4">
                <?php foreach ($preChecks as [$type, $msg]): ?>
                <div class="alert alert-<?= $type === 'error' ? 'danger' : 'warning' ?> py-2 mb-2">
                    <i class="bi bi-<?= $type === 'error' ? 'x-circle' : 'exclamation-triangle' ?>"></i> <?= $msg ?>
                </div>
                <?php endforeach; ?>
                <?php if (in_array(['error', 'El directorio del plugin no tiene permisos de escritura.'], $preChecks)): ?>
                <div class="alert alert-info mt-3">
                    <strong><i class="bi bi-info-circle"></i> Solución:</strong>
                    Ejecuta en el servidor: <code>chmod 755 <?= PLUGIN_DIR ?></code>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <form method="POST" novalidate>
                <input type="hidden" name="adm_csrf_token" value="<?= htmlspecialchars($token) ?>">

                <div class="field-group">
                    <h5 class="mb-3"><i class="bi bi-building"></i> Este instalador creará:</h5>

                    <div class="alert alert-light border mb-4">
                        <ol class="mb-0 mt-2">
                            <li><strong>Tablas en la base de datos:</strong>
                                <ul>
                                    <li><code>crm_organizations</code> — Empresas con todos sus datos</li>
                                    <li><code>crm_member_organizations</code> — Relación miembros-empresas</li>
                                </ul>
                            </li>
                            <li><strong>Campo de perfil:</strong>
                                <ul>
                                    <li><code>Empresa</code> — Campo de texto en perfil de miembros</li>
                                    <li>Añadido a la lista de Contacts</li>
                                    <li>Enlaces clicables a la organización</li>
                                </ul>
                            </li>
                            <li><strong>Ficheros de Admidio modificados:</strong>
                                <ul>
                                    <li><code>contacts_data.php</code> — Para mostrar enlaces</li>
                                    <li><code>contacts.list.tpl</code> — Estilos CSS</li>
                                    <li><code>index.tpl</code> — Script JS para perfiles</li>
                                </ul>
                            </li>
                            <li><strong>Menú:</strong>
                                <ul>
                                    <li>Ítem <code>Organizations & Members</code></li>
                                </ul>
                            </li>
                        </ol>
                    </div>
                </div>

                <div class="d-grid">
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="bi bi-play-fill"></i> Instalar
                    </button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

</div>
</body>
</html>
