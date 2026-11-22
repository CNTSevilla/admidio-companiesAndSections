<?php
/**
 * Companies and Sections Plugin - Company profile
 *
 * Company data, union section, workplaces (centros de trabajo) and assigned members.
 *
 * Secretariat (config.php roles): sees and edits everything.
 * Delegates: see only their companies, read only, without roles or payment data.
 * Only a company WITH union section can have delegates (portavoz = delegado/a).
 *
 * POST ACTIONS (CSRF protected, secretariat only):
 * - update_org
 * - add_workplace / update_workplace / delete_workplace
 * - add_member / update_member / remove_member
 *
 * @package CompaniesAndSections
 */

require_once(__DIR__ . '/organization_functions.php');

casRequireAuth();

$orgId = (int)($_GET['org_id'] ?? 0);
if ($orgId <= 0) {
    casFail('Empresa no especificada.');
}
if (!casSchemaReady()) {
    casFail('El plugin necesita actualizarse: un administrador debe ejecutar install.php.');
}
if (!casCanView($orgId)) {
    casDeny(false, 'No tienes acceso a esta empresa.');
}
$canEdit = casCanEdit();

$org = $gDb->queryPrepared('SELECT * FROM ' . CAS_TBL_ORGS . ' WHERE org_id = ? AND org_active = 1', [$orgId])->fetch();
if (!$org) {
    casFail('Empresa no encontrada.');
}

$users = casUserList();
$workplaces = casWorkplaces($orgId);
$formErrors = [];
$back = static function (array $extra = []) use ($orgId): void {
    casRedirect('organization_profile.php', ['org_id' => $orgId] + $extra);
};
// a workplace id from POST, only if it belongs to THIS company (else null)
$postWkp = static function () use ($workplaces): ?int {
    $id = (int)($_POST['mro_wkp_id'] ?? 0);
    return isset($workplaces[$id]) ? $id : null;
};
// delegate mark from POST: only possible if the company has a union section
$hasSection = (int)$org['org_tiene_seccion'] === 1;
$postDelegado = static function () use ($hasSection): int {
    return $hasSection && isset($_POST['mro_delegado']) ? 1 : 0;
};

