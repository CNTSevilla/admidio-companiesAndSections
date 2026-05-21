<?php
/**
 * Companies and Sections Plugin - Organization Profile
 *
 * Displays the complete profile of an organization including:
 * - Company data (name, tax ID, address, contact, etc.)
 * - Union section if it exists
 * - List of assigned members with their roles
 * 
 * POST ACTIONS:
 * - update_org: Updates the organization data
 *   - Also synchronizes the name in contact profiles
 *
 * FUNCTIONALITIES:
 * - Editing all fields of the organization
 * - Assignment of new members to the organization
 * - Visualization of member roles
 * - Calculation of SEPA totals
 * - Deletion of member assignments
 *
 * @package CompaniesAndSections
 */

require_once(__DIR__ . '/../../system/common.php');
require_once(__DIR__ . '/organization_functions.php');

// permissions check: only allowed roles or administrators can access
if (!isUserAuthorized($gDb)) {
    $gMessage->show('Acceso denegado. No tienes permiso para ver esta empresa.');
    exit;
}

$orgId = isset($_GET['org_id']) ? (int)$_GET['org_id'] : 0;

if ($orgId <= 0) {
    $gMessage->show('Empresa no especificada.');
}

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

// Obtain organization data
$stmt = $crmDb->prepare("SELECT * FROM crm_organizations WHERE org_id = ? AND org_active = 1");
$stmt->execute([$orgId]);
$org = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$org) {
    $gMessage->show('Empresa no encontrada.');
}

$gNavigation->addStartUrl(CURRENT_URL, htmlspecialchars($org['org_name']), 'bi-building');
$headline = htmlspecialchars($org['org_name']);

