<?php
/**
 * Companies and Sections Plugin - Company profile
 *
 * Shows and edits a company, its union section and its assigned members.
 *
 * POST ACTIONS (CSRF protected):
 * - update_org:    updates the company data
 * - add_member:    assigns a user to THIS company
 * - remove_member: removes an assignment of THIS company
 *
 * @package CompaniesAndSections
 */

require_once(__DIR__ . '/organization_functions.php');

casRequireAuth();

$orgId = (int)($_GET['org_id'] ?? 0);
if ($orgId <= 0) {
    casFail('Empresa no especificada.');
}

$loadOrg = static function (int $id) use ($gDb): ?array {
    $row = $gDb->queryPrepared('SELECT * FROM ' . CAS_TBL_ORGS . ' WHERE org_id = ? AND org_active = 1', [$id])->fetch();
    return $row ?: null;
};
$org = $loadOrg($orgId);
if (!$org) {
    casFail('Empresa no encontrada.');
}

$users = casUserList();
$formErrors = [];

// ---------------------------------------------------------------- POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    casCheckCsrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'update_org') {
        [$d, $formErrors] = casReadOrgForm($users);
        if (!$formErrors) {
            $gDb->queryPrepared(
                'UPDATE ' . CAS_TBL_ORGS . ' SET
                    org_name = ?, org_nombre_comercial = ?, org_razon_social = ?, org_cif = ?,
                    org_forma_juridica = ?, org_sector = ?, org_description = ?, org_email = ?,
                    org_telefono = ?, org_web = ?, org_tiene_seccion = ?, org_nombre_seccion = ?,
                    org_email_seccion = ?, org_portavoz_seccion = ?, org_calles = ?, org_cp = ?,
                    org_localidad = ?, org_provincia = ?, org_usr_id_change = ?
                  WHERE org_id = ?',
                [
                    $d['org_name'], $d['org_nombre_comercial'], $d['org_razon_social'], $d['org_cif'],
                    $d['org_forma_juridica'], $d['org_sector'], $d['org_description'], $d['org_email'],
                    $d['org_telefono'], $d['org_web'], $d['org_tiene_seccion'], $d['org_nombre_seccion'],
                    $d['org_email_seccion'], $d['org_portavoz_seccion'], $d['org_calles'], $d['org_cp'],
                    $d['org_localidad'], $d['org_provincia'], (int)$gCurrentUserId, $orgId,
                ]
            );
            casRedirect('organization_profile.php', ['org_id' => $orgId, 'saved' => 1]);
        }
        // on errors fall through and show the form with the submitted values
        $org = array_merge($org, $d);
    } elseif ($action === 'add_member') {
        $usrId = (int)($_POST['usr_id'] ?? 0);
        $role = mb_substr(trim((string)($_POST['mro_role'] ?? '')), 0, 100);
        if (isset($users[$usrId])) {
            // reactivates an old assignment or creates a new one (unique key usr+org)
            $gDb->queryPrepared(
                'INSERT INTO ' . CAS_TBL_MEMBERS . '
                        (mro_uuid, mro_usr_id, mro_org_id, mro_role, mro_active, mro_usr_id_create, mro_timestamp_create)
                 VALUES (?, ?, ?, ?, 1, ?, NOW())
                 ON DUPLICATE KEY UPDATE mro_active = 1, mro_role = VALUES(mro_role)',
                [casUuid(), $usrId, $orgId, $role, (int)$gCurrentUserId]
            );
        }
        casRedirect('organization_profile.php', ['org_id' => $orgId]);
    } elseif ($action === 'remove_member') {
        // only assignments of THIS company can be removed from this page
        $gDb->queryPrepared(
            'DELETE FROM ' . CAS_TBL_MEMBERS . ' WHERE mro_id = ? AND mro_org_id = ?',
            [(int)($_POST['mro_id'] ?? 0), $orgId]
        );
        casRedirect('organization_profile.php', ['org_id' => $orgId]);
    } else {
        casRedirect('organization_profile.php', ['org_id' => $orgId]);
    }
}

// ---------------------------------------------------------------- data for the page
[$fnId, $lnId] = casNameFieldIds();
$members = $gDb->queryPrepared(
    'SELECT m.mro_id, m.mro_usr_id, m.mro_role, u.usr_uuid,
            fn.usd_value AS first_name, ln.usd_value AS last_name
       FROM ' . CAS_TBL_MEMBERS . ' m
 INNER JOIN ' . TBL_USERS . ' u ON u.usr_id = m.mro_usr_id
  LEFT JOIN ' . TBL_USER_DATA . ' fn ON fn.usd_usr_id = u.usr_id AND fn.usd_usf_id = ?
  LEFT JOIN ' . TBL_USER_DATA . ' ln ON ln.usd_usr_id = u.usr_id AND ln.usd_usf_id = ?
      WHERE m.mro_org_id = ? AND m.mro_active = 1
   ORDER BY ln.usd_value, fn.usd_value',
    [$fnId, $lnId, $orgId]
)->fetchAll();
$memberIds = array_map(static fn($m) => (int)$m['mro_usr_id'], $members);