// ---------------------------------------------------------------- POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    casCheckCsrf();
    casRequireEdit();
    $action = (string)($_POST['action'] ?? '');

    switch ($action) {
        case 'update_org':
            [$d, $formErrors] = casReadOrgForm();
            if (!$formErrors) {
                try {
                    $gDb->startTransaction();
                    $gDb->queryPrepared(
                        'UPDATE ' . CAS_TBL_ORGS . ' SET
                            org_name = ?, org_nombre_comercial = ?, org_razon_social = ?, org_cif = ?,
                            org_forma_juridica = ?, org_sector = ?, org_description = ?, org_email = ?,
                            org_telefono = ?, org_web = ?, org_tiene_seccion = ?, org_nombre_seccion = ?,
                            org_email_seccion = ?, org_calles = ?, org_cp = ?,
                            org_localidad = ?, org_provincia = ?, org_usr_id_change = ?
                          WHERE org_id = ?',
                        [
                            $d['org_name'], $d['org_nombre_comercial'], $d['org_razon_social'], $d['org_cif'],
                            $d['org_forma_juridica'], $d['org_sector'], $d['org_description'], $d['org_email'],
                            $d['org_telefono'], $d['org_web'], $d['org_tiene_seccion'], $d['org_nombre_seccion'],
                            $d['org_email_seccion'], $d['org_calles'], $d['org_cp'],
                            $d['org_localidad'], $d['org_provincia'], (int)$gCurrentUserId, $orgId,
                        ]
                    );
                    $removed = 0;
                    if (!$d['org_tiene_seccion']) {
                        // without union section there are no delegates
                        $removed = $gDb->queryPrepared(
                            'UPDATE ' . CAS_TBL_MEMBERS . ' SET mro_delegado = 0 WHERE mro_org_id = ? AND mro_delegado = 1',
                            [$orgId]
                        )->rowCount();
                    }
                    $gDb->endTransaction();
                } catch (\Throwable $e) {
                    casFail('No se pudieron guardar los cambios.', $e);
                }
                $back(['saved' => 1] + ($removed ? ['deleg_removed' => $removed] : []));
            }
            $org = array_merge($org, $d);   // show the submitted values with the errors
            break;

        case 'add_workplace':
        case 'update_workplace':
            [$w, $formErrors] = casReadWorkplaceForm();
            $wkpId = (int)($_POST['wkp_id'] ?? 0);
            if ($action === 'update_workplace' && !isset($workplaces[$wkpId])) {
                $back();
            }
            foreach ($workplaces as $id => $existing) {
                if ($id !== $wkpId && mb_strtolower($existing['wkp_name']) === mb_strtolower($w['wkp_name'])) {
                    $formErrors[] = 'Ya existe un centro de trabajo con ese nombre en esta empresa.';
                }
            }
            if (!$formErrors) {
                if ($action === 'add_workplace') {
                    $gDb->queryPrepared(
                        'INSERT INTO ' . CAS_TBL_WORKPLACES . ' (wkp_uuid, wkp_org_id, wkp_name, wkp_calles, wkp_cp,
                                wkp_localidad, wkp_provincia, wkp_telefono, wkp_notas, wkp_usr_id_create)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [casUuid(), $orgId, $w['wkp_name'], $w['wkp_calles'], $w['wkp_cp'], $w['wkp_localidad'],
                         $w['wkp_provincia'], $w['wkp_telefono'], $w['wkp_notas'], (int)$gCurrentUserId]
                    );
                } else {
                    $gDb->queryPrepared(
                        'UPDATE ' . CAS_TBL_WORKPLACES . ' SET wkp_name = ?, wkp_calles = ?, wkp_cp = ?, wkp_localidad = ?,
                                wkp_provincia = ?, wkp_telefono = ?, wkp_notas = ?
                          WHERE wkp_id = ? AND wkp_org_id = ?',
                        [$w['wkp_name'], $w['wkp_calles'], $w['wkp_cp'], $w['wkp_localidad'], $w['wkp_provincia'],
                         $w['wkp_telefono'], $w['wkp_notas'], $wkpId, $orgId]
                    );
                }
                $back();
            }
            break;

        case 'delete_workplace':
            // members of the workplace stay in the company, without workplace (FK ON DELETE SET NULL)
            $gDb->queryPrepared(
                'DELETE FROM ' . CAS_TBL_WORKPLACES . ' WHERE wkp_id = ? AND wkp_org_id = ?',
                [(int)($_POST['wkp_id'] ?? 0), $orgId]
            );
            $back();
            break;

        case 'add_member':
            $usrId = (int)($_POST['usr_id'] ?? 0);
            if (isset($users[$usrId])) {
                $gDb->queryPrepared(
                    'INSERT INTO ' . CAS_TBL_MEMBERS . '
                            (mro_uuid, mro_usr_id, mro_org_id, mro_role, mro_wkp_id, mro_delegado,
                             mro_active, mro_usr_id_create, mro_timestamp_create)
                     VALUES (?, ?, ?, ?, ?, ?, 1, ?, NOW())
                     ON DUPLICATE KEY UPDATE mro_active = 1, mro_role = VALUES(mro_role),
                                             mro_wkp_id = VALUES(mro_wkp_id), mro_delegado = VALUES(mro_delegado)',
                    [casUuid(), $usrId, $orgId, mb_substr(trim((string)($_POST['mro_role'] ?? '')), 0, 100),
                     $postWkp(), $postDelegado(), (int)$gCurrentUserId]
                );
            }
            $back();
            break;

        case 'update_member':
            $gDb->queryPrepared(
                'UPDATE ' . CAS_TBL_MEMBERS . ' SET mro_role = ?, mro_wkp_id = ?, mro_delegado = ?
                  WHERE mro_id = ? AND mro_org_id = ?',
                [mb_substr(trim((string)($_POST['mro_role'] ?? '')), 0, 100), $postWkp(),
                 $postDelegado(), (int)($_POST['mro_id'] ?? 0), $orgId]
            );
            $back();
            break;

        case 'remove_member':
            $gDb->queryPrepared(
                'DELETE FROM ' . CAS_TBL_MEMBERS . ' WHERE mro_id = ? AND mro_org_id = ?',
                [(int)($_POST['mro_id'] ?? 0), $orgId]
            );
            $back();
            break;

        default:
            $back();
    }
}

