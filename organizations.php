<?php
/**
 * Companies and Sections Plugin - Main Page
 *
 * List of companies with filters, pagination and member counts.
 * Secretariat (config.php roles): all companies, can create/delete.
 * Delegates: only their own companies, read only.
 *
 * POST ACTIONS (CSRF protected, secretariat only):
 * - delete_org: deletes a company, only if it has no active members
 *
 * @package CompaniesAndSections
 */

require_once(__DIR__ . '/organization_functions.php');

casRequireAuth();

$headline = 'Empresas y secciones';
$gNavigation->addStartUrl(CURRENT_URL, $headline, 'bi-building');

$message = '';
$messageType = '';
if (isset($_GET['created'])) {
    $message = 'Empresa creada correctamente.';
    $messageType = 'success';
} elseif (isset($_GET['deleted'])) {
    $message = 'Empresa eliminada.';
    $messageType = 'success';
} elseif (isset($_GET['notdeleted'])) {
    $message = 'No se puede eliminar: la empresa tiene miembros asignados.';
    $messageType = 'danger';
}

// Tables installed?
$tablesExist = casSchemaReady();
$canEdit = casCanEdit();

// ---------------------------------------------------------------- POST: delete
if ($tablesExist && $_SERVER['REQUEST_METHOD'] === 'POST') {
    casCheckCsrf();
    casRequireEdit();
    if (($_POST['action'] ?? '') === 'delete_org') {
        $delId = (int)($_POST['org_id'] ?? 0);
        try {
            $gDb->startTransaction();
            $cnt = (int)$gDb->queryPrepared(
                'SELECT COUNT(*) FROM ' . CAS_TBL_MEMBERS . ' WHERE mro_org_id = ? AND mro_active = 1 FOR UPDATE',
                [$delId]
            )->fetchColumn();
            if ($cnt > 0) {
                $gDb->rollback();
                casRedirect('organizations.php', ['notdeleted' => 1]);
            }
            $gDb->queryPrepared('DELETE FROM ' . CAS_TBL_MEMBERS . ' WHERE mro_org_id = ?', [$delId]);
            $gDb->queryPrepared('DELETE FROM ' . CAS_TBL_ORGS . ' WHERE org_id = ?', [$delId]);
            $gDb->endTransaction();
        } catch (\Throwable $e) {
            casFail('No se pudo eliminar la empresa.', $e);
        }
        casRedirect('organizations.php', ['deleted' => 1]);
    }
    casRedirect('organizations.php');
}

$orgs = [];
$memberCounts = [];
$totalOrgs = 0;
$totalPages = 1;
$users = casUserList();

$searchTerm = trim((string)($_GET['search'] ?? ''));
$filterSeccion = (string)($_GET['filter_seccion'] ?? '');
$filterNombreSeccion = (string)($_GET['filter_nombre_seccion'] ?? '');
$filterSector = (string)($_GET['filter_sector'] ?? '');
$filterPortavoz = (int)($_GET['filter_portavoz'] ?? 0);
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 25;
if (!in_array($perPage, [0, 25, 50, 100], true)) {
    $perPage = 25;
}
$currentPage = max(1, (int)($_GET['page'] ?? 1));
$seccionesList = $nombresSeccionList = $sectoresList = [];