// Obtain members of the organization
$stmt = $crmDb->prepare("
    SELECT m.*,
           ud1.usd_value as first_name,
           ud2.usd_value as last_name,
           u.usr_id
    FROM crm_member_organizations m
    JOIN adm_users u ON m.mro_usr_id = u.usr_id
    LEFT JOIN adm_user_data ud1 ON ud1.usd_usr_id = u.usr_id AND ud1.usd_usf_id = 2
    LEFT JOIN adm_user_data ud2 ON ud2.usd_usr_id = u.usr_id AND ud2.usd_usf_id = 1
    WHERE m.mro_org_id = ? AND m.mro_active = 1
    ORDER BY ud2.usd_value, ud1.usd_value
");
$stmt->execute([$orgId]);
$members = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtain roles of the members
$stmt = $crmDb->query("
    SELECT mro.mro_usr_id, GROUP_CONCAT(DISTINCT r.rol_name SEPARATOR ',') as roles
    FROM crm_member_organizations mro
    JOIN adm_members m ON m.mem_usr_id = mro.mro_usr_id AND m.mem_leader = 0
    JOIN adm_roles r ON r.rol_id = m.mem_rol_id
    WHERE mro.mro_active = 1
    GROUP BY mro.mro_usr_id
");
$userRoles = [];
foreach ($stmt->fetchAll() as $row) {
    $userRoles[$row['mro_usr_id']] = $row['roles'];
}

// Calculate payment statistics
$stats = [
    'total' => count($members),
    'nomina' => 0,
    'total_nomina' => 0
];

$sepaEnabled = false;
$sepaPaymentField = null;
$sepaQuotaField = null;

$stmt = $crmDb->query("SELECT usf_id FROM adm_user_fields WHERE usf_name_intern = 'SEPA_PAYMENT_METHOD' LIMIT 1");
$sepaRow = $stmt->fetch();

if ($sepaRow) {
    $sepaEnabled = true;
    $sepaPaymentField = $sepaRow['usf_id'];

    $stmt = $crmDb->prepare("
        SELECT ufo_id, ufo_value, ufo_sequence
        FROM adm_user_field_select_options
        WHERE ufo_usf_id = ?
        ORDER BY ufo_sequence
    ");
    $stmt->execute([$sepaPaymentField]);
    $paymentOptions = $stmt->fetchAll();

    $nominaOptionId = null;
    foreach ($paymentOptions as $opt) {
        if (stripos($opt['ufo_value'], 'nómina') !== false || stripos($opt['ufo_value'], 'nomina') !== false) {
            $nominaOptionId = $opt['ufo_id'];
            break;
        }
    }

    if ($nominaOptionId) {
        $stmt = $crmDb->query("SELECT usf_id FROM adm_user_fields WHERE usf_name_intern = 'SEPA_QUOTA_TYPE' LIMIT 1");
        $sepaQuotaRow = $stmt->fetch();

        $sepaFees = ['normal' => 12, 'reduced' => 5];

        if ($sepaQuotaRow) {
            $sepaQuotaField = $sepaQuotaRow['usf_id'];

            $stmt = $crmDb->prepare("SELECT ufo_id, ufo_value, ufo_sequence FROM adm_user_field_select_options WHERE ufo_usf_id = ? ORDER BY ufo_sequence");
            $stmt->execute([$sepaQuotaField]);
            $quotaOptions = $stmt->fetchAll();

            foreach ($members as $m) {
                $stmt = $crmDb->prepare("SELECT usd_value FROM adm_user_data WHERE usd_usr_id = ? AND usd_usf_id = ?");
                $stmt->execute([$m['mro_usr_id'], $sepaPaymentField]);
                $paymentValue = $stmt->fetchColumn();

                if ($paymentValue == $nominaOptionId) {
                    $stats['nomina']++;
                    $cuota = getCuota($m['mro_usr_id'], $sepaQuotaField, $quotaOptions, $sepaFees);
                    $stats['total_nomina'] += $cuota;
                }
            }
        }
    }
}

function getCuota($usrId, $sepaQuotaField, $quotaOptions, $sepaFees) {
    global $crmDb;
    $stmt = $crmDb->prepare("SELECT usd_value FROM adm_user_data WHERE usd_usr_id = ? AND usd_usf_id = ?");
    $stmt->execute([$usrId, $sepaQuotaField]);
    $quotaValue = $stmt->fetchColumn();
    $quotaOptionIndex = (int)$quotaValue - 1;
    if (isset($quotaOptions[$quotaOptionIndex])) {
        $quotaText = strtolower($quotaOptions[$quotaOptionIndex]['ufo_value']);
        if (strpos($quotaText, 'normal') !== false) {
            return $sepaFees['normal'];
        } elseif (strpos($quotaText, 'reducida') !== false) {
            return $sepaFees['reduced'];
        }
    }
    return $sepaFees['normal'];
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'update_org') {
            try {
                $oldOrgName = $org['org_name'];
                $newOrgName = $_POST['org_name'];
                
                $orgPortavoz = !empty($_POST['org_portavoz_seccion']) ? (int)$_POST['org_portavoz_seccion'] : null;
                $stmt = $crmDb->prepare("
                    UPDATE crm_organizations SET
                        org_name = ?, org_nombre_comercial = ?, org_razon_social = ?,
                        org_cif = ?, org_forma_juridica = ?, org_sector = ?,
                        org_description = ?, org_email = ?, org_telefono = ?,
                        org_tiene_seccion = ?, org_nombre_seccion = ?, org_email_seccion = ?,
                        org_portavoz_seccion = ?, org_calles = ?, org_cp = ?, org_localidad = ?, org_provincia = ?,
                        org_web = ?
                    WHERE org_id = ?
                ");
                $stmt->execute([
                    $_POST['org_name'],
                    $_POST['org_nombre_comercial'] ?? '',
                    $_POST['org_razon_social'] ?? '',
                    $_POST['org_cif'] ?? '',
                    $_POST['org_forma_juridica'] ?? '',
                    $_POST['org_sector'] ?? '',
                    $_POST['org_description'] ?? '',
                    $_POST['org_email'] ?? '',
                    $_POST['org_telefono'] ?? '',
                    isset($_POST['org_tiene_seccion']) ? 1 : 0,
                    $_POST['org_nombre_seccion'] ?? '',
                    $_POST['org_email_seccion'] ?? '',
                    $orgPortavoz,
                    $_POST['org_calles'] ?? '',
                    $_POST['org_cp'] ?? '',
                    $_POST['org_localidad'] ?? '',
                    $_POST['org_provincia'] ?? '',
                    $_POST['org_web'] ?? '',
                    $orgId
                ]);
                updateOrgMembersDropdown($crmDb, $oldOrgName, $newOrgName);
                syncOrgNameInContacts($crmDb, $oldOrgName, $newOrgName);
                header("Location: " . $_SERVER['REQUEST_URI']);
                exit;
            } catch (Exception $e) {
                error_log('update_org Error: ' . $e->getMessage());
                $gMessage->show('Error al actualizar: ' . $e->getMessage());
            }
        }

        if ($_POST['action'] === 'add_member' && isset($_POST['usr_id']) && isset($_POST['org_id'])) {
            try {
                $stmt = $crmDb->prepare("SELECT mro_id FROM crm_member_organizations WHERE mro_usr_id = ? AND mro_org_id = ? AND mro_active = 1");
                $stmt->execute([$_POST['usr_id'], $_POST['org_id']]);
                if (!$stmt->fetch()) {
                    $stmt = $crmDb->prepare("INSERT INTO crm_member_organizations (mro_uuid, mro_usr_id, mro_org_id, mro_role, mro_active, mro_usr_id_create, mro_timestamp_create) VALUES (?, ?, ?, ?, 1, ?, NOW())");
                    $stmt->execute([
                        sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)),
                        $_POST['usr_id'],
                        $_POST['org_id'],
                        $_POST['mro_role'] ?? '',
                        $gCurrentUserId ?? 1
                    ]);
                }
            } catch (Exception $e) {
                error_log('Error add_member: ' . $e->getMessage());
            }
            header("Location: " . $_SERVER['REQUEST_URI']);
            exit;
        }

        if ($_POST['action'] === 'remove_member' && isset($_POST['mro_id'])) {
            $stmt = $crmDb->prepare("DELETE FROM crm_member_organizations WHERE mro_id = ?");
            $stmt->execute([$_POST['mro_id']]);
            header("Location: " . $_SERVER['REQUEST_URI']);
            exit;
        }
    }
}