// ---------------------------------------------------------------- data for the page
[$fnId, $lnId] = casNameFieldIds();
$members = $gDb->queryPrepared(
    'SELECT m.mro_id, m.mro_usr_id, m.mro_role, m.mro_wkp_id, m.mro_delegado, u.usr_uuid,
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

$delegates = array_values(array_filter($members, static fn($m) => (int)$m['mro_delegado'] === 1 && $hasSection));

$perWorkplace = [];
foreach ($members as $m) {
    $perWorkplace[(int)$m['mro_wkp_id']] = ($perWorkplace[(int)$m['mro_wkp_id']] ?? 0) + 1;
}

// filter by workplace: ?wkp=ID, ?wkp=0 = without workplace
$filterWkp = isset($_GET['wkp']) && $_GET['wkp'] !== '' ? (int)$_GET['wkp'] : null;
$shownMembers = $filterWkp === null ? $members
    : array_values(array_filter($members, static fn($m) => (int)$m['mro_wkp_id'] === $filterWkp));

// roles and payment data: secretariat only
$userRoles = [];
$sepa = ['enabled' => false, 'amounts' => [], 'methods' => []];
if ($canEdit && $memberIds) {
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
    $sepa = casSepaInfo($memberIds);
}

$gNavigation->addUrl(CURRENT_URL, $org['org_name']);
$page = \Admidio\UI\Presenter\PagePresenter::withHtmlIDAndHeadline('companies-and-sections-profile', e($org['org_name']));
$page->addHtml('
<style>
:root { --cnt-red:#DA291C; --cnt-black:#4a4a4a; --cnt-black-light:#2d2d2d; --cnt-green:#198754; }
.btn-save { background:var(--cnt-red); border-color:var(--cnt-red); color:#fff; } .btn-save:hover { background:#c41e3a; color:#fff; }
.card-header-custom { background:var(--cnt-black-light); color:#fff; font-weight:600; }
.crm-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:1rem; }
.crm-grid .span-all { grid-column:1 / -1; }
@media (max-width:1199px) { .crm-grid { grid-template-columns:1fr; } }
.members-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:1rem; align-items:start; }
@media (max-width:991px) { .members-grid { grid-template-columns:1fr; } }
.crm-member-card { border-left:5px solid var(--cnt-red); }
.crm-member-card .card-header { background:var(--cnt-black-light); color:#fff; }
.badge-large { padding:6px 12px; font-size:.85rem; margin:0 4px 4px 0; }
.btn-remove { background:var(--cnt-red); border:none; color:#fff; border-radius:50%; width:30px; height:30px; display:inline-flex; align-items:center; justify-content:center; padding:0; }
.btn-remove:hover { background:#c41e3a; color:#fff; }
#seccion_fields.hidden { display:none; }
.wkp-row { border-bottom:1px solid #eee; padding:.5rem 0; }
.wkp-row:last-child { border-bottom:none; }
details > summary { cursor:pointer; }
</style>');
$page->addJavascript('
    var cb = document.getElementById("org_tiene_seccion"), f = document.getElementById("seccion_fields");
    if (cb && f) { var upd = function () { f.classList.toggle("hidden", !cb.checked); }; cb.addEventListener("change", upd); upd(); }
    var of = document.getElementById("org_form");
    if (of && cb) {
        of.addEventListener("submit", function (ev) {
            var n = parseInt(of.getAttribute("data-delegados") || "0", 10);
            if (!cb.checked && n > 0 && !confirm("Esta empresa dejará de tener sección sindical. Se retirará el cargo a " + n + " delegado/a(s). ¿Continuar?")) {
                ev.preventDefault();
            }
        });
    }
', true);

$selfUrl = 'organization_profile.php?org_id=' . $orgId;
$wkpOptions = static function (?int $selected) use ($workplaces): string {
    $html = '<option value="">Sin centro asignado</option>';
    foreach ($workplaces as $id => $w) {
        $html .= '<option value="' . $id . '"' . ($selected === $id ? ' selected' : '') . '>' . e($w['wkp_name']) . '</option>';
    }
    return $html;
};

ob_start();
?>
<div class="mb-3">
    <a href="organizations.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Volver a empresas</a>
    <?php if (!$canEdit): ?><span class="badge bg-secondary ms-2">Solo lectura</span><?php endif; ?>
</div>

<?php if (isset($_GET['saved'])): ?><div class="alert alert-success">Cambios guardados.<?php if ((int)($_GET['deleg_removed'] ?? 0) > 0): ?> Al quitar la sección sindical se ha retirado el cargo a <?php echo (int)$_GET['deleg_removed']; ?> delegado/a(s).<?php endif; ?></div><?php endif; ?>
<?php if ($formErrors): ?><div class="alert alert-danger"><?php echo implode('<br>', array_map('e', $formErrors)); ?></div><?php endif; ?>

<div class="crm-grid">
    <form method="POST" action="<?php echo e($selfUrl); ?>" style="display:contents" id="org_form" data-delegados="<?php echo count($delegates); ?>">
        <?php echo casCsrfField(); ?>
        <input type="hidden" name="action" value="update_org">
        <fieldset style="display:contents" <?php echo $canEdit ? '' : 'disabled'; ?>>

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
                <?php if ($canEdit): ?><button type="submit" class="btn btn-save mt-3"><i class="bi bi-save"></i> Guardar cambios</button><?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header card-header-custom"><i class="bi bi-geo-alt"></i> Dirección (sede)</div>
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
                    <div class="mb-2"><span class="form-label fw-bold small d-block">Delegados/as</span>
                        <?php if ($delegates): ?>
                            <?php foreach ($delegates as $dm): ?><span class="badge bg-danger badge-large"><i class="bi bi-megaphone"></i> <?php echo e(casUserLabel($dm)); ?></span><?php endforeach; ?>
                        <?php elseif ($hasSection): ?>
                            <span class="text-muted small">Sin delegados/as.</span>
                        <?php endif; ?>
                        <?php if ($canEdit): ?><div class="form-text">Se nombran en <a href="#miembros">Miembros</a>, marcando <em>Delegado/a</em> en la asignación<?php echo $hasSection ? '' : ' (guarda primero la sección)'; ?>.</div><?php endif; ?>
                    </div>
                </div>
                <?php if (!$hasSection): ?>
                <p class="text-muted small mb-0" id="no_section_note"><i class="bi bi-info-circle"></i> Sin sección sindical no hay delegados/as. Las personas asignadas siguen apareciendo como afiliadas a esta empresa.</p>
                <?php endif; ?>
            </div>
        </div>
        </fieldset>
    </form>

    <!-- ===================== Workplaces ===================== -->
    <div class="card span-all">
        <div class="card-header card-header-custom d-flex justify-content-between align-items-center">
            <span><i class="bi bi-geo"></i> Centros de trabajo (<?php echo count($workplaces); ?>)</span>
        </div>
        <div class="card-body">
            <?php if (!$workplaces): ?>
            <p class="text-muted mb-3">Esta empresa no tiene centros de trabajo registrados.</p>
            <?php endif; ?>

            <?php foreach ($workplaces as $id => $w):
                $addr = implode(', ', array_filter([$w['wkp_calles'], trim($w['wkp_cp'] . ' ' . $w['wkp_localidad']), $w['wkp_provincia']]));
            ?>
            <div class="wkp-row">
                <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                    <div>
                        <a class="fw-bold" href="<?php echo e($selfUrl . '&wkp=' . $id); ?>#miembros"><i class="bi bi-geo-alt"></i> <?php echo e($w['wkp_name']); ?></a>
                        <span class="badge bg-dark ms-1"><?php echo (int)($perWorkplace[$id] ?? 0); ?> miembros</span>
                        <?php if ($addr !== ''): ?><div class="small text-muted"><?php echo e($addr); ?></div><?php endif; ?>
                        <?php if (!empty($w['wkp_telefono'])): ?><div class="small"><i class="bi bi-telephone"></i> <?php echo e($w['wkp_telefono']); ?></div><?php endif; ?>
                        <?php if (!empty($w['wkp_notas'])): ?><div class="small fst-italic"><?php echo nl2br(e($w['wkp_notas'])); ?></div><?php endif; ?>
                    </div>
                    <?php if ($canEdit): ?>
                    <form method="POST" action="<?php echo e($selfUrl); ?>" onsubmit="return confirm('¿Eliminar este centro? Sus miembros seguirán en la empresa, sin centro.');">
                        <?php echo casCsrfField(); ?>
                        <input type="hidden" name="action" value="delete_workplace">
                        <input type="hidden" name="wkp_id" value="<?php echo $id; ?>">
                        <button type="submit" class="btn-remove" title="Eliminar centro"><i class="bi bi-trash"></i></button>
                    </form>
                    <?php endif; ?>
                </div>
                <?php if ($canEdit): ?>
                <details class="mt-2"><summary class="small">Editar centro</summary>
                    <form method="POST" action="<?php echo e($selfUrl); ?>" class="row g-2 mt-1">
                        <?php echo casCsrfField(); ?>
                        <input type="hidden" name="action" value="update_workplace">
                        <input type="hidden" name="wkp_id" value="<?php echo $id; ?>">
                        <div class="col-md-3"><input type="text" name="wkp_name" maxlength="100" class="form-control form-control-sm" required placeholder="Nombre" value="<?php echo e($w['wkp_name']); ?>"></div>
                        <div class="col-md-3"><input type="text" name="wkp_calles" maxlength="255" class="form-control form-control-sm" placeholder="Calle" value="<?php echo e($w['wkp_calles']); ?>"></div>
                        <div class="col-md-1"><input type="text" name="wkp_cp" maxlength="10" class="form-control form-control-sm" placeholder="CP" value="<?php echo e($w['wkp_cp']); ?>"></div>
                        <div class="col-md-2"><input type="text" name="wkp_localidad" maxlength="100" class="form-control form-control-sm" placeholder="Localidad" value="<?php echo e($w['wkp_localidad']); ?>"></div>
                        <div class="col-md-2"><input type="text" name="wkp_provincia" maxlength="100" class="form-control form-control-sm" placeholder="Provincia" value="<?php echo e($w['wkp_provincia']); ?>"></div>
                        <div class="col-md-1"><input type="tel" name="wkp_telefono" maxlength="20" class="form-control form-control-sm" placeholder="Teléfono" value="<?php echo e($w['wkp_telefono']); ?>"></div>
                        <div class="col-md-10"><input type="text" name="wkp_notas" maxlength="2000" class="form-control form-control-sm" placeholder="Notas (turnos, horario, persona de contacto...)" value="<?php echo e($w['wkp_notas']); ?>"></div>
                        <div class="col-md-2"><button type="submit" class="btn btn-save btn-sm w-100">Guardar</button></div>
                    </form>
                </details>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>

            <?php if ($canEdit): ?>
            <details class="mt-3" <?php echo $workplaces ? '' : 'open'; ?>><summary class="fw-bold"><i class="bi bi-plus-circle"></i> Añadir centro de trabajo</summary>
                <form method="POST" action="<?php echo e($selfUrl); ?>" class="row g-2 mt-1">
                    <?php echo casCsrfField(); ?>
                    <input type="hidden" name="action" value="add_workplace">
                    <div class="col-md-3"><input type="text" name="wkp_name" maxlength="100" class="form-control form-control-sm" required placeholder="Nombre (p. ej. Centro Aeropuerto)"></div>
                    <div class="col-md-3"><input type="text" name="wkp_calles" maxlength="255" class="form-control form-control-sm" placeholder="Calle"></div>
                    <div class="col-md-1"><input type="text" name="wkp_cp" maxlength="10" class="form-control form-control-sm" placeholder="CP"></div>
                    <div class="col-md-2"><input type="text" name="wkp_localidad" maxlength="100" class="form-control form-control-sm" placeholder="Localidad"></div>
                    <div class="col-md-2"><input type="text" name="wkp_provincia" maxlength="100" class="form-control form-control-sm" placeholder="Provincia"></div>
                    <div class="col-md-1"><input type="tel" name="wkp_telefono" maxlength="20" class="form-control form-control-sm" placeholder="Teléfono"></div>
                    <div class="col-md-10"><input type="text" name="wkp_notas" maxlength="2000" class="form-control form-control-sm" placeholder="Notas (turnos, horario, persona de contacto...)"></div>
                    <div class="col-md-2"><button type="submit" class="btn btn-success btn-sm w-100">Añadir</button></div>
                </form>
            </details>
            <?php endif; ?>
        </div>
    </div>

    <!-- ===================== Members ===================== -->
    <div class="card span-all" id="miembros">
        <div class="card-header card-header-custom d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span><i class="bi bi-people"></i> Miembros (<?php echo count($members); ?>)</span>
            <?php if ($workplaces): ?>
            <form method="GET" action="organization_profile.php" class="d-flex gap-2 align-items-center">
                <input type="hidden" name="org_id" value="<?php echo $orgId; ?>">
                <label class="small mb-0" for="wkp_filter">Centro:</label>
                <select id="wkp_filter" name="wkp" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="" <?php echo $filterWkp === null ? 'selected' : ''; ?>>Todos</option>
                    <?php foreach ($workplaces as $id => $w): ?>
                    <option value="<?php echo $id; ?>" <?php echo $filterWkp === $id ? 'selected' : ''; ?>><?php echo e($w['wkp_name']); ?> (<?php echo (int)($perWorkplace[$id] ?? 0); ?>)</option>
                    <?php endforeach; ?>
                    <option value="0" <?php echo $filterWkp === 0 ? 'selected' : ''; ?>>Sin centro (<?php echo (int)($perWorkplace[0] ?? 0); ?>)</option>
                </select>
            </form>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if ($canEdit): ?>
            <form method="POST" action="<?php echo e($selfUrl); ?>" class="mb-4">
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
                        <label class="form-label small fw-bold" for="mro_wkp_id">Centro de trabajo</label>
                        <select id="mro_wkp_id" name="mro_wkp_id" class="form-select"><?php echo $wkpOptions(null); ?></select>
                    </div>
                    <div>
                        <label class="form-label small fw-bold" for="mro_role">Cargo</label>
                        <input type="text" id="mro_role" name="mro_role" maxlength="100" class="form-control" placeholder="Cargo" style="width:160px;">
                    </div>
                    <?php if ($hasSection): ?>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="mro_delegado" name="mro_delegado" value="1">
                        <label class="form-check-label small fw-bold" for="mro_delegado">Delegado/a</label>
                    </div>
                    <?php endif; ?>
                    <div><button type="submit" class="btn btn-success">Asignar</button></div>
                </div>
                <div class="form-text"><?php echo $hasSection
                    ? 'Los delegados/as pueden ver esta empresa (solo lectura).'
                    : 'Esta empresa no tiene sección sindical: no se pueden nombrar delegados/as.'; ?></div>
            </form>
            <?php endif; ?>

            <?php if (!$shownMembers): ?>
            <p class="text-muted mt-4 ms-3">No hay miembros<?php echo $filterWkp !== null ? ' en este centro' : ' asignados a esta empresa'; ?>.</p>
            <?php else: ?>
            <div class="members-grid mt-2">
                <?php foreach ($shownMembers as $m):
                    $uid = (int)$m['mro_usr_id'];
                    $wid = (int)$m['mro_wkp_id'];
                    $pm = $sepa['methods'][$uid] ?? '';
                    $amount = $sepa['amounts'][$uid] ?? null;
                ?>
                <div class="crm-member-card card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <a class="fw-bold text-white" href="<?php echo e(ADMIDIO_URL . FOLDER_MODULES . '/profile/profile.php?user_uuid=' . rawurlencode($m['usr_uuid'])); ?>"><?php echo e(casUserLabel($m)); ?></a>
                        <?php if ($canEdit): ?>
                        <form method="POST" action="<?php echo e($selfUrl); ?>" class="mb-0" onsubmit="return confirm('¿Quitar a este miembro de la empresa?');">
                            <?php echo casCsrfField(); ?>
                            <input type="hidden" name="action" value="remove_member">
                            <input type="hidden" name="mro_id" value="<?php echo (int)$m['mro_id']; ?>">
                            <button type="submit" class="btn-remove" title="Quitar"><i class="bi bi-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <div class="card-body d-flex flex-column gap-2">
                        <div>
                            <span class="badge bg-secondary badge-large"><i class="bi bi-geo-alt"></i> <?php echo e($workplaces[$wid]['wkp_name'] ?? 'Sin centro'); ?></span>
                            <?php if ($hasSection && (int)$m['mro_delegado'] === 1): ?><span class="badge bg-danger badge-large"><i class="bi bi-megaphone"></i> Delegado/a</span><?php endif; ?>
                            <?php if (!empty($m['mro_role'])): ?><span class="badge bg-dark badge-large"><?php echo e($m['mro_role']); ?></span><?php endif; ?>
                        </div>

                        <?php if ($canEdit && !empty($userRoles[$uid])): ?>
                        <div><small class="text-muted fw-bold text-uppercase">Roles</small>
                            <div class="mt-1"><?php foreach ($userRoles[$uid] as $r): ?><span class="badge bg-light text-dark border badge-large"><?php echo e($r); ?></span><?php endforeach; ?></div></div>
                        <?php endif; ?>

                        <?php if ($canEdit && $pm !== ''): ?>
                        <div><small class="text-muted fw-bold text-uppercase">Forma de pago</small>
                            <div class="mt-1"><span class="badge bg-success badge-large"><?php echo e($pm); ?></span>
                            <?php if ($amount !== null): ?><span class="badge bg-success badge-large">Descuento en nómina: <?php echo e(number_format((float)$amount, 2, ',', '.')); ?> €/mes</span><?php endif; ?></div></div>
                        <?php endif; ?>

                        <?php if ($canEdit): ?>
                        <details><summary class="small">Editar asignación</summary>
                            <form method="POST" action="<?php echo e($selfUrl); ?>" class="mt-2 d-flex flex-column gap-2">
                                <?php echo casCsrfField(); ?>
                                <input type="hidden" name="action" value="update_member">
                                <input type="hidden" name="mro_id" value="<?php echo (int)$m['mro_id']; ?>">
                                <select name="mro_wkp_id" class="form-select form-select-sm"><?php echo $wkpOptions($wid ?: null); ?></select>
                                <input type="text" name="mro_role" maxlength="100" class="form-control form-control-sm" placeholder="Cargo" value="<?php echo e($m['mro_role']); ?>">
                                <?php if ($hasSection): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="del_<?php echo (int)$m['mro_id']; ?>" name="mro_delegado" value="1" <?php echo (int)$m['mro_delegado'] === 1 ? 'checked' : ''; ?>>
                                    <label class="form-check-label small" for="del_<?php echo (int)$m['mro_id']; ?>">Delegado/a (ve esta empresa)</label>
                                </div>
                                <?php endif; ?>
                                <button type="submit" class="btn btn-save btn-sm">Guardar</button>
                            </form>
                        </details>
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