if ($tablesExist) {
    $distinct = static function (string $col) use ($gDb): array {
        $stmt = $gDb->queryPrepared(
            'SELECT DISTINCT ' . $col . ' AS v FROM ' . CAS_TBL_ORGS . '
              WHERE ' . $col . ' IS NOT NULL AND ' . $col . ' <> \'\' ORDER BY ' . $col
        );
        return array_column($stmt->fetchAll(), 'v');
    };
    $seccionesList = $distinct('org_email_seccion');
    $nombresSeccionList = $distinct('org_nombre_seccion');
    $sectoresList = $distinct('org_sector');

    $conditions = ['org_active = 1'];
    $params = [];
    if (!$canEdit) {   // delegates: only their companies
        $own = casDelegateOrgIds() ?: [0];
        $conditions[] = 'org_id IN (' . implode(',', array_fill(0, count($own), '?')) . ')';
        $params = array_merge($params, $own);
    }
    if ($searchTerm !== '') {
        $conditions[] = '(org_name LIKE ? OR org_nombre_comercial LIKE ? OR org_razon_social LIKE ? OR org_cif LIKE ?)';
        $like = '%' . addcslashes($searchTerm, '%_\\') . '%';
        array_push($params, $like, $like, $like, $like);
    }
    if ($filterSeccion !== '') {
        $conditions[] = 'org_email_seccion = ?';
        $params[] = $filterSeccion;
    }
    if ($filterNombreSeccion !== '') {
        $conditions[] = 'org_nombre_seccion = ?';
        $params[] = $filterNombreSeccion;
    }
    if ($filterSector !== '') {
        $conditions[] = 'org_sector = ?';
        $params[] = $filterSector;
    }
    if ($filterPortavoz > 0) {
        $conditions[] = 'org_portavoz_seccion = ?';
        $params[] = $filterPortavoz;
    }
    $where = implode(' AND ', $conditions);

    $totalOrgs = (int)$gDb->queryPrepared('SELECT COUNT(*) FROM ' . CAS_TBL_ORGS . ' WHERE ' . $where, $params)->fetchColumn();
    $totalPages = $perPage > 0 ? max(1, (int)ceil($totalOrgs / $perPage)) : 1;
    $currentPage = min($currentPage, $totalPages);
    $offset = ($currentPage - 1) * $perPage;

    $sql = 'SELECT * FROM ' . CAS_TBL_ORGS . ' WHERE ' . $where . ' ORDER BY org_name';
    if ($perPage > 0) {
        $sql .= ' LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset;
    }
    $orgs = $gDb->queryPrepared($sql, $params)->fetchAll();

    // active members per company + payroll totals
    $orgMembers = [];
    $stmt = $gDb->queryPrepared('SELECT mro_org_id, mro_usr_id FROM ' . CAS_TBL_MEMBERS . ' WHERE mro_active = 1');
    foreach ($stmt->fetchAll() as $row) {
        $orgMembers[(int)$row['mro_org_id']][] = (int)$row['mro_usr_id'];
    }
    foreach ($orgMembers as $oid => $list) {
        $memberCounts[$oid] = count($list);
    }

}

