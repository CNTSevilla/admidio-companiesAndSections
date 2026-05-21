<?php
/**
 * Companies and Sections - Create Company Form
 *
 * Form to create a new company in the CRM. Accessible only to users with the appropriate roles defined in `config.php`.
 *
 * CAMPOS DEL FORMULARIO:
 * - Common Data: internal name, comercial name, legal name, CIF/Identifier, form of legal entity, sector
 * - Contacto: email, teléfono, web
 * - Sección Sindical: name, email, spokesperson (dropdown of members)
 * - Dirección: street, ZIP Code, city, province
 *
 * POST ACTION FLOW:
 * - Validate required fields (at least company name)
 * - Insert the new company into crm_organizations
 * - Redirect to organizations.php after creation
 *
 * @package CompaniesAndSections
 */

require_once(__DIR__ . '/../../system/common.php');
require_once(__DIR__ . '/organization_functions.php');

// persmissions check
if (!isUserAuthorized($gDb)) {
    $gMessage->show('Acceso denegado. No tienes permiso para crear empresas.');
    exit;
}

$gNavigation->addStartUrl(CURRENT_URL, 'Nueva Organización', 'bi-building');

$headline = 'Nueva Organización';

$host = getenv('ADMIDIO_DB_HOST') ?: 'db:3306';
$crmDbname = getenv('ADMIDIO_DB_NAME') ?: 'admidio';
$user = getenv('ADMIDIO_DB_USER') ?: 'admidio';
$pass = getenv('ADMIDIO_DB_PASSWORD') ?: 'admidiopass';

try {
    $dsn = "mysql:host=$host;dbname=$crmDbname;charset=utf8mb4";
    $crmDb = new PDO($dsn, $user, $pass);
    $crmDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $gMessage->show('Error de conexión: ' . $e->getMessage());
}

