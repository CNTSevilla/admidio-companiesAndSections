<?php
/**
 * Companies and Sections Plugin - Main Page
 *
 * Main page of the plugin that displays the list of organizations/companies and their members:
 * - List of all organizations/companies
 * - Filters by sector, union section, representative
 * - Pagination
 * - SEPA Statistics (if available)
 * - Links to create, view, and delete organizations
 *
 * POST ACTIONS:
 * - delete_org: Elimina una organización (solo si no tiene miembros)
 *
 * FUNCTIONALITIES:
 * - Configurable pagination
 * - MMultiple filters
 * - Integrated with Admidio users and roles
 * - Links to representative profiles using UUID
 *
 * @package CompaniesAndSections
 * @version 1.0.0
 */

require_once(__DIR__ . '/../../system/common.php');
require_once(__DIR__ . '/organization_functions.php');

// Check permissions: only allowed roles or administrators can access
if (!isUserAuthorized($gDb)) {
    $gMessage->show('Acceso denegado. No tienes permiso para ver este plugin.');
    exit;
}

$gNavigation->addStartUrl(CURRENT_URL, 'Organizations & Members', 'bi-building');

$headline = 'Organizations & Members';

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

$message = '';
$messageType = '';

if (isset($_GET['created'])) {
    $message = 'Empresa creada correctamente';
    $messageType = 'success';
}

$tablesExist = true;
try {
    $crmDb->query("SELECT 1 FROM crm_organizations LIMIT 1");
} catch (PDOException $e) {
    $tablesExist = false;
}

if ($tablesExist && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'delete_org' && isset($_POST['org_id'])) {
        $stmt = $crmDb->prepare("SELECT COUNT(*) as cnt FROM crm_member_organizations WHERE mro_org_id = ? AND mro_active = 1");
        $stmt->execute([$_POST['org_id']]);
        $count = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];

        if ($count > 0) {
            $message = 'No se puede eliminar: tiene miembros asignados';
            $messageType = 'danger';
        } else {
            $stmt = $crmDb->prepare("DELETE FROM crm_organizations WHERE org_id = ?");
            $stmt->execute([$_POST['org_id']]);
            updateOrgMembersDropdown($crmDb);
            $message = 'Empresa eliminada';
            $messageType = 'success';
        }
    }
}

$orgs = [];
$memberCounts = [];
$totalNominaGlobal = 0;

// Obtain union sections for filtering
$seccionesList = [];
$stmt = $crmDb->query("SELECT DISTINCT org_email_seccion FROM crm_organizations WHERE org_email_seccion IS NOT NULL AND org_email_seccion != ''");
foreach ($stmt->fetchAll() as $row) {
    if (!empty($row['org_email_seccion'])) {
        $seccionesList[] = $row['org_email_seccion'];
    }
}
sort($seccionesList);

// Obtain names of sections for filtering
$nombresSeccionList = [];
$stmt = $crmDb->query("SELECT DISTINCT org_nombre_seccion FROM crm_organizations WHERE org_nombre_seccion IS NOT NULL AND org_nombre_seccion != ''");
foreach ($stmt->fetchAll() as $row) {
    if (!empty($row['org_nombre_seccion'])) {
        $nombresSeccionList[] = $row['org_nombre_seccion'];
    }
}
sort($nombresSeccionList);

// Obtain sectors for filtering
$sectoresList = [];
$stmt = $crmDb->query("SELECT DISTINCT org_sector FROM crm_organizations WHERE org_sector IS NOT NULL AND org_sector != ''");
foreach ($stmt->fetchAll() as $row) {
    if (!empty($row['org_sector'])) {
        $sectoresList[] = $row['org_sector'];
    }
}
sort($sectoresList);