// Obtain available members
$stmt = $crmDb->query("
    SELECT u.usr_id, ud1.usd_value as first_name, ud2.usd_value as last_name
    FROM adm_users u
    LEFT JOIN adm_user_data ud1 ON ud1.usd_usr_id = u.usr_id AND ud1.usd_usf_id = 2
    LEFT JOIN adm_user_data ud2 ON ud2.usd_usr_id = u.usr_id AND ud2.usd_usf_id = 1
    WHERE u.usr_valid = 1
    ORDER BY ud2.usd_value, ud1.usd_value
");
$availableMembers = $stmt->fetchAll();

// Obtain the selected user as the section representative
$selectedPortavozId = $org['org_portavoz_seccion'] ?? '';
$selectedPortavozName = '';
if ($selectedPortavozId) {
    $stmt = $crmDb->prepare("
        SELECT u.usr_id, ud1.usd_value as first_name, ud2.usd_value as last_name
        FROM adm_users u
        LEFT JOIN adm_user_data ud1 ON ud1.usd_usr_id = u.usr_id AND ud1.usd_usf_id = 2
        LEFT JOIN adm_user_data ud2 ON ud2.usd_usr_id = u.usr_id AND ud2.usd_usf_id = 1
        WHERE u.usr_id = ?
    ");
    $stmt->execute([$selectedPortavozId]);
    $portavoz = $stmt->fetch();
    if ($portavoz) {
        $selectedPortavozName = ($portavoz['last_name'] ?? '') . ', ' . ($portavoz['first_name'] ?? '');
    }
}

$page = \Admidio\UI\Presenter\PagePresenter::withHtmlIDAndHeadline('crm-org-profile', $headline);

$page->addHtml('
<style>
:root {
    --cnt-red: #DA291C;
    --cnt-black: #4a4a4a;
    --cnt-black-light: #2d2d2d;
    --cnt-green: #198754;
    --cnt-blue: #0d6efd;
}
.crm-link { color: var(--cnt-red); text-decoration: none; font-weight: 600; }
.crm-link:hover { text-decoration: underline; }
.stat-card {
    border-radius: 10px;
    padding: 15px;
    text-align: center;
    color: white;
}
.stat-card-red { background: linear-gradient(135deg, var(--cnt-red) 0%, #c41e3a 100%); }
.stat-card-blue { background: linear-gradient(135deg, var(--cnt-blue) 0%, #0a58ca 100%); }
.stat-card-green { background: linear-gradient(135deg, var(--cnt-green) 0%, #157347 100%); }
.stat-number { font-size: 2rem; font-weight: 700; }
.stat-label { font-size: 0.85rem; opacity: 0.9; }
.stat-label-lg { font-size: 1rem; opacity: 0.9; font-weight: 600; }
.btn-save {
    background: var(--cnt-red);
    border-color: var(--cnt-red);
    color: white;
}
.btn-save:hover { background: #c41e3a; color: white; }
.form-section-title {
    color: var(--cnt-red);
    font-weight: 700;
    border-bottom: 2px solid var(--cnt-red);
    padding-bottom: 5px;
    margin-bottom: 15px;
}
.crm-member-card {
    border-left: 5px solid var(--cnt-red);
    width: 100%;
}
.members-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
    align-items: start;
}
.members-grid .crm-member-card {
    margin-bottom: 0;
}
.crm-member-card .card-header {
    background: var(--cnt-black-light);
    color: white;
}
.badge-large {
    padding: 8px 16px;
    font-size: 1rem;
    margin-left: 0 !important;
}
.btn-remove-member {
    background: var(--cnt-red);
    border: none;
    color: white;
    border-radius: 50%;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0;
    text-align: center;
    line-height: 1;
}
.btn-remove-member i {
    line-height: 1;
    margin-right: 0 !important;
}
.btn-remove-member:hover {
    background: #c41e3a;
    color: white;
    transform: scale(1.1);
}
.card-header-custom {
    background: var(--cnt-black-light);
    color: white;
    font-weight: 600;
}
#seccion_fields {
    transition: opacity 0.3s ease;
}
#seccion_fields.hidden {
    display: none;
}
.crm-wrapper {
    margin-right: calc(-50vw + 50%);
}
.crm-grid {
    display: grid;
    grid-template-columns: repeat(12, 1fr);
    grid-template-areas:
        "datos dir seccion"
        "datos miembros miembros";
    gap: 1rem;
}
.crm-grid .card-datos { grid-area: datos; grid-column: span 4; }
.crm-grid .card-dir { grid-area: dir; grid-column: span 4; }
.crm-grid .card-seccion { grid-area: seccion; grid-column: span 4; }
.crm-grid .card-miembros { grid-area: miembros; grid-column: span 8; }
@media (max-width: 1449px) {
    .crm-wrapper {
        margin-right: 0;
        width: 100%;
    }
    .crm-grid {
        grid-template-columns: 1fr;
        grid-template-areas:
            "datos"
            "dir"
            "seccion"
            "miembros";
    }
    .crm-grid .card-datos,
    .crm-grid .card-dir,
    .crm-grid .card-seccion,
    .crm-grid .card-miembros { grid-column: span 1; }
}

/* Responsive para el formulario de miembros */
@media (max-width: 1449px) {
    .card-miembros .d-flex.gap-2 {
        flex-direction: column;
    }
    .card-miembros .d-flex.gap-2 > div {
        width: 100%;
    }
    .card-miembros .d-flex.gap-2 > div input[type="text"] {
        width: 100%;
    }
    .card-miembros .d-flex.gap-2 .btn {
        width: 100%;
    }
}

/* Responsive para el grid de miembros */
@media (max-width: 575px) {
    .members-grid {
        grid-template-columns: 1fr;
    }
}
</style>
<script>
document.addEventListener("DOMContentLoaded", function() {
    var checkbox = document.getElementById("org_tiene_seccion");
    var fields = document.getElementById("seccion_fields");
    function updateFields() {
        if (checkbox.checked) {
            fields.classList.remove("hidden");
        } else {
            fields.classList.add("hidden");
        }
    }
    checkbox.addEventListener("change", updateFields);
    updateFields();
});
</script>');

ob_start();
?>

<div class="row mb-3">
    <div class="col-12">
        <a href="organizations.php" class="btn btn-secondary">
            <i class="bi bi-arrow-left"></i> Volver a Empresas
        </a>
    </div>
</div>

<?php if ($sepaEnabled): ?>
<div class="row mb-4">
    <div class="col-md-4">
        <div class="stat-card stat-card-blue">
            <div class="stat-number"><?php echo $stats['total']; ?></div>
            <div class="stat-label stat-label-lg">Total Miembros</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card stat-card-red">
            <div class="stat-number"><?php echo $stats['nomina']; ?></div>
            <div class="stat-label stat-label-lg">Pago por Nómina</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card stat-card-green">
            <div class="stat-number"><?php echo number_format($stats['total_nomina'], 2); ?> €</div>
            <div class="stat-label stat-label-lg">Total a percibir</div>
        </div>
    </div>
</div>
<?php endif; ?>

<form method="POST" id="updateOrgForm">
    <input type="hidden" name="action" value="update_org">

<div class="crm-wrapper">
<div class="crm-grid">
    <div class="card card-datos">
        <div class="card-header card-header-custom">
            <i class="bi bi-building"></i> Datos de la Empresa
        </div>
        <div class="card-body">
            <div class="mb-2">
                <label class="form-label fw-bold small">Nombre <span class="text-danger">*</span></label>
                <input type="text" name="org_name" class="form-control" value="<?php echo htmlspecialchars($org['org_name']); ?>" required>
            </div>

            <div class="mb-2">
                <label class="form-label fw-bold small">Nombre Comercial</label>
                <input type="text" name="org_nombre_comercial" class="form-control" value="<?php echo htmlspecialchars($org['org_nombre_comercial'] ?? ''); ?>">
            </div>

            <div class="mb-2">
                <label class="form-label fw-bold small">Razón Social</label>
                <input type="text" name="org_razon_social" class="form-control" value="<?php echo htmlspecialchars($org['org_razon_social'] ?? ''); ?>">
            </div>

            <div class="row mb-2">
                <div class="col-6">
                    <label class="form-label fw-bold small">CIF</label>
                    <input type="text" name="org_cif" class="form-control" style="text-transform: uppercase;" value="<?php echo htmlspecialchars($org['org_cif'] ?? ''); ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-bold small">Forma Jurídica</label>
                    <input type="text" name="org_forma_juridica" class="form-control" value="<?php echo htmlspecialchars($org['org_forma_juridica'] ?? ''); ?>">
                </div>
            </div>

            <div class="mb-2">
                <label class="form-label fw-bold small">Sector</label>
                <input type="text" name="org_sector" class="form-control" value="<?php echo htmlspecialchars($org['org_sector'] ?? ''); ?>">
            </div>

            <div class="mb-2">
                <label class="form-label fw-bold small">Correo</label>
                <input type="email" name="org_email" class="form-control" value="<?php echo htmlspecialchars($org['org_email'] ?? ''); ?>">
            </div>

            <div class="mb-2">
                <label class="form-label fw-bold small">Teléfono</label>
                <input type="tel" name="org_telefono" class="form-control" value="<?php echo htmlspecialchars($org['org_telefono'] ?? ''); ?>">
            </div>

            <div class="mb-2">
                <label class="form-label fw-bold small">Web</label>
                <input type="url" name="org_web" class="form-control" value="<?php echo htmlspecialchars($org['org_web'] ?? ''); ?>">
            </div>

            <div class="mb-2">
                <label class="form-label fw-bold small">Descripción</label>
                <textarea name="org_description" class="form-control" rows="2"><?php echo htmlspecialchars($org['org_description'] ?? ''); ?></textarea>
            </div>

            <button type="submit" class="btn btn-save text-white mt-3">
                <i class="bi bi-save"></i> Guardar cambios
            </button>
        </div>
    </div>

    <div class="card card-dir">
        <div class="card-header card-header-custom">
            <i class="bi bi-geo-alt"></i> Dirección
        </div>
        <div class="card-body">
            <div class="mb-2">
                <label class="form-label fw-bold small">Calles</label>
                <input type="text" name="org_calles" class="form-control" value="<?php echo htmlspecialchars($org['org_calles'] ?? ''); ?>">
            </div>

            <div class="row mb-2">
                <div class="col-6">
                    <label class="form-label fw-bold small">Código Postal</label>
                    <input type="text" name="org_cp" class="form-control" maxlength="10" value="<?php echo htmlspecialchars($org['org_cp'] ?? ''); ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-bold small">Localidad</label>
                    <input type="text" name="org_localidad" class="form-control" value="<?php echo htmlspecialchars($org['org_localidad'] ?? ''); ?>">
                </div>
            </div>

            <div class="mb-2">
                <label class="form-label fw-bold small">Provincia</label>
                <input type="text" name="org_provincia" class="form-control" value="<?php echo htmlspecialchars($org['org_provincia'] ?? ''); ?>">
            </div>
        </div>
    </div>

    <div class="card card-seccion">
        <div class="card-header card-header-custom">
            <i class="bi bi-people"></i> Sección Sindical
        </div>
        <div class="card-body">
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="org_tiene_seccion" name="org_tiene_seccion" value="1"
                       <?php echo $org['org_tiene_seccion'] ? 'checked' : ''; ?>>
                <label class="form-check-label fw-bold" for="org_tiene_seccion">¿Tiene sección sindical?</label>
            </div>

            <div id="seccion_fields">
                <div class="mb-2">
                    <label class="form-label fw-bold small">Nombre sección sindical</label>
                    <input type="text" name="org_nombre_seccion" class="form-control" value="<?php echo htmlspecialchars($org['org_nombre_seccion'] ?? ''); ?>">
                </div>

                <div class="mb-2">
                    <label class="form-label fw-bold small">Correo sección sindical</label>
                    <input type="email" name="org_email_seccion" class="form-control" value="<?php echo htmlspecialchars($org['org_email_seccion'] ?? ''); ?>">
                </div>

                <div class="mb-2">
                    <label class="form-label fw-bold small">Portavoz sección sindical</label>
                    <select name="org_portavoz_seccion" class="form-select">
                        <option value="">Seleccionar miembro...</option>
                        <?php foreach ($availableMembers as $member): ?>
                        <option value="<?php echo $member['usr_id']; ?>" <?php echo ($selectedPortavozId == $member['usr_id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(($member['last_name'] ?? '') . ', ' . ($member['first_name'] ?? '')); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </div>
</form>

    <div class="card card-miembros">
        <div class="card-header card-header-custom d-flex justify-content-between align-items-center">
            <span><i class="bi bi-people"></i> Miembros (<?php echo $stats['total']; ?>)</span>
        </div>
        <div class="card-body">
            <form method="POST" class="mb-4">
                <input type="hidden" name="action" value="add_member">
                <input type="hidden" name="org_id" value="<?php echo $orgId; ?>">
                <div class="d-flex gap-2 align-items-end">
                    <div class="flex-grow-1">
                        <label class="form-label small fw-bold">Miembro</label>
                        <select name="usr_id" class="form-select" required>
                            <option value="">Seleccionar...</option>
                            <?php foreach ($availableMembers as $member): ?>
                            <option value="<?php echo $member['usr_id']; ?>">
                                <?php echo htmlspecialchars(($member['last_name'] ?? '') . ', ' . ($member['first_name'] ?? '')); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label small fw-bold">Cargo</label>
                        <input type="text" name="mro_role" class="form-control" placeholder="Cargo" style="width: 150px;">
                    </div>
                    <div>
                        <button type="submit" class="btn btn-success">Asignar</button>
                    </div>
                </div>
            </form>

            <?php if (empty($members)): ?>
            <p class="text-muted mt-4 ms-3">No hay miembros asignados a esta empresa.</p>
            <?php else: ?>
            <div class="members-grid mt-4">
                <?php
                $memberPaymentMethods = [];
                if ($sepaEnabled && $sepaPaymentField) {
                    foreach ($members as $m) {
                        $stmt = $crmDb->prepare("SELECT usd_value FROM adm_user_data WHERE usd_usr_id = ? AND usd_usf_id = ?");
                        $stmt->execute([$m['mro_usr_id'], $sepaPaymentField]);
                        $pmValue = $stmt->fetchColumn();
                        $pmLabel = '';
                        foreach ($paymentOptions as $opt) {
                            if ($opt['ufo_id'] == $pmValue) {
                                $pmLabel = $opt['ufo_value'];
                                break;
                            }
                        }
                        $memberPaymentMethods[$m['mro_usr_id']] = $pmLabel;
                    }
                }
                foreach ($members as $m):
                    $pmLabel = $memberPaymentMethods[$m['mro_usr_id']] ?? '';
                    $userRolList = isset($userRoles[$m['usr_id']]) ? explode(',', $userRoles[$m['usr_id']]) : [];
                ?>
                <div class="crm-member-card card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span class="fw-bold"><?php echo htmlspecialchars(($m['last_name'] ?? '') . ', ' . ($m['first_name'] ?? '')); ?></span>
                        <form method="POST" class="mb-0">
                            <input type="hidden" name="action" value="remove_member">
                            <input type="hidden" name="mro_id" value="<?php echo $m['mro_id']; ?>">
                            <button type="submit" class="btn btn-remove-member" onclick="return confirm('¿Eliminar miembro?');" title="Eliminar">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </div>
                    <div class="card-body">
                        <div class="d-flex flex-column gap-2">
                            <?php if (!empty($m['mro_role'])): ?>
                            <div>
                                <small class="text-muted fw-bold text-uppercase">Cargo</small>
                                <div class="mt-1"><span class="badge bg-danger badge-large"><?php echo htmlspecialchars($m['mro_role']); ?></span></div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($userRolList[0])): ?>
                            <div>
                                <small class="text-muted fw-bold text-uppercase">Rol</small>
                                <div class="mt-1">
                                    <?php foreach ($userRolList as $r): ?>
                                    <span class="badge bg-dark badge-large"><?php echo htmlspecialchars(trim($r)); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($pmLabel)): ?>
                            <div>
                                <small class="text-muted fw-bold text-uppercase">Forma pago</small>
                                <div class="mt-1"><span class="badge bg-success badge-large"><?php echo htmlspecialchars($pmLabel); ?></span></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</div>

<?php
$page->addHtml(ob_get_clean());
$page->show();