function generateUuid(): string
{
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_org') {
    $errors = [];

    if (empty(trim($_POST['org_name'] ?? ''))) {
        $errors[] = 'El nombre de la empresa es obligatorio.';
    }

    if (empty($errors)) {
        $stmt = $crmDb->prepare("
            INSERT INTO crm_organizations (
                org_uuid, org_name, org_nombre_comercial, org_razon_social,
                org_cif, org_forma_juridica, org_sector,
                org_description, org_email, org_telefono,
                org_tiene_seccion, org_nombre_seccion, org_email_seccion,
                org_calles, org_cp, org_localidad, org_provincia,
                org_web, org_active
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
        ");
        $stmt->execute([
            generateUuid(),
            trim($_POST['org_name']),
            trim($_POST['org_nombre_comercial'] ?? ''),
            trim($_POST['org_razon_social'] ?? ''),
            trim($_POST['org_cif'] ?? ''),
            trim($_POST['org_forma_juridica'] ?? ''),
            trim($_POST['org_sector'] ?? ''),
            trim($_POST['org_description'] ?? ''),
            trim($_POST['org_email'] ?? ''),
            trim($_POST['org_telefono'] ?? ''),
            isset($_POST['org_tiene_seccion']) ? 1 : 0,
            trim($_POST['org_nombre_seccion'] ?? ''),
            trim($_POST['org_email_seccion'] ?? ''),
            trim($_POST['org_calles'] ?? ''),
            trim($_POST['org_cp'] ?? ''),
            trim($_POST['org_localidad'] ?? ''),
            trim($_POST['org_provincia'] ?? ''),
            trim($_POST['org_web'] ?? '')
        ]);

        updateOrgMembersDropdown($crmDb);

        header('Location: /adm_plugins/organization_members/organizations.php?created=1');
        exit;
    } else {
        $message = implode('<br>', $errors);
        $messageType = 'danger';
    }
}

// common legal forms in Spain for dropdown
$formasJuridicas = ['SL', 'SA', 'SLU', 'Cooperativa', 'Autónomo', 'Asociación', 'Fundación', 'Otra'];

$page = \Admidio\UI\Presenter\PagePresenter::withHtmlIDAndHeadline('crm-create-org', $headline);

$page->addHtml('
<style>
:root {
    --cnt-red: #DA291C;
    --cnt-black: #4a4a4a;
    --cnt-black-light: #2d2d2d;
    --cnt-green: #198754;
}
.btn-create {
    background: var(--cnt-red);
    border: none;
}
.btn-create:hover {
    background: #c41e3a;
    color: white;
}
.card-header-custom {
    background: var(--cnt-black-light);
    color: white;
    font-weight: 600;
    padding: 12px 20px;
}
.seccion-fields {
    transition: opacity 0.3s ease;
}
.seccion-fields.disabled {
    opacity: 0.5;
    pointer-events: none;
}
.form-section-title {
    color: var(--cnt-red);
    font-weight: 700;
    border-bottom: 2px solid var(--cnt-red);
    padding-bottom: 5px;
    margin-bottom: 15px;
}
.form-label-custom {
    font-weight: 600;
    color: var(--cnt-black);
}
.input-group-text-custom {
    background: var(--cnt-black-light);
    color: white;
    border: none;
}
</style>
<script>
document.addEventListener("DOMContentLoaded", function() {
    var checkbox = document.getElementById("org_tiene_seccion");
    var fields = document.getElementById("seccion_fields");
    function updateFields() {
        if (checkbox.checked) {
            fields.classList.remove("disabled");
            fields.style.display = "";
        } else {
            fields.classList.add("disabled");
            fields.style.display = "none";
        }
    }
    checkbox.addEventListener("change", updateFields);
    updateFields();
});
</script>');

ob_start();
?>
<div class="row mb-5">
    <div class="col-lg-10">
        <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?>"><?php echo $message; ?></div>
        <?php endif; ?>

        <form method="POST" class="row g-4">
            <input type="hidden" name="action" value="create_org">

            <!-- General Data -->
            <div class="col-12">
                <h5 class="form-section-title"><i class="bi bi-building"></i> Datos Generales</h5>
            </div>

            <div class="col-md-6">
                <label class="form-label form-label-custom">Nombre de la empresa *</label>
                <input type="text" name="org_name" class="form-control" required value="<?php echo htmlspecialchars($_POST['org_name'] ?? ''); ?>">
            </div>

            <div class="col-md-6">
                <label class="form-label form-label-custom">Nombre Comercial</label>
                <input type="text" name="org_nombre_comercial" class="form-control" value="<?php echo htmlspecialchars($_POST['org_nombre_comercial'] ?? ''); ?>">
            </div>

            <div class="col-md-6">
                <label class="form-label form-label-custom">Razón Social</label>
                <input type="text" name="org_razon_social" class="form-control" value="<?php echo htmlspecialchars($_POST['org_razon_social'] ?? ''); ?>">
            </div>

            <div class="col-md-3">
                <label class="form-label form-label-custom">CIF/NIF</label>
                <input type="text" name="org_cif" class="form-control" style="text-transform: uppercase;" value="<?php echo htmlspecialchars($_POST['org_cif'] ?? ''); ?>">
            </div>

            <div class="col-md-3">
                <label class="form-label form-label-custom">Forma Jurídica</label>
                <select name="org_forma_juridica" class="form-select">
                    <option value="">Seleccionar...</option>
                    <?php foreach ($formasJuridicas as $fj): ?>
                    <option value="<?php echo $fj; ?>" <?php echo ($_POST['org_forma_juridica'] ?? '') === $fj ? 'selected' : ''; ?>><?php echo $fj; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6">
                <label class="form-label form-label-custom">Sector</label>
                <input type="text" name="org_sector" class="form-control" value="<?php echo htmlspecialchars($_POST['org_sector'] ?? ''); ?>" placeholder="Ej: Tecnología, Construcción, Servicios...">
            </div>

            <div class="col-md-6">
                <label class="form-label form-label-custom">Correo electrónico</label>
                <input type="email" name="org_email" class="form-control" value="<?php echo htmlspecialchars($_POST['org_email'] ?? ''); ?>">
            </div>

            <div class="col-md-4">
                <label class="form-label form-label-custom">Teléfono</label>
                <input type="tel" name="org_telefono" class="form-control" value="<?php echo htmlspecialchars($_POST['org_telefono'] ?? ''); ?>">
            </div>

            <div class="col-md-4">
                <label class="form-label form-label-custom">Web</label>
                <input type="url" name="org_web" class="form-control" placeholder="https://..." value="<?php echo htmlspecialchars($_POST['org_web'] ?? ''); ?>">
            </div>

            <div class="col-12">
                <label class="form-label form-label-custom">Descripción</label>
                <textarea name="org_description" class="form-control" rows="2"><?php echo htmlspecialchars($_POST['org_description'] ?? ''); ?></textarea>
            </div>

            <!-- Union Section -->
            <div class="col-12 mt-5">
                <h5 class="form-section-title"><i class="bi bi-people"></i> Sección Sindical</h5>
            </div>

            <div class="col-md-4">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="org_tiene_seccion" name="org_tiene_seccion" value="1" <?php echo isset($_POST['org_tiene_seccion']) ? 'checked' : ''; ?>>
                    <label class="form-check-label fw-bold" for="org_tiene_seccion">¿Tiene sección sindical?</label>
                </div>
            </div>

            <div id="seccion_fields" class="col-12">
                <div class="row">
                    <div class="col-md-4">
                        <label class="form-label form-label-custom">Nombre sección sindical</label>
                        <input type="text" name="org_nombre_seccion" class="form-control" value="<?php echo htmlspecialchars($_POST['org_nombre_seccion'] ?? ''); ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label form-label-custom">Correo sección sindical</label>
                        <input type="email" name="org_email_seccion" class="form-control" value="<?php echo htmlspecialchars($_POST['org_email_seccion'] ?? ''); ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label form-label-custom">Portavoz sección sindical</label>
                        <select name="org_portavoz_seccion" class="form-select">
                            <option value="">Seleccionar miembro...</option>
                            <?php
                            $stmt = $crmDb->query("
                                SELECT u.usr_id, ud1.usd_value as first_name, ud2.usd_value as last_name
                                FROM adm_users u
                                LEFT JOIN adm_user_data ud1 ON ud1.usd_usr_id = u.usr_id AND ud1.usd_usf_id = 2
                                LEFT JOIN adm_user_data ud2 ON ud2.usd_usr_id = u.usr_id AND ud2.usd_usf_id = 1
                                WHERE u.usr_valid = 1
                                ORDER BY ud2.usd_value, ud1.usd_value
                            ");
                            foreach ($stmt->fetchAll() as $member):
                            ?>
                            <option value="<?php echo $member['usr_id']; ?>"><?php echo htmlspecialchars(($member['last_name'] ?? '') . ', ' . ($member['first_name'] ?? '')); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Address -->
            <div class="col-12 mt-5">
                <h5 class="form-section-title"><i class="bi bi-geo-alt"></i> Dirección</h5>
            </div>

            <div class="col-md-6">
                <label class="form-label form-label-custom">Calles</label>
                <input type="text" name="org_calles" class="form-control" value="<?php echo htmlspecialchars($_POST['org_calles'] ?? ''); ?>">
            </div>

            <div class="col-md-2">
                <label class="form-label form-label-custom">Código Postal</label>
                <input type="text" name="org_cp" class="form-control" maxlength="10" value="<?php echo htmlspecialchars($_POST['org_cp'] ?? ''); ?>">
            </div>

            <div class="col-md-4">
                <label class="form-label form-label-custom">Localidad</label>
                <input type="text" name="org_localidad" class="form-control" value="<?php echo htmlspecialchars($_POST['org_localidad'] ?? ''); ?>">
            </div>

            <div class="col-md-4">
                <label class="form-label form-label-custom">Provincia</label>
                <input type="text" name="org_provincia" class="form-control" value="<?php echo htmlspecialchars($_POST['org_provincia'] ?? ''); ?>">
            </div>

            <!-- Buttons -->
            <div class="col-12 mt-5">
                <button type="submit" class="btn btn-create text-white">
                    <i class="bi bi-building"></i> Crear Empresa
                </button>
                <a href="organizations.php" class="btn btn-secondary ms-2">
                    <i class="bi bi-x"></i> Cancelar
                </a>
            </div>
        </form>
    </div>
</div>

<?php
$page->addHtml(ob_get_clean());
$page->show();
