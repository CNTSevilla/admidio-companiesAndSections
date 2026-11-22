<?php
/**
 * Companies and Sections Plugin - Installer for Admidio 5.x
 *
 * What it does (and nothing else):
 * 1. Creates the tables crm_organizations and crm_member_organizations
 * 2. Adds the menu entry "Empresas y secciones" under Administration
 * 3. Creates config.php (only administrators) if it does not exist
 *
 * It does NOT modify any Admidio core file or template and does NOT create profile fields.
 * Can be run again safely: it only creates what is missing.
 *
 * Usage (as Admidio administrator):
 *   https://your-server/adm_plugins/companies_and_sections/install.php
 * Delete this file after installing.
 */

use Admidio\Menu\Entity\MenuEntry;

require_once(__DIR__ . '/organization_functions.php');

if (!$gCurrentUser->isAdministrator()) {
    http_response_code(403);
    exit('Acceso denegado. Debes ser administrador de Admidio.');
}

const CAS_MENU_URL = FOLDER_PLUGINS . '/' . CAS_FOLDER . '/organizations.php';

$messages = [];   // [type, text]
$done = false;

function casCreateTables(): array
{
    global $gDb;
    $out = [];
    $orgsExisted = $gDb->queryPrepared('SELECT 1 FROM ' . CAS_TBL_ORGS . ' LIMIT 1', [], false) !== false;
    $memExisted  = $gDb->queryPrepared('SELECT 1 FROM ' . CAS_TBL_MEMBERS . ' LIMIT 1', [], false) !== false;

    $gDb->queryPrepared('
        CREATE TABLE IF NOT EXISTS ' . CAS_TBL_ORGS . ' (
            org_id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            org_uuid             VARCHAR(36)  NOT NULL UNIQUE,
            org_name             VARCHAR(100) NOT NULL,
            org_description      TEXT,
            org_active           TINYINT(1)   NOT NULL DEFAULT 1,
            org_usr_id_create    INT UNSIGNED DEFAULT NULL,
            org_timestamp_create TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            org_usr_id_change    INT UNSIGNED DEFAULT NULL,
            org_timestamp_change TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            org_tiene_seccion    TINYINT(1)   NOT NULL DEFAULT 0,
            org_seccion_sindical VARCHAR(100) DEFAULT NULL,
            org_calles           VARCHAR(255) DEFAULT NULL,
            org_cp               VARCHAR(10)  DEFAULT NULL,
            org_localidad        VARCHAR(100) DEFAULT NULL,
            org_provincia        VARCHAR(100) DEFAULT NULL,
            org_telefono         VARCHAR(20)  DEFAULT NULL,
            org_email            VARCHAR(100) DEFAULT NULL,
            org_web              VARCHAR(255) DEFAULT NULL,
            org_nombre_comercial VARCHAR(100) DEFAULT NULL,
            org_razon_social     VARCHAR(100) DEFAULT NULL,
            org_cif              VARCHAR(20)  DEFAULT NULL,
            org_forma_juridica   VARCHAR(50)  DEFAULT NULL,
            org_sector           VARCHAR(100) DEFAULT NULL,
            org_email_seccion    VARCHAR(100) DEFAULT NULL,
            org_portavoz_seccion INT UNSIGNED DEFAULT NULL,
            org_nombre_seccion   VARCHAR(100) DEFAULT NULL,
            KEY idx_org_name (org_name),
            CONSTRAINT fk_crm_org_portavoz FOREIGN KEY (org_portavoz_seccion)
                REFERENCES ' . TBL_USERS . ' (usr_id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $out[] = ['info', 'Tabla ' . CAS_TBL_ORGS . ($orgsExisted ? ' ya existía (no se ha modificado).' : ' creada.')];

    $gDb->queryPrepared('
        CREATE TABLE IF NOT EXISTS ' . CAS_TBL_MEMBERS . ' (
            mro_id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            mro_uuid             VARCHAR(36)  NOT NULL UNIQUE,
            mro_usr_id           INT UNSIGNED NOT NULL,
            mro_org_id           INT UNSIGNED NOT NULL,
            mro_role             VARCHAR(100) DEFAULT NULL,
            mro_active           TINYINT(1)   NOT NULL DEFAULT 1,
            mro_timestamp_create TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            mro_usr_id_create    INT UNSIGNED DEFAULT NULL,
            UNIQUE KEY uk_member_org (mro_usr_id, mro_org_id),
            KEY idx_org_id (mro_org_id),
            CONSTRAINT fk_crm_mro_org FOREIGN KEY (mro_org_id)
                REFERENCES ' . CAS_TBL_ORGS . ' (org_id) ON DELETE CASCADE,
            CONSTRAINT fk_crm_mro_usr FOREIGN KEY (mro_usr_id)
                REFERENCES ' . TBL_USERS . ' (usr_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $out[] = ['info', 'Tabla ' . CAS_TBL_MEMBERS . ($memExisted ? ' ya existía (no se ha modificado).' : ' creada.')];
    return $out;
}

function casAddMenuItem(): array
{
    global $gDb;
    // look for the entry by URL (Admidio regenerates men_name_intern on save)
    $existing = $gDb->queryPrepared(
        'SELECT men_id FROM ' . TBL_MENU . ' WHERE men_url = ? OR men_url LIKE ?',
        [CAS_MENU_URL, '%/organization_members/organizations.php']
    )->fetch();
    if ($existing) {
        $gDb->queryPrepared('UPDATE ' . TBL_MENU . ' SET men_url = ? WHERE men_id = ?', [CAS_MENU_URL, (int)$existing['men_id']]);
        return ['info', 'La entrada de menú ya existía (URL actualizada).'];
    }
    $parent = $gDb->queryPrepared(
        'SELECT men_id FROM ' . TBL_MENU . ' WHERE men_node = true AND men_name_intern = \'administration\' LIMIT 1'
    )->fetchColumn();

    $menu = new MenuEntry($gDb);
    $menu->setValue('men_men_id_parent', $parent ? (int)$parent : null);
    $menu->setValue('men_node', false);
    $menu->setValue('men_standard', false);
    $menu->setValue('men_name', 'Empresas y secciones');
    $menu->setValue('men_description', 'Empresas, secciones sindicales y afiliación por empresa');
    $menu->setValue('men_url', CAS_MENU_URL);
    $menu->setValue('men_icon', 'bi-building');
    $menu->save();
    return ['ok', 'Entrada de menú "Empresas y secciones" creada en Administración. '
        . 'Restringe quién la ve en Menú → editar entrada (la página ya comprueba los permisos).'];
}

function casCreateConfig(): array
{
    $file = __DIR__ . '/config.php';
    if (is_file($file)) {
        return ['info', 'config.php ya existe (no se ha tocado).'];
    }
    $content = "<?php\n// Nombres EXACTOS de los roles de Admidio con acceso al plugin.\n"
             . "// Vacío = solo administradores.\nreturn [\n    'allowed_roles' => [],\n];\n";
    if (@file_put_contents($file, $content) === false) {
        return ['warn', 'No se pudo crear config.php (carpeta sin permisos de escritura). '
            . 'Cópialo a mano desde config.example.php.'];
    }
    @chmod($file, 0640);
    return ['ok', 'config.php creado: de momento solo pueden entrar administradores.'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    casCheckCsrf();
    try {
        $messages = array_merge($messages, casCreateTables());
        $messages[] = casAddMenuItem();
        $messages[] = casCreateConfig();
        $done = true;
    } catch (\Throwable $e) {
        error_log('[companies_and_sections] install: ' . $e->getMessage());
        $messages[] = ['error', 'La instalación falló. Revisa el log de PHP del servidor.'];
    }
    if ($done) {
        $messages[] = @unlink(__FILE__)
            ? ['ok', 'install.php se ha borrado.']
            : ['warn', 'No se pudo borrar install.php: bórralo a mano del servidor.'];
    }
}

$page = \Admidio\UI\Presenter\PagePresenter::withHtmlIDAndHeadline('companies-and-sections-install', 'Instalar Empresas y secciones');
ob_start();
?>
<?php foreach ($messages as [$type, $text]): ?>
<div class="alert alert-<?php echo ['ok' => 'success', 'info' => 'info', 'warn' => 'warning', 'error' => 'danger'][$type]; ?>"><?php echo e($text); ?></div>
<?php endforeach; ?>

<?php if ($done): ?>
<a class="btn btn-success" href="<?php echo e(CAS_URL . '/organizations.php'); ?>"><i class="bi bi-building"></i> Ir a Empresas y secciones</a>
<?php else: ?>
<p>Este instalador:</p>
<ul>
    <li>Crea las tablas <code><?php echo e(CAS_TBL_ORGS); ?></code> y <code><?php echo e(CAS_TBL_MEMBERS); ?></code>.</li>
    <li>Añade la entrada de menú <strong>Empresas y secciones</strong> en Administración.</li>
    <li>Crea <code>config.php</code> si no existe.</li>
</ul>
<p class="text-muted">No modifica ningún fichero de Admidio ni crea campos de perfil. Se puede ejecutar varias veces.</p>
<form method="POST" action="install.php">
    <?php echo casCsrfField(); ?>
    <button type="submit" class="btn btn-primary"><i class="bi bi-play-fill"></i> Instalar</button>
</form>
<?php endif; ?>
<?php
$page->addHtml(ob_get_clean());
$page->show();
