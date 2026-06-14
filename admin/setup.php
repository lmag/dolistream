<?php
/* Copyright (C) 2024  Eoxia <technique@eoxia.com>
 *
 * This program is free software under GNU GPL v3+
 */

/**
 * \file    htdocs/custom/dolistream/admin/setup.php
 * \ingroup dolistream
 * \brief   DoliStream — setup page
 */

// ── Bootstrap Dolibarr ───────────────────────────────────────────────────────
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include str_replace('..', '', $_SERVER["CONTEXT_DOCUMENT_ROOT"]) . "/main.inc.php";
}
$tmp  = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i    = strlen($tmp) - 1;
$j    = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1)) . "/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1)) . "/main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

// ── Bibliothèques ────────────────────────────────────────────────────────────
require_once DOL_DOCUMENT_ROOT . "/core/lib/admin.lib.php";
require_once '../lib/dolistream.lib.php';

// ── Sécurité ─────────────────────────────────────────────────────────────────
if (!$user->admin) {
	accessforbidden();
}

// ── Traductions ───────────────────────────────────────────────────────────────
$langs->loadLangs(array('admin', 'dolistream@dolistream'));

// ── Hooks ────────────────────────────────────────────────────────────────────
$hookmanager->initHooks(array('dolistreamsetup', 'globalsetup'));

	$action     = GETPOST('action', 'aZ09');
	$backtopage = GETPOST('backtopage', 'alpha');

	include DOL_DOCUMENT_ROOT . '/core/actions_setmoduleoptions.inc.php';

	$log_filename = !empty($conf->global->DOLISTREAM_LOG_FILE) ? $conf->global->DOLISTREAM_LOG_FILE : 'dolistream.log';
	$log_filepath = DOL_DATA_ROOT . '/' . $log_filename;

	if ($action === 'set_log_file') {
		$new_log = GETPOST('log_file', 'alpha');
		if (!empty($new_log)) {
			dolibarr_set_const($db, 'DOLISTREAM_LOG_FILE', $new_log, 'chaine', 0, '', $conf->entity);
			$log_filename = $new_log;
			$log_filepath = DOL_DATA_ROOT . '/' . $log_filename;
			setEventMessages($langs->trans("RecordSaved"), null, 'mesgs');
		}
	} elseif ($action === 'empty_log') {
		if (file_exists($log_filepath)) {
			file_put_contents($log_filepath, '');
			setEventMessages("Fichier de log vidé.", null, 'mesgs');
		}
	} elseif ($action === 'download_log') {
		if (file_exists($log_filepath)) {
			header('Content-Description: File Transfer');
			header('Content-Type: text/plain');
			header('Content-Disposition: attachment; filename="' . basename($log_filepath) . '"');
			header('Expires: 0');
			header('Cache-Control: must-revalidate');
			header('Pragma: public');
			header('Content-Length: ' . filesize($log_filepath));
			readfile($log_filepath);
			exit;
		} else {
			setEventMessages("Le fichier de log n'existe pas.", null, 'errors');
		}
	}

	/*
	 * View
	 */

$title = $langs->trans('DoliStreamSetup');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-dolistream page-admin');

$linkback = '<a href="' . ($backtopage ?: DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1') . '">'
	. img_picto($langs->trans('BackToModuleList'), 'back', 'class="pictofixedwidth"')
	. '<span class="hideonsmartphone">' . $langs->trans('BackToModuleList') . '</span></a>';

print load_fiche_titre($langs->trans('DoliStream'), $linkback, 'title_setup');

$head = dolinstreamAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans('DoliStream'), -1, 'technic');

print '<div class="warning">';
print '<strong>⚠ ' . $langs->trans('DoliStreamWarningDevOnly') . '</strong>';
print '</div><br>';

print '<span class="opacitymedium">' . $langs->trans('DoliStreamSetupDesc') . '</span><br><br>';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>' . $langs->trans('Parameter') . '</td><td>' . $langs->trans('Value') . '</td></tr>';
print '<tr class="oddeven"><td>' . $langs->trans('Version') . '</td><td><strong>1.0.0</strong></td></tr>';
print '<tr class="oddeven"><td>' . $langs->trans('Access') . '</td><td>' . $langs->trans('AdminOnly') . '</td></tr>';
print '<tr class="oddeven"><td>' . $langs->trans('MainPage') . '</td>';
print '<td><a class="butAction" href="' . DOL_URL_ROOT . '/custom/dolistream/view/index.php" style="padding:4px 10px;">';
print '▶ ' . $langs->trans('DoliStream') . '</a></td></tr>';
print '</table><br><br>';

// Section Log
print load_fiche_titre("Log de la console", '', '');
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">Fichier de log système</td></tr>';

print '<tr class="oddeven"><td>Nom du fichier (dans ' . DOL_DATA_ROOT . '/)</td><td>';
print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="set_log_file">';
print '<input type="text" name="log_file" value="' . htmlspecialchars($log_filename) . '" class="flat minwidth200" style="margin-right:8px;">';
print '<input type="submit" class="button" value="' . $langs->trans('Modify') . '">';
print '</form>';
print '</td></tr>';

$size = file_exists($log_filepath) ? filesize($log_filepath) : 0;
$size_text = $size > 1048576 ? round($size / 1048576, 2) . ' Mo' : ($size > 1024 ? round($size / 1024, 2) . ' Ko' : $size . ' octets');

print '<tr class="oddeven"><td>Taille du fichier</td><td><strong>' . $size_text . '</strong></td></tr>';

print '<tr class="oddeven"><td>Actions</td><td>';
if (file_exists($log_filepath)) {
	print '<a class="butAction" href="' . $_SERVER['PHP_SELF'] . '?action=download_log&token=' . newToken() . '">Télécharger le log</a>';
	print '<a class="butActionDelete" href="' . $_SERVER['PHP_SELF'] . '?action=empty_log&token=' . newToken() . '" onclick="return confirm(\'Voulez-vous vraiment vider le fichier de log ?\');">Vider le log</a>';
} else {
	print '<span class="opacitymedium">Le fichier n\'existe pas encore.</span>';
}
print '</td></tr>';
print '</table>';

print dol_get_fiche_end();

llxFooter();
$db->close();