// current (not ended) roles of the members of THIS company in the current organization
$userRoles = [];
if ($memberIds) {
    $ph = implode(',', array_fill(0, count($memberIds), '?'));
    $stmt = $gDb->queryPrepared(
        'SELECT mem.mem_usr_id, rol.rol_name
           FROM ' . TBL_MEMBERS . ' mem
     INNER JOIN ' . TBL_ROLES . ' rol ON rol.rol_id = mem.mem_rol_id AND rol.rol_valid = true
     INNER JOIN ' . TBL_CATEGORIES . ' cat ON cat.cat_id = rol.rol_cat_id
          WHERE mem.mem_usr_id IN (' . $ph . ')
            AND mem.mem_begin <= ? AND mem.mem_end > ?
            AND (cat.cat_org_id = ? OR cat.cat_org_id IS NULL)
       ORDER BY rol.rol_name',
        array_merge($memberIds, [DATE_NOW, DATE_NOW, $gCurrentOrgId])
    );
    foreach ($stmt->fetchAll() as $row) {
        $userRoles[(int)$row['mem_usr_id']][] = $row['rol_name'];
    }
}

$sepa = casSepaInfo($memberIds);
$stats = ['total' => count($members), 'nomina' => count($sepa['amounts']), 'total_nomina' => array_sum($sepa['amounts'])];