// Obtain users for filtering by representative
$portavozList = [];
$stmt = $crmDb->query("
    SELECT u.usr_id, ud1.usd_value as first_name, ud2.usd_value as last_name
    FROM adm_users u
    LEFT JOIN adm_user_data ud1 ON ud1.usd_usr_id = u.usr_id AND ud1.usd_usf_id = 2
    LEFT JOIN adm_user_data ud2 ON ud2.usd_usr_id = u.usr_id AND ud2.usd_usf_id = 1
    WHERE u.usr_valid = 1
    ORDER BY ud2.usd_value, ud1.usd_value
");
foreach ($stmt->fetchAll() as $row) {
    $portavozList[] = [
        'usr_id' => $row['usr_id'],
        'nombre' => trim(($row['last_name'] ?? '') . ', ' . ($row['first_name'] ?? ''))
    ];
}

if ($tablesExist) {
    $searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';
    $filterSeccion = isset($_GET['filter_seccion']) ? $_GET['filter_seccion'] : '';
    $filterNombreSeccion = isset($_GET['filter_nombre_seccion']) ? $_GET['filter_nombre_seccion'] : '';
    $filterSector = isset($_GET['filter_sector']) ? $_GET['filter_sector'] : '';
    $filterPortavoz = isset($_GET['filter_portavoz']) ? (int)$_GET['filter_portavoz'] : 0;

    // Paginación
    $perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 25;
    if ($perPage <= 0) $perPage = 25;
    $currentPage = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $offset = ($currentPage - 1) * $perPage;

    $conditions = ['org_active = 1'];
    $params = [];

    if (!empty($searchTerm)) {
        $conditions[] = "(org_name LIKE ? OR org_nombre_comercial LIKE ? OR org_razon_social LIKE ? OR org_cif LIKE ?)";
        $params[] = '%' . $searchTerm . '%';
        $params[] = '%' . $searchTerm . '%';
        $params[] = '%' . $searchTerm . '%';
        $params[] = '%' . $searchTerm . '%';
    }

    if (!empty($filterSeccion)) {
        $conditions[] = "org_email_seccion = ?";
        $params[] = $filterSeccion;
    }

    if (!empty($filterNombreSeccion)) {
        $conditions[] = "org_nombre_seccion = ?";
        $params[] = $filterNombreSeccion;
    }

    if (!empty($filterSector)) {
        $conditions[] = "org_sector = ?";
        $params[] = $filterSector;
    }

    if ($filterPortavoz > 0) {
        $conditions[] = "org_portavoz_seccion = ?";
        $params[] = $filterPortavoz;
    }

    $where = implode(' AND ', $conditions);

    // Contar total para paginación
    $countStmt = $crmDb->prepare("SELECT COUNT(*) FROM crm_organizations WHERE $where");
    $countStmt->execute($params);
    $totalOrgs = $countStmt->fetchColumn();
    $totalPages = $perPage > 0 ? ceil($totalOrgs / $perPage) : 1;
    if ($currentPage > $totalPages) $currentPage = $totalPages;

    // Query with pagination and JOIN to obtain data of the representative
    $sql = "SELECT o.*,
            CONCAT(ud2.usd_value, ', ', ud1.usd_value) as pembuat_nombre,
            u.usr_id as pembuat_usr_id,
            u.usr_uuid as pembuat_usr_uuid
            FROM crm_organizations o
            LEFT JOIN adm_users u ON u.usr_id = o.org_portavoz_seccion
            LEFT JOIN adm_user_data ud1 ON ud1.usd_usr_id = u.usr_id AND ud1.usd_usf_id = 2
            LEFT JOIN adm_user_data ud2 ON ud2.usd_usr_id = u.usr_id AND ud2.usd_usf_id = 1
            WHERE $where ORDER BY o.org_name";
    if ($perPage > 0) {
        $sql .= " LIMIT $perPage OFFSET $offset";
    }
    $stmt = $crmDb->prepare($sql);
    $stmt->execute($params);
    $orgs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Count members for each organization
    $stmt = $crmDb->query("SELECT mro_org_id, COUNT(*) as cnt FROM crm_member_organizations WHERE mro_active = 1 GROUP BY mro_org_id");
    foreach ($stmt->fetchAll() as $row) {
        $memberCounts[$row['mro_org_id']] = $row['cnt'];
    }

    // Calculate SEPA totals
    $sepaEnabled = false;
    $stmt = $crmDb->query("SELECT usf_id FROM adm_user_fields WHERE usf_name_intern = 'SEPA_PAYMENT_METHOD' LIMIT 1");
    $sepaRow = $stmt->fetch();

    if ($sepaRow) {
        $sepaEnabled = true;
        $sepaPaymentField = $sepaRow['usf_id'];

        $stmt = $crmDb->prepare("
            SELECT ufo_id FROM adm_user_field_select_options
            WHERE ufo_usf_id = ? AND (ufo_value LIKE '%nómina%' OR ufo_value LIKE '%nomina%')
            LIMIT 1
        ");
        $stmt->execute([$sepaPaymentField]);
        $nominaOptionId = $stmt->fetchColumn();

        if ($nominaOptionId) {
            $stmt = $crmDb->query("SELECT usf_id FROM adm_user_fields WHERE usf_name_intern = 'SEPA_QUOTA_TYPE' LIMIT 1");
            $sepaQuotaRow = $stmt->fetch();

            $sepaFees = ['normal' => 12, 'reduced' => 5];
            $configFile = __DIR__ . '/../sepa_export/config.php';
            if (file_exists($configFile)) {
                include($configFile);
            }

            if ($sepaQuotaRow) {
                $sepaQuotaField = $sepaQuotaRow['usf_id'];
                $stmt = $crmDb->prepare("SELECT ufo_id, ufo_value FROM adm_user_field_select_options WHERE ufo_usf_id = ? ORDER BY ufo_sequence");
                $stmt->execute([$sepaQuotaField]);
                $quotaOptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($orgs as $index => $org) {
                    $orgs[$index]['total_nomina'] = 0;
                    $stmt = $crmDb->prepare("
                        SELECT mro.mro_usr_id FROM crm_member_organizations mro
                        JOIN adm_user_data udp ON udp.usd_usr_id = mro.mro_usr_id AND udp.usd_usf_id = ?
                        WHERE mro.mro_org_id = ? AND mro.mro_active = 1 AND udp.usd_value = ?
                    ");
                    $stmt->execute([$sepaPaymentField, $org['org_id'], $nominaOptionId]);
                    $nominaMembers = $stmt->fetchAll();

                    foreach ($nominaMembers as $nm) {
                        $stmt = $crmDb->prepare("SELECT usd_value FROM adm_user_data WHERE usd_usr_id = ? AND usd_usf_id = ?");
                        $stmt->execute([$nm['mro_usr_id'], $sepaQuotaField]);
                        $quotaValue = $stmt->fetchColumn();
                        $quotaOptionIndex = (int)$quotaValue - 1;
                        if (!empty($quotaOptions) && isset($quotaOptions[$quotaOptionIndex])) {
                            $quotaText = strtolower($quotaOptions[$quotaOptionIndex]['ufo_value']);
                            if (strpos($quotaText, 'normal') !== false) {
                                $orgs[$index]['total_nomina'] += $sepaFees['normal'];
                            } elseif (strpos($quotaText, 'reducida') !== false) {
                                $orgs[$index]['total_nomina'] += $sepaFees['reduced'];
                            }
                        } else {
                            $orgs[$index]['total_nomina'] += $sepaFees['normal'];
                        }
                    }
                    $totalNominaGlobal += $orgs[$index]['total_nomina'];
                }
            } else {
                foreach ($orgs as $index => $org) {
                    $orgs[$index]['total_nomina'] = 0;
                    $stmt = $crmDb->prepare("
                        SELECT mro.mro_usr_id FROM crm_member_organizations mro
                        JOIN adm_user_data udp ON udp.usd_usr_id = mro.mro_usr_id AND udp.usd_usf_id = ?
                        WHERE mro.mro_org_id = ? AND mro.mro_active = 1 AND udp.usd_value = ?
                    ");
                    $stmt->execute([$sepaPaymentField, $org['org_id'], $nominaOptionId]);
                    $nominaMembers = $stmt->fetchAll();
                    $orgs[$index]['total_nomina'] = count($nominaMembers) * $sepaFees['normal'];
                    $totalNominaGlobal += $orgs[$index]['total_nomina'];
                }
            }
        }
    }
}

$page = \Admidio\UI\Presenter\PagePresenter::withHtmlIDAndHeadline('organization-members', $headline);
$page->setContentFullWidth();

$page->addHtml('
<style>
:root {
    --cnt-red: #DA291C;
    --cnt-black: #4a4a4a;
    --cnt-black-light: #2d2d2d;
    --cnt-green: #198754;
    --cnt-blue: #0d6efd;
}
.btn-create {
    background: linear-gradient(135deg, var(--cnt-red) 0%, #c41e3a 100%);
    color: white;
    border: none;
    padding: 8px 16px;
    font-weight: 600;
    border-radius: 6px;
    font-size: 0.85rem;
    box-shadow: 0 2px 8px rgba(218, 41, 28, 0.3);
    transition: all 0.3s ease;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.btn-create:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(218, 41, 28, 0.4);
    color: white;
}
.crm-header {
    background: linear-gradient(135deg, var(--cnt-red) 0%, #c41e3a 100%);
    color: white;
    padding: 15px 20px;
    border-radius: 10px;
    margin-bottom: 20px;
}
.badge-custom {
    padding: 8px 14px;
    font-size: 0.9rem;
    font-weight: 600;
    line-height: 1.4;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.badge-seccion { background: var(--cnt-green); color: white; }
.badge-miem { background: var(--cnt-black-light); color: white; }
.badge-sin { background: #6c757d; color: white; }
.btn-ver {
    background: var(--cnt-blue);
    border-color: var(--cnt-blue);
    color: white;
    padding: 6px 14px;
    font-size: 0.85rem;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.btn-ver:hover { background: #0a58ca; color: white; }
.btn-borrar {
    background: var(--cnt-red);
    border-color: var(--cnt-red);
    color: white;
    padding: 6px 14px;
    font-size: 0.85rem;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.btn-borrar:hover { background: #c41e3a; color: white; }
.crm-link { color: var(--cnt-red); text-decoration: none; font-weight: 600; }
.crm-link:hover { text-decoration: underline; }
.filter-card {
    border: 2px solid #e9ecef;
    border-radius: 10px;
    padding: 12px 15px;
    margin-bottom: 15px;
    background: #fafafa;
}
.table { font-size: 0.85rem; }
.table th { background: #f8f9fa; font-weight: 600; color: var(--cnt-black); font-size: 0.8rem; }
.table-hover tbody tr:hover { background-color: rgba(218, 41, 28, 0.05); }
.table td { vertical-align: middle; }
.actions-cell {
    display: flex;
    gap: 6px;
    justify-content: center;
    flex-wrap: wrap;
}
.pagination-custom {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px;
    border-top: 1px solid #dee2e6;
}
.pagination-info {
    color: #6c757d;
    font-size: 0.85rem;
}
.pagination-controls {
    display: flex;
    align-items: center;
    gap: 10px;
}
.pagination-controls select {
    width: auto;
}
.page-link {
    color: var(--cnt-red);
}
.page-link:hover {
    color: #c41e3a;
}
.nomina-summary {
    margin-bottom: 1.5rem;
}
.nomina-card {
    background: linear-gradient(135deg, var(--cnt-red) 0%, #c41e3a 100%);
    color: white;
    border-radius: 12px;
    padding: 1rem 1.5rem;
    display: flex;
    align-items: center;
    gap: 1.5rem;
    box-shadow: 0 4px 15px rgba(218, 41, 28, 0.3);
}
.nomina-card .icon {
    width: 60px;
    height: 60px;
    min-width: 60px;
    background: rgba(255,255,255,0.2);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.75rem;
}
.nomina-card .content {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 1rem;
}
.nomina-card .main-info {
    display: flex;
    flex-direction: column;
}
.nomina-card .amount {
    font-size: 2.25rem;
    font-weight: 700;
    line-height: 1;
}
.nomina-card .label {
    font-size: 0.9rem;
    opacity: 0.9;
    margin-top: 0.25rem;
}
.nomina-card .stats {
    display: flex;
    gap: 2rem;
}
.nomina-card .stat {
    text-align: center;
}
.nomina-card .stat-value {
    font-size: 1.25rem;
    font-weight: 700;
}
.nomina-card .stat-label {
    font-size: 0.75rem;
    opacity: 0.85;
}
</style>');

ob_start();
?>

<?php if ($message): ?>
<div class="alert alert-<?php echo $messageType; ?>"><?php echo $message; ?></div>
<?php endif; ?>

<!-- Header -->
<div class="crm-header">
    <div class="row align-items-center">
        <div class="col-md-4">
            <h4 class="mb-0"><i class="bi bi-buildings"></i> <?php echo count($orgs); ?> Empresas</h4>
        </div>
    </div>
</div>

<?php if ($sepaEnabled && $totalNominaGlobal > 0): ?>
<?php
$countConSeccion = 0;
$totalMiembrosFiltrados = 0;
foreach ($orgs as $org) {
    if ($org['org_tiene_seccion']) $countConSeccion++;
    $totalMiembrosFiltrados += $memberCounts[$org['org_id']] ?? 0;
}
?>
<div class="nomina-summary">
    <div class="nomina-card">
        <div class="icon">
            <i class="bi bi-wallet2"></i>
        </div>
        <div class="content">
            <div class="main-info">
                <span class="amount"><?php echo number_format($totalNominaGlobal, 2); ?> €</span>
                <span class="label">Total a percibir por nómina / mes</span>
            </div>
            <div class="stats">
                <div class="stat">
                    <div class="stat-value"><?php echo count($orgs); ?></div>
                    <div class="stat-label">Empresas</div>
                </div>
                <div class="stat">
                    <div class="stat-value"><?php echo $totalMiembrosFiltrados; ?></div>
                    <div class="stat-label">Miembros</div>
                </div>
                <div class="stat">
                    <div class="stat-value"><?php echo $countConSeccion; ?></div>
                    <div class="stat-label">Con Sección</div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!$tablesExist): ?>
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle"></i>
    Las tablas no existen. <a href="install.php">Ejecuta el instalador</a>.
</div>
<?php else: ?>

<!-- Filters -->
<div class="filter-card">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-2">
            <label class="form-label small fw-bold mb-1">Buscar</label>
            <input type="text" name="search" class="form-control form-control-sm" placeholder="Nombre, CIF..." value="<?php echo htmlspecialchars($searchTerm ?? ''); ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-bold mb-1">Email Sección</label>
            <select name="filter_seccion" class="form-select form-select-sm">
                <option value="">Todos</option>
                <?php foreach ($seccionesList as $sec): ?>
                <option value="<?php echo htmlspecialchars($sec); ?>" <?php echo ($filterSeccion ?? '') === $sec ? 'selected' : ''; ?>><?php echo htmlspecialchars($sec); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-bold mb-1">Nombre Sección</label>
            <select name="filter_nombre_seccion" class="form-select form-select-sm">
                <option value="">Todos</option>
                <?php foreach ($nombresSeccionList as $nom): ?>
                <option value="<?php echo htmlspecialchars($nom); ?>" <?php echo ($filterNombreSeccion ?? '') === $nom ? 'selected' : ''; ?>><?php echo htmlspecialchars($nom); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-bold mb-1">Portavoz Sección</label>
            <select name="filter_portavoz" class="form-select form-select-sm">
                <option value="">Todos</option>
                <?php foreach ($portavozList as $port): ?>
                <option value="<?php echo $port['usr_id']; ?>" <?php echo ($filterPortavoz ?? 0) == $port['usr_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($port['nombre']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-bold mb-1">Sector</label>
            <select name="filter_sector" class="form-select form-select-sm">
                <option value="">Todos</option>
                <?php foreach ($sectoresList as $sec): ?>
                <option value="<?php echo htmlspecialchars($sec); ?>" <?php echo ($filterSector ?? '') === $sec ? 'selected' : ''; ?>><?php echo htmlspecialchars($sec); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 d-flex gap-1">
            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search"></i></button>
            <?php if (!empty($searchTerm) || !empty($filterSeccion) || !empty($filterNombreSeccion) || !empty($filterSector) || !empty($filterPortavoz)): ?>
            <a href="organizations.php" class="btn btn-secondary btn-sm"><i class="bi bi-x-lg"></i></a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Table -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center" style="background: var(--cnt-black-light); color: white;">
        <span><i class="bi bi-list"></i> Listado de Empresas</span>
        <a href="create_organization.php" class="btn-create">
            <i class="bi bi-plus-circle"></i> Nueva Empresa
        </a>
    </div>
    <div class="card-body p-0">
        <?php if (empty($orgs)): ?>
        <div class="p-4 text-center text-muted">
            <i class="bi bi-building" style="font-size: 3rem;"></i>
            <p class="mt-2 mb-0">No hay empresas<?php echo !empty($searchTerm) ? ' que coincidan con la búsqueda' : ''; ?>.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>CIF</th>
                        <th>Sector</th>
                        <th class="text-center">¿Sección?</th>
                        <th>Nombre Sección</th>
                        <th class="text-center">Email Sección</th>
                        <th>Portavoz Sección</th>
                        <th class="text-center">Miembros</th>
                        <?php if ($sepaEnabled): ?>
                        <th class="text-end">Nómina/mes</th>
                        <?php endif; ?>
                        <th class="text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orgs as $org): ?>
                    <?php $memberCount = $memberCounts[$org['org_id']] ?? 0; ?>
                    <tr>
                        <td>
                            <a href="organization_profile.php?org_id=<?php echo $org['org_id']; ?>" class="crm-link">
                                <i class="bi bi-building"></i> <?php echo htmlspecialchars($org['org_name']); ?>
                            </a>
                            <?php if (!empty($org['org_nombre_comercial'])): ?>
                            <br><small class="text-muted"><?php echo htmlspecialchars($org['org_nombre_comercial']); ?></small>
                            <?php endif; ?>
                        </td>
                        <td><strong><?php echo htmlspecialchars($org['org_cif'] ?? '-'); ?></strong></td>
                        <td><?php echo htmlspecialchars($org['org_sector'] ?? '-'); ?></td>
                        <td class="text-center">
                            <?php if ($org['org_tiene_seccion']): ?>
                            <span class="badge badge-custom badge-seccion">
                                <i class="bi bi-check-circle"></i> Sí
                            </span>
                            <?php else: ?>
                            <span class="badge badge-custom badge-sin">No</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($org['org_nombre_seccion'] ?? '-'); ?></td>
                        <td class="text-center"><?php echo $org['org_email_seccion'] ? '<small><a href="mailto:' . htmlspecialchars($org['org_email_seccion']) . '">' . htmlspecialchars($org['org_email_seccion']) . '</a></small>' : '-'; ?></td>
                        <td>
                            <?php if (!empty($org['pembuat_usr_uuid'])): ?>
                            <a href="/modules/profile/profile.php?user_uuid=<?php echo $org['pembuat_usr_uuid']; ?>" class="crm-link" target="_blank">
                                <i class="bi bi-person"></i> <?php echo htmlspecialchars($org['pembuat_nombre'] ?? '-'); ?>
                            </a>
                            <?php else: ?>
                            -
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="badge badge-custom badge-miem">
                                <i class="bi bi-people"></i> <?php echo $memberCount; ?>
                            </span>
                        </td>
                        <?php if ($sepaEnabled): ?>
                        <td class="text-end fw-bold text-danger">
                            <?php echo number_format($org['total_nomina'] ?? 0, 2); ?> €
                        </td>
                        <?php endif; ?>
                        <td>
                            <div class="actions-cell">
                                <a href="organization_profile.php?org_id=<?php echo $org['org_id']; ?>" class="btn btn-ver btn-sm">
                                    <i class="bi bi-eye"></i> Ver
                                </a>
                                <?php if ($memberCount == 0): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="action" value="delete_org">
                                    <input type="hidden" name="org_id" value="<?php echo $org['org_id']; ?>">
                                    <button type="submit" class="btn btn-borrar btn-sm" onclick="return confirm('¿Eliminar esta empresa?');">
                                        <i class="bi bi-trash"></i> Borrar
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalOrgs > 0): ?>
        <div class="pagination-custom">
            <div class="pagination-info">
                Mostrando <?php echo count($orgs); ?> de <?php echo $totalOrgs; ?> empresas
            </div>
            <div class="pagination-controls">
                <form method="GET" class="d-flex align-items-center gap-2">
                    <?php
                    $baseUrl = 'organizations.php?';
                    $urlParams = [];
                    if (!empty($searchTerm)) $urlParams[] = 'search=' . urlencode($searchTerm);
                    if (!empty($filterSeccion)) $urlParams[] = 'filter_seccion=' . urlencode($filterSeccion);
                    if (!empty($filterNombreSeccion)) $urlParams[] = 'filter_nombre_seccion=' . urlencode($filterNombreSeccion);
                    if (!empty($filterSector)) $urlParams[] = 'filter_sector=' . urlencode($filterSector);
                    if (!empty($filterPortavoz)) $urlParams[] = 'filter_portavoz=' . $filterPortavoz;
                    $baseUrl .= implode('&', $urlParams);
                    if (!empty($urlParams)) $baseUrl .= '&';
                    ?>
                    <label class="mb-0">Mostrar:</label>
                    <select name="per_page" class="form-select form-select-sm" style="width: auto;" onchange="this.form.submit()">
                        <option value="25" <?php echo $perPage == 25 ? 'selected' : ''; ?>>25 entradas</option>
                        <option value="50" <?php echo $perPage == 50 ? 'selected' : ''; ?>>50 entradas</option>
                        <option value="0" <?php echo $perPage == 0 ? 'selected' : ''; ?>>Todas</option>
                    </select>
                    <input type="hidden" name="search" value="<?php echo htmlspecialchars($searchTerm); ?>">
                    <input type="hidden" name="filter_seccion" value="<?php echo htmlspecialchars($filterSeccion); ?>">
                    <input type="hidden" name="filter_nombre_seccion" value="<?php echo htmlspecialchars($filterNombreSeccion); ?>">
                    <input type="hidden" name="filter_sector" value="<?php echo htmlspecialchars($filterSector); ?>">
                    <input type="hidden" name="filter_portavoz" value="<?php echo $filterPortavoz; ?>">
                </form>

                <?php if ($perPage > 0 && $totalPages > 1): ?>
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <?php if ($currentPage > 1): ?>
                        <li class="page-item"><a class="page-link" href="<?php echo $baseUrl; ?>page=<?php echo $currentPage - 1; ?>&per_page=<?php echo $perPage; ?>">&laquo;</a></li>
                        <?php endif; ?>

                        <?php
                        $start = max(1, $currentPage - 2);
                        $end = min($totalPages, $currentPage + 2);
                        if ($start > 1): ?>
                        <li class="page-item"><a class="page-link" href="<?php echo $baseUrl; ?>page=1&per_page=<?php echo $perPage; ?>">1</a></li>
                        <?php if ($start > 2): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                        <?php endif; ?>
                        <?php endif; ?>

                        <?php for ($i = $start; $i <= $end; $i++): ?>
                        <li class="page-item <?php echo $i == $currentPage ? 'active' : ''; ?>">
                            <a class="page-link" href="<?php echo $baseUrl; ?>page=<?php echo $i; ?>&per_page=<?php echo $perPage; ?>"><?php echo $i; ?></a>
                        </li>
                        <?php endfor; ?>

                        <?php if ($end < $totalPages): ?>
                        <?php if ($end < $totalPages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                        <?php endif; ?>
                        <li class="page-item"><a class="page-link" href="<?php echo $baseUrl; ?>page=<?php echo $totalPages; ?>&per_page=<?php echo $perPage; ?>"><?php echo $totalPages; ?></a></li>
                        <?php endif; ?>

                        <?php if ($currentPage < $totalPages): ?>
                        <li class="page-item"><a class="page-link" href="<?php echo $baseUrl; ?>page=<?php echo $currentPage + 1; ?>&per_page=<?php echo $perPage; ?>">&raquo;</a></li>
                        <?php endif; ?>
                    </ul>
                </nav>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php endif; ?>
    </div>
</div>

<?php endif; ?>

<?php
$page->addHtml(ob_get_clean());
$page->show();
