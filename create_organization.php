<?php
/**
 * Companies and Sections Plugin - Create company
 *
 * POST ACTION (CSRF protected):
 * - create_org: validates the form and inserts the company
 *
 * @package CompaniesAndSections
 */

require_once(__DIR__ . '/organization_functions.php');

casRequireAuth();
casRequireEdit();

$headline = 'Nueva empresa';
$gNavigation->addUrl(CURRENT_URL, $headline);

$formErrors = [];
$d = [
    'org_name' => '', 'org_nombre_comercial' => '', 'org_razon_social' => '', 'org_cif' => '',
    'org_forma_juridica' => '', 'org_sector' => '', 'org_description' => '', 'org_email' => '',
    'org_telefono' => '', 'org_web' => '', 'org_tiene_seccion' => 0, 'org_nombre_seccion' => '',
    'org_email_seccion' => '', 'org_calles' => '', 'org_cp' => '',
    'org_localidad' => '', 'org_provincia' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_org') {
    casCheckCsrf();
    [$d, $formErrors] = casReadOrgForm();
    if (!$formErrors) {
        $gDb->queryPrepared(
            'INSERT INTO ' . CAS_TBL_ORGS . ' (
                org_uuid, org_name, org_nombre_comercial, org_razon_social, org_cif, org_forma_juridica,
                org_sector, org_description, org_email, org_telefono, org_web, org_tiene_seccion,
                org_nombre_seccion, org_email_seccion, org_calles, org_cp,
                org_localidad, org_provincia, org_active, org_usr_id_create
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)',
            [
                casUuid(), $d['org_name'], $d['org_nombre_comercial'], $d['org_razon_social'], $d['org_cif'],
                $d['org_forma_juridica'], $d['org_sector'], $d['org_description'], $d['org_email'],
                $d['org_telefono'], $d['org_web'], $d['org_tiene_seccion'], $d['org_nombre_seccion'],
                $d['org_email_seccion'], $d['org_calles'], $d['org_cp'],
                $d['org_localidad'], $d['org_provincia'], (int)$gCurrentUserId,
            ]
        );
        casRedirect('organizations.php', ['created' => 1]);
    }
}

$formasJuridicas = ['SL', 'SA', 'SLU', 'Cooperativa', 'Autónomo', 'Asociación', 'Fundación', 'Administración pública', 'Otra'];