$gNavigation->addUrl(CURRENT_URL, $org['org_name']);
$page = \Admidio\UI\Presenter\PagePresenter::withHtmlIDAndHeadline('companies-and-sections-profile', e($org['org_name']));
$page->addHtml('
<style>
:root { --cnt-red:#DA291C; --cnt-black:#4a4a4a; --cnt-black-light:#2d2d2d; --cnt-green:#198754; --cnt-blue:#0d6efd; }
.stat-card { border-radius:10px; padding:15px; text-align:center; color:#fff; }
.stat-card-red { background:linear-gradient(135deg,var(--cnt-red) 0%,#c41e3a 100%); }
.stat-card-blue { background:linear-gradient(135deg,var(--cnt-blue) 0%,#0a58ca 100%); }
.stat-card-green { background:linear-gradient(135deg,var(--cnt-green) 0%,#157347 100%); }
.stat-number { font-size:2rem; font-weight:700; } .stat-label { font-size:1rem; opacity:.9; font-weight:600; }
.btn-save { background:var(--cnt-red); border-color:var(--cnt-red); color:#fff; } .btn-save:hover { background:#c41e3a; color:#fff; }
.card-header-custom { background:var(--cnt-black-light); color:#fff; font-weight:600; }
.crm-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:1rem; }
.crm-grid .card-miembros { grid-column:1 / -1; }
@media (max-width:1199px) { .crm-grid { grid-template-columns:1fr; } }
.members-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:1rem; align-items:start; }
@media (max-width:991px) { .members-grid { grid-template-columns:1fr; } }
.crm-member-card { border-left:5px solid var(--cnt-red); }
.crm-member-card .card-header { background:var(--cnt-black-light); color:#fff; }
.badge-large { padding:6px 12px; font-size:.9rem; margin:0 4px 4px 0; }
.btn-remove-member { background:var(--cnt-red); border:none; color:#fff; border-radius:50%; width:32px; height:32px; display:flex; align-items:center; justify-content:center; padding:0; }
.btn-remove-member:hover { background:#c41e3a; color:#fff; }
#seccion_fields.hidden { display:none; }
</style>');
$page->addJavascript('
    var cb = document.getElementById("org_tiene_seccion"), f = document.getElementById("seccion_fields");
    var upd = function () { f.classList.toggle("hidden", !cb.checked); };
    cb.addEventListener("change", upd); upd();
', true);

ob_start();
?>
<div class="mb-3">
    <a href="organizations.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Volver a empresas</a>
</div>

<?php if (isset($_GET['saved'])): ?>
<div class="alert alert-success">Cambios guardados.</div>
<?php endif; ?>
<?php if ($formErrors): ?>
<div class="alert alert-danger"><?php echo implode('<br>', array_map('e', $formErrors)); ?></div>
<?php endif; ?>

<?php if ($sepa['enabled']): ?>
<div class="row mb-4">
    <div class="col-md-4"><div class="stat-card stat-card-blue"><div class="stat-number"><?php echo (int)$stats['total']; ?></div><div class="stat-label">Total miembros</div></div></div>
    <div class="col-md-4"><div class="stat-card stat-card-red"><div class="stat-number"><?php echo (int)$stats['nomina']; ?></div><div class="stat-label">Pago por nómina</div></div></div>
    <div class="col-md-4"><div class="stat-card stat-card-green"><div class="stat-number"><?php echo e(number_format((float)$stats['total_nomina'], 2, ',', '.')); ?> €</div><div class="stat-label">Total a percibir / mes</div></div></div>
</div>
<?php endif; ?>

<div class="crm-grid">
    <form method="POST" action="organization_profile.php?org_id=<?php echo $orgId; ?>" id="updateOrgForm" class="contents" style="display:contents">
        <?php echo casCsrfField(); ?>
        <input type="hidden" name="action" value="update_org">

        <div class="card">
            <div class="card-header card-header-custom"><i class="bi bi-building"></i> Datos de la empresa</div>
            <div class="card-body">
                <div class="mb-2"><label class="form-label fw-bold small">Nombre <span class="text-danger">*</span></label>
                    <input type="text" name="org_name" maxlength="100" class="form-control" value="<?php echo e($org['org_name']); ?>" required></div>
                <div class="mb-2"><label class="form-label fw-bold small">Nombre comercial</label>
                    <input type="text" name="org_nombre_comercial" maxlength="100" class="form-control" value="<?php echo e($org['org_nombre_comercial']); ?>"></div>
                <div class="mb-2"><label class="form-label fw-bold small">Razón social</label>
                    <input type="text" name="org_razon_social" maxlength="100" class="form-control" value="<?php echo e($org['org_razon_social']); ?>"></div>
                <div class="row mb-2">
                    <div class="col-6"><label class="form-label fw-bold small">CIF</label>
                        <input type="text" name="org_cif" maxlength="20" class="form-control" style="text-transform:uppercase;" value="<?php echo e($org['org_cif']); ?>"></div>
                    <div class="col-6"><label class="form-label fw-bold small">Forma jurídica</label>
                        <input type="text" name="org_forma_juridica" maxlength="50" class="form-control" value="<?php echo e($org['org_forma_juridica']); ?>"></div>
                </div>
                <div class="mb-2"><label class="form-label fw-bold small">Sector</label>
                    <input type="text" name="org_sector" maxlength="100" class="form-control" value="<?php echo e($org['org_sector']); ?>"></div>
                <div class="mb-2"><label class="form-label fw-bold small">Correo</label>
                    <input type="email" name="org_email" maxlength="100" class="form-control" value="<?php echo e($org['org_email']); ?>"></div>
                <div class="mb-2"><label class="form-label fw-bold small">Teléfono</label>
                    <input type="tel" name="org_telefono" maxlength="20" class="form-control" value="<?php echo e($org['org_telefono']); ?>"></div>
                <div class="mb-2"><label class="form-label fw-bold small">Web</label>
                    <input type="url" name="org_web" maxlength="255" class="form-control" placeholder="https://..." value="<?php echo e($org['org_web']); ?>"></div>
                <div class="mb-2"><label class="form-label fw-bold small">Descripción</label>
                    <textarea name="org_description" class="form-control" rows="2"><?php echo e($org['org_description']); ?></textarea></div>
                <button type="submit" class="btn btn-save mt-3"><i class="bi bi-save"></i> Guardar cambios</button>
            </div>
        </div>

        <div class="card">
            <div class="card-header card-header-custom"><i class="bi bi-geo-alt"></i> Dirección</div>
            <div class="card-body">
                <div class="mb-2"><label class="form-label fw-bold small">Calle</label>
                    <input type="text" name="org_calles" maxlength="255" class="form-control" value="<?php echo e($org['org_calles']); ?>"></div>
                <div class="row mb-2">
                    <div class="col-6"><label class="form-label fw-bold small">Código postal</label>
                        <input type="text" name="org_cp" maxlength="10" class="form-control" value="<?php echo e($org['org_cp']); ?>"></div>
                    <div class="col-6"><label class="form-label fw-bold small">Localidad</label>
                        <input type="text" name="org_localidad" maxlength="100" class="form-control" value="<?php echo e($org['org_localidad']); ?>"></div>
                </div>
                <div class="mb-2"><label class="form-label fw-bold small">Provincia</label>
                    <input type="text" name="org_provincia" maxlength="100" class="form-control" value="<?php echo e($org['org_provincia']); ?>"></div>
            </div>
        </div>

        <div class="card">
            <div class="card-header card-header-custom"><i class="bi bi-people"></i> Sección sindical</div>
            <div class="card-body">
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="org_tiene_seccion" name="org_tiene_seccion" value="1" <?php echo $org['org_tiene_seccion'] ? 'checked' : ''; ?>>
                    <label class="form-check-label fw-bold" for="org_tiene_seccion">¿Tiene sección sindical?</label>
                </div>
                <div id="seccion_fields">
                    <div class="mb-2"><label class="form-label fw-bold small">Nombre de la sección</label>
                        <input type="text" name="org_nombre_seccion" maxlength="100" class="form-control" value="<?php echo e($org['org_nombre_seccion']); ?>"></div>
                    <div class="mb-2"><label class="form-label fw-bold small">Correo de la sección</label>
                        <input type="email" name="org_email_seccion" maxlength="100" class="form-control" value="<?php echo e($org['org_email_seccion']); ?>"></div>
                    <div class="mb-2"><label class="form-label fw-bold small">Portavoz de la sección</label>
                        <select name="org_portavoz_seccion" class="form-select">
                            <option value="">Seleccionar miembro...</option>
                            <?php foreach ($users as $u): ?>
                            <option value="<?php echo (int)$u['usr_id']; ?>" <?php echo (int)$org['org_portavoz_seccion'] === (int)$u['usr_id'] ? 'selected' : ''; ?>><?php echo e(casUserLabel($u)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <div class="card card-miembros">
        <div class="card-header card-header-custom"><i class="bi bi-people"></i> Miembros (<?php echo (int)$stats['total']; ?>)</div>
        <div class="card-body">
            <form method="POST" action="organization_profile.php?org_id=<?php echo $orgId; ?>" class="mb-4">
                <?php echo casCsrfField(); ?>
                <input type="hidden" name="action" value="add_member">
                <div class="d-flex gap-2 align-items-end flex-wrap">
                    <div class="flex-grow-1">
                        <label class="form-label small fw-bold" for="usr_id">Miembro</label>
                        <select id="usr_id" name="usr_id" class="form-select" required>
                            <option value="">Seleccionar...</option>
                            <?php foreach ($users as $u): if (in_array((int)$u['usr_id'], $memberIds, true)) continue; ?>
                            <option value="<?php echo (int)$u['usr_id']; ?>"><?php echo e(casUserLabel($u)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label small fw-bold" for="mro_role">Cargo</label>
                        <input type="text" id="mro_role" name="mro_role" maxlength="100" class="form-control" placeholder="Cargo" style="width:180px;">
                    </div>
                    <div><button type="submit" class="btn btn-success">Asignar</button></div>
                </div>
            </form>

            <?php if (!$members): ?>
            <p class="text-muted mt-4 ms-3">No hay miembros asignados a esta empresa.</p>
            <?php else: ?>
            <div class="members-grid mt-4">
                <?php foreach ($members as $m):
                    $uid = (int)$m['mro_usr_id'];
                    $pm = $sepa['methods'][$uid] ?? '';
                ?>
                <div class="crm-member-card card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <a class="fw-bold text-white" href="<?php echo e(ADMIDIO_URL . FOLDER_MODULES . '/profile/profile.php?user_uuid=' . rawurlencode($m['usr_uuid'])); ?>">
                            <?php echo e(casUserLabel($m)); ?>
                        </a>
                        <form method="POST" action="organization_profile.php?org_id=<?php echo $orgId; ?>" class="mb-0" onsubmit="return confirm('¿Quitar a este miembro de la empresa?');">
                            <?php echo casCsrfField(); ?>
                            <input type="hidden" name="action" value="remove_member">
                            <input type="hidden" name="mro_id" value="<?php echo (int)$m['mro_id']; ?>">
                            <button type="submit" class="btn btn-remove-member" title="Quitar"><i class="bi bi-trash"></i></button>
                        </form>
                    </div>
                    <div class="card-body d-flex flex-column gap-2">
                        <?php if (!empty($m['mro_role'])): ?>
                        <div><small class="text-muted fw-bold text-uppercase">Cargo</small>
                            <div class="mt-1"><span class="badge bg-danger badge-large"><?php echo e($m['mro_role']); ?></span></div></div>
                        <?php endif; ?>
                        <?php if (!empty($userRoles[$uid])): ?>
                        <div><small class="text-muted fw-bold text-uppercase">Roles</small>
                            <div class="mt-1"><?php foreach ($userRoles[$uid] as $r): ?><span class="badge bg-dark badge-large"><?php echo e($r); ?></span><?php endforeach; ?></div></div>
                        <?php endif; ?>
                        <?php if ($pm !== ''): ?>
                        <div><small class="text-muted fw-bold text-uppercase">Forma de pago</small>
                            <div class="mt-1"><span class="badge bg-success badge-large"><?php echo e($pm); ?></span></div></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php
$page->addHtml(ob_get_clean());
$page->show();