$page = \Admidio\UI\Presenter\PagePresenter::withHtmlIDAndHeadline('companies-and-sections', $headline);
$page->setContentFullWidth();
$page->addHtml('
<style>
:root { --cnt-red:#DA291C; --cnt-black:#4a4a4a; --cnt-black-light:#2d2d2d; --cnt-green:#198754; --cnt-blue:#0d6efd; }
.btn-create { background:linear-gradient(135deg,var(--cnt-red) 0%,#c41e3a 100%); color:#fff; border:none; padding:8px 16px; font-weight:600; border-radius:6px; font-size:.85rem; text-decoration:none; display:inline-flex; align-items:center; gap:6px; }
.btn-create:hover { color:#fff; filter:brightness(1.05); }
.crm-header { background:linear-gradient(135deg,var(--cnt-red) 0%,#c41e3a 100%); color:#fff; padding:15px 20px; border-radius:10px; margin-bottom:20px; }
.badge-custom { padding:8px 14px; font-size:.9rem; font-weight:600; display:inline-flex; align-items:center; gap:5px; }
.badge-seccion { background:var(--cnt-green); color:#fff; } .badge-miem { background:var(--cnt-black-light); color:#fff; } .badge-sin { background:#6c757d; color:#fff; }
.btn-ver { background:var(--cnt-blue); color:#fff; padding:6px 14px; font-size:.85rem; display:inline-flex; align-items:center; gap:6px; }
.btn-borrar { background:var(--cnt-red); color:#fff; padding:6px 14px; font-size:.85rem; display:inline-flex; align-items:center; gap:6px; }
.btn-ver:hover, .btn-borrar:hover { color:#fff; filter:brightness(.92); }
.crm-link { color:var(--cnt-red); text-decoration:none; font-weight:600; } .crm-link:hover { text-decoration:underline; }
.filter-card { border:2px solid #e9ecef; border-radius:10px; padding:12px 15px; margin-bottom:15px; background:#fafafa; }
.table { font-size:.85rem; } .table th { background:#f8f9fa; font-weight:600; color:var(--cnt-black); font-size:.8rem; } .table td { vertical-align:middle; }
.actions-cell { display:flex; gap:6px; justify-content:center; flex-wrap:wrap; }
.pagination-custom { display:flex; justify-content:space-between; align-items:center; padding:15px; border-top:1px solid #dee2e6; }
.pagination-info { color:#6c757d; font-size:.85rem; } .pagination-controls { display:flex; align-items:center; gap:10px; }
.page-link { color:var(--cnt-red); }
</style>');

$query = array_filter([
    'search' => $searchTerm, 'filter_seccion' => $filterSeccion, 'filter_nombre_seccion' => $filterNombreSeccion,
    'filter_sector' => $filterSector, 'filter_portavoz' => $filterPortavoz ?: null, 'per_page' => $perPage,
], static fn($v) => $v !== '' && $v !== null);
$pageUrl = static fn(int $p): string => e('organizations.php?' . http_build_query($query + ['page' => $p]));

ob_start();
?>
<?php if ($message): ?>
<div class="alert alert-<?php echo e($messageType); ?>"><?php echo e($message); ?></div>
<?php endif; ?>

<div class="crm-header">
    <h4 class="mb-0"><i class="bi bi-buildings"></i> <?php echo (int)$totalOrgs; ?> empresas<?php echo $canEdit ? '' : ' (tus empresas, solo lectura)'; ?></h4>
</div>

<?php if (!$tablesExist): ?>
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle"></i> Las tablas del plugin no existen o son de una versión anterior.
    <?php if ($gCurrentUser->isAdministrator()): ?>Sube <code>install.php</code> y <a href="<?php echo e(CAS_URL . '/install.php'); ?>">ejecuta el instalador</a> para crearlas o actualizarlas.<?php endif; ?>
</div>
<?php else: ?>

<div class="filter-card">
    <form method="GET" action="organizations.php" class="row g-2 align-items-end">
        <div class="col-md-2">
            <label class="form-label small fw-bold mb-1">Buscar</label>
            <input type="text" name="search" class="form-control form-control-sm" placeholder="Nombre, CIF..." value="<?php echo e($searchTerm); ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-bold mb-1">Correo sección</label>
            <select name="filter_seccion" class="form-select form-select-sm">
                <option value="">Todos</option>
                <?php foreach ($seccionesList as $v): ?>
                <option value="<?php echo e($v); ?>" <?php echo $filterSeccion === $v ? 'selected' : ''; ?>><?php echo e($v); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-bold mb-1">Nombre sección</label>
            <select name="filter_nombre_seccion" class="form-select form-select-sm">
                <option value="">Todos</option>
                <?php foreach ($nombresSeccionList as $v): ?>
                <option value="<?php echo e($v); ?>" <?php echo $filterNombreSeccion === $v ? 'selected' : ''; ?>><?php echo e($v); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-bold mb-1">Portavoz sección</label>
            <select name="filter_portavoz" class="form-select form-select-sm">
                <option value="">Todos</option>
                <?php foreach ($users as $u): ?>
                <option value="<?php echo (int)$u['usr_id']; ?>" <?php echo $filterPortavoz === (int)$u['usr_id'] ? 'selected' : ''; ?>><?php echo e(casUserLabel($u)); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-bold mb-1">Sector</label>
            <select name="filter_sector" class="form-select form-select-sm">
                <option value="">Todos</option>
                <?php foreach ($sectoresList as $v): ?>
                <option value="<?php echo e($v); ?>" <?php echo $filterSector === $v ? 'selected' : ''; ?>><?php echo e($v); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 d-flex gap-1">
            <input type="hidden" name="per_page" value="<?php echo (int)$perPage; ?>">
            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search"></i></button>
            <?php if ($searchTerm !== '' || $filterSeccion !== '' || $filterNombreSeccion !== '' || $filterSector !== '' || $filterPortavoz): ?>
            <a href="organizations.php" class="btn btn-secondary btn-sm"><i class="bi bi-x-lg"></i></a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center" style="background:var(--cnt-black-light);color:#fff;">
        <span><i class="bi bi-list"></i> Listado de empresas</span>
        <?php if ($canEdit): ?><a href="create_organization.php" class="btn-create"><i class="bi bi-plus-circle"></i> Nueva empresa</a><?php endif; ?>
    </div>
    <div class="card-body p-0">
        <?php if (!$orgs): ?>
        <div class="p-4 text-center text-muted">
            <i class="bi bi-building" style="font-size:3rem;"></i>
            <p class="mt-2 mb-0">No hay empresas<?php echo $searchTerm !== '' ? ' que coincidan con la búsqueda' : ''; ?>.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Nombre</th><th>CIF</th><th>Sector</th><th class="text-center">¿Sección?</th>
                        <th>Nombre sección</th><th class="text-center">Correo sección</th><th>Portavoz sección</th>
                        <th class="text-center">Miembros</th>
                        <th class="text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($orgs as $org):
                    $oid = (int)$org['org_id'];
                    $memberCount = $memberCounts[$oid] ?? 0;
                    $portavoz = $users[(int)$org['org_portavoz_seccion']] ?? null;
                    $profileUrl = 'organization_profile.php?org_id=' . $oid;
                ?>
                    <tr>
                        <td>
                            <a href="<?php echo e($profileUrl); ?>" class="crm-link"><i class="bi bi-building"></i> <?php echo e($org['org_name']); ?></a>
                            <?php if (!empty($org['org_nombre_comercial'])): ?><br><small class="text-muted"><?php echo e($org['org_nombre_comercial']); ?></small><?php endif; ?>
                        </td>
                        <td><strong><?php echo e($org['org_cif'] ?: '-'); ?></strong></td>
                        <td><?php echo e($org['org_sector'] ?: '-'); ?></td>
                        <td class="text-center">
                            <?php if ($org['org_tiene_seccion']): ?>
                            <span class="badge badge-custom badge-seccion"><i class="bi bi-check-circle"></i> Sí</span>
                            <?php else: ?>
                            <span class="badge badge-custom badge-sin">No</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo e($org['org_nombre_seccion'] ?: '-'); ?></td>
                        <td class="text-center">
                            <?php if (!empty($org['org_email_seccion'])): ?>
                            <small><a href="mailto:<?php echo e($org['org_email_seccion']); ?>"><?php echo e($org['org_email_seccion']); ?></a></small>
                            <?php else: ?>-<?php endif; ?>
                        </td>
                        <td>
                            <?php if ($portavoz): ?>
                            <a href="<?php echo e(ADMIDIO_URL . FOLDER_MODULES . '/profile/profile.php?user_uuid=' . rawurlencode($portavoz['usr_uuid'])); ?>" class="crm-link" target="_blank" rel="noopener">
                                <i class="bi bi-person"></i> <?php echo e(casUserLabel($portavoz)); ?>
                            </a>
                            <?php else: ?>-<?php endif; ?>
                        </td>
                        <td class="text-center"><span class="badge badge-custom badge-miem"><i class="bi bi-people"></i> <?php echo (int)$memberCount; ?></span></td>
                        <td>
                            <div class="actions-cell">
                                <a href="<?php echo e($profileUrl); ?>" class="btn btn-ver btn-sm"><i class="bi bi-eye"></i> Ver</a>
                                <?php if ($canEdit && $memberCount === 0): ?>
                                <form method="POST" action="organizations.php" class="d-inline" onsubmit="return confirm('¿Eliminar esta empresa?');">
                                    <?php echo casCsrfField(); ?>
                                    <input type="hidden" name="action" value="delete_org">
                                    <input type="hidden" name="org_id" value="<?php echo $oid; ?>">
                                    <button type="submit" class="btn btn-borrar btn-sm"><i class="bi bi-trash"></i> Borrar</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="pagination-custom">
            <div class="pagination-info">Mostrando <?php echo count($orgs); ?> de <?php echo (int)$totalOrgs; ?> empresas</div>
            <div class="pagination-controls">
                <form method="GET" action="organizations.php" class="d-flex align-items-center gap-2">
                    <?php foreach (array_diff_key($query, ['per_page' => 1]) as $k => $v): ?>
                    <input type="hidden" name="<?php echo e($k); ?>" value="<?php echo e($v); ?>">
                    <?php endforeach; ?>
                    <label class="mb-0" for="per_page">Mostrar:</label>
                    <select id="per_page" name="per_page" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()">
                        <?php foreach ([25 => '25', 50 => '50', 100 => '100', 0 => 'Todas'] as $v => $label): ?>
                        <option value="<?php echo $v; ?>" <?php echo $perPage === $v ? 'selected' : ''; ?>><?php echo $label; ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <?php if ($perPage > 0 && $totalPages > 1): ?>
                <nav><ul class="pagination pagination-sm mb-0">
                    <?php if ($currentPage > 1): ?><li class="page-item"><a class="page-link" href="<?php echo $pageUrl($currentPage - 1); ?>">&laquo;</a></li><?php endif; ?>
                    <?php for ($i = max(1, $currentPage - 2); $i <= min($totalPages, $currentPage + 2); $i++): ?>
                    <li class="page-item <?php echo $i === $currentPage ? 'active' : ''; ?>"><a class="page-link" href="<?php echo $pageUrl($i); ?>"><?php echo $i; ?></a></li>
                    <?php endfor; ?>
                    <?php if ($currentPage < $totalPages): ?><li class="page-item"><a class="page-link" href="<?php echo $pageUrl($currentPage + 1); ?>">&raquo;</a></li><?php endif; ?>
                </ul></nav>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
<?php
$page->addHtml(ob_get_clean());
$page->show();