$page = \Admidio\UI\Presenter\PagePresenter::withHtmlIDAndHeadline('companies-and-sections-create', $headline);
$page->addHtml('
<style>
:root { --cnt-red:#DA291C; --cnt-black:#4a4a4a; }
.btn-create { background:var(--cnt-red); border:none; color:#fff; } .btn-create:hover { background:#c41e3a; color:#fff; }
.form-section-title { color:var(--cnt-red); font-weight:700; border-bottom:2px solid var(--cnt-red); padding-bottom:5px; margin-bottom:15px; }
.form-label-custom { font-weight:600; color:var(--cnt-black); }
#seccion_fields.hidden { display:none; }
</style>');
$page->addJavascript('
    var cb = document.getElementById("org_tiene_seccion"), f = document.getElementById("seccion_fields");
    var upd = function () { f.classList.toggle("hidden", !cb.checked); };
    cb.addEventListener("change", upd); upd();
', true);

ob_start();
?>
<div class="row mb-5">
    <div class="col-lg-10">
        <?php if ($formErrors): ?>
        <div class="alert alert-danger"><?php echo implode('<br>', array_map('e', $formErrors)); ?></div>
        <?php endif; ?>

        <form method="POST" action="create_organization.php" class="row g-4">
            <?php echo casCsrfField(); ?>
            <input type="hidden" name="action" value="create_org">

            <div class="col-12"><h5 class="form-section-title"><i class="bi bi-building"></i> Datos generales</h5></div>
            <div class="col-md-6"><label class="form-label form-label-custom">Nombre de la empresa *</label>
                <input type="text" name="org_name" maxlength="100" class="form-control" required value="<?php echo e($d['org_name']); ?>"></div>
            <div class="col-md-6"><label class="form-label form-label-custom">Nombre comercial</label>
                <input type="text" name="org_nombre_comercial" maxlength="100" class="form-control" value="<?php echo e($d['org_nombre_comercial']); ?>"></div>
            <div class="col-md-6"><label class="form-label form-label-custom">Razón social</label>
                <input type="text" name="org_razon_social" maxlength="100" class="form-control" value="<?php echo e($d['org_razon_social']); ?>"></div>
            <div class="col-md-3"><label class="form-label form-label-custom">CIF/NIF</label>
                <input type="text" name="org_cif" maxlength="20" class="form-control" style="text-transform:uppercase;" value="<?php echo e($d['org_cif']); ?>"></div>
            <div class="col-md-3"><label class="form-label form-label-custom">Forma jurídica</label>
                <select name="org_forma_juridica" class="form-select">
                    <option value="">Seleccionar...</option>
                    <?php foreach ($formasJuridicas as $fj): ?>
                    <option value="<?php echo e($fj); ?>" <?php echo $d['org_forma_juridica'] === $fj ? 'selected' : ''; ?>><?php echo e($fj); ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="col-md-6"><label class="form-label form-label-custom">Sector</label>
                <input type="text" name="org_sector" maxlength="100" class="form-control" placeholder="Ej.: hostelería, limpieza, logística..." value="<?php echo e($d['org_sector']); ?>"></div>
            <div class="col-md-6"><label class="form-label form-label-custom">Correo electrónico</label>
                <input type="email" name="org_email" maxlength="100" class="form-control" value="<?php echo e($d['org_email']); ?>"></div>
            <div class="col-md-4"><label class="form-label form-label-custom">Teléfono</label>
                <input type="tel" name="org_telefono" maxlength="20" class="form-control" value="<?php echo e($d['org_telefono']); ?>"></div>
            <div class="col-md-4"><label class="form-label form-label-custom">Web</label>
                <input type="url" name="org_web" maxlength="255" class="form-control" placeholder="https://..." value="<?php echo e($d['org_web']); ?>"></div>
            <div class="col-12"><label class="form-label form-label-custom">Descripción</label>
                <textarea name="org_description" class="form-control" rows="2"><?php echo e($d['org_description']); ?></textarea></div>

            <div class="col-12 mt-5"><h5 class="form-section-title"><i class="bi bi-people"></i> Sección sindical</h5></div>
            <div class="col-md-4"><div class="form-check">
                <input class="form-check-input" type="checkbox" id="org_tiene_seccion" name="org_tiene_seccion" value="1" <?php echo $d['org_tiene_seccion'] ? 'checked' : ''; ?>>
                <label class="form-check-label fw-bold" for="org_tiene_seccion">¿Tiene sección sindical?</label></div></div>
            <div id="seccion_fields" class="col-12"><div class="row">
                <div class="col-md-4"><label class="form-label form-label-custom">Nombre de la sección</label>
                    <input type="text" name="org_nombre_seccion" maxlength="100" class="form-control" value="<?php echo e($d['org_nombre_seccion']); ?>"></div>
                <div class="col-md-4"><label class="form-label form-label-custom">Correo de la sección</label>
                    <input type="email" name="org_email_seccion" maxlength="100" class="form-control" value="<?php echo e($d['org_email_seccion']); ?>"></div>
                <div class="col-12 form-text">Los delegados/as se nombran después, en la ficha de la empresa, al asignar a las personas afiliadas.</div>
            </div></div>

            <div class="col-12 mt-5"><h5 class="form-section-title"><i class="bi bi-geo-alt"></i> Dirección</h5></div>
            <div class="col-md-6"><label class="form-label form-label-custom">Calle</label>
                <input type="text" name="org_calles" maxlength="255" class="form-control" value="<?php echo e($d['org_calles']); ?>"></div>
            <div class="col-md-2"><label class="form-label form-label-custom">Código postal</label>
                <input type="text" name="org_cp" maxlength="10" class="form-control" value="<?php echo e($d['org_cp']); ?>"></div>
            <div class="col-md-4"><label class="form-label form-label-custom">Localidad</label>
                <input type="text" name="org_localidad" maxlength="100" class="form-control" value="<?php echo e($d['org_localidad']); ?>"></div>
            <div class="col-md-4"><label class="form-label form-label-custom">Provincia</label>
                <input type="text" name="org_provincia" maxlength="100" class="form-control" value="<?php echo e($d['org_provincia']); ?>"></div>

            <div class="col-12 mt-5">
                <button type="submit" class="btn btn-create"><i class="bi bi-building"></i> Crear empresa</button>
                <a href="organizations.php" class="btn btn-secondary ms-2"><i class="bi bi-x"></i> Cancelar</a>
            </div>
        </form>
    </div>
</div>
<?php
$page->addHtml(ob_get_clean());
$page->show();
