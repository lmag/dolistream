<?php
/* Copyright (C) 2024  Eoxia <technique@eoxia.com>
 * This program is free software under GNU GPL v3+
 *
 * ⚠ DEVELOPMENT MODULE — DO NOT USE IN PRODUCTION
 */

/**
 * \file    htdocs/custom/dolistream/view/index.php
 * \ingroup dolistream
 * \brief   DoliStream — main runner page (generation & purge)
 */

// ── Bootstrap Dolibarr ───────────────────────────────────────────────────────
// Guard : si ajax/run.php a déjà chargé main.inc.php, on l'ignore
if (defined('DOLISTREAM_AJAX_RUN') && isset($db)) {
	$res = 1; // déjà bootstrapé
} else {
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
	if (!$res && file_exists("../../main.inc.php"))    { $res = @include "../../main.inc.php"; }
	if (!$res && file_exists("../../../main.inc.php")) { $res = @include "../../../main.inc.php"; }
	if (!$res) { die("Include of main fails"); }
}

// ── Bibliothèques ────────────────────────────────────────────────────────────
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT . '/contact/class/contact.class.php';
require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT . '/comm/propal/class/propal.class.php';
require_once DOL_DOCUMENT_ROOT . '/projet/class/project.class.php';
require_once DOL_DOCUMENT_ROOT . '/expedition/class/expedition.class.php';
require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.commande.class.php';
require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
if (file_exists(DOL_DOCUMENT_ROOT . '/reception/class/reception.class.php')) {
	require_once DOL_DOCUMENT_ROOT . '/reception/class/reception.class.php';
}
require_once '../lib/dolistream.lib.php';
require_once DOL_DOCUMENT_ROOT . '/comm/action/class/actioncomm.class.php';


// ── Sécurité ─────────────────────────────────────────────────────────────────
if (!isModEnabled('dolistream')) {
	accessforbidden('Module DoliStream is not enabled');
}

global $dolibarr_main_prod;
if (!empty($dolibarr_main_prod)) {
	accessforbidden('DoliStream cannot be used in a production environment (dolibarr_main_prod=1)');
}
if (!$user->admin && !$user->hasRight('dolistream', 'generate', 'run')) {
	accessforbidden();
}

// ── Traductions ───────────────────────────────────────────────────────────────
$langs->loadLangs(array('dolistream@dolistream', 'admin', 'companies', 'products', 'orders', 'bills', 'propal', 'stocks', 'sendings', 'productbatch', 'projects', 'commercial'));

// ── Paramètres ───────────────────────────────────────────────────────────────
$action = GETPOST('action', 'aZ09');
$script = GETPOST('script', 'alpha');
$urlOpt = GETPOST('opt',    'alpha'); // pré-sélection depuis l'URL (ex: opt=supplier)
$page   = max(0, (int) GETPOST('page', 'int')); // pagination des résultats

// ── Progression (fichier temp pour éviter le lock de session) ─────────────────
$dsProgressFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ds_progress_' . session_id() . '.json';

/** Retourne la progression courante en JSON (handler AJAX) */
if ($action === 'progress') {
	header('Content-Type: application/json; charset=utf-8');
	echo file_exists($dsProgressFile)
		? file_get_contents($dsProgressFile)
		: json_encode(['pct' => 0, 'current' => 0, 'total' => 0, 'done' => false]);
	exit;
}

/** Écrit la progression dans un fichier tmp lisible par le handler AJAX */
function dolinstreamProgress(int $current, int $total, bool $done = false): void
{
	global $dsProgressFile;
	$pct = $total > 0 ? (int) round($current / $total * 100) : 0;
	file_put_contents($dsProgressFile, json_encode([
		'pct'     => $pct,
		'current' => $current,
		'total'   => $total,
		'done'    => $done,
	]));
}

// ── Log d'exécution ───────────────────────────────────────────────────────────
$scriptLog = array();

/**
 * Ajoute une ligne de log
 */
$dsLiveLogFile = ''; // rempli quand action=run pour le streaming

/**
 * Ajoute une ligne de log
 */
function dsLog(string $msg, string $level = 'info'): void
{
	global $scriptLog, $dsLiveLogFile;
	$entry = array('level' => $level, 'msg' => $msg, 'time' => date('H:i:s'));
	$scriptLog[] = $entry;
	// Écrit immédiatement dans le fichier live pour le streaming JS
	if ($dsLiveLogFile) {
		file_put_contents($dsLiveLogFile, json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
	}
}

// ═══════════════════════════════════════════════════════════════════════════════

// ── Config DB résultats par script (tableau DB + console log + ActionComm) ───
$dsDbConf = array(
	'generate-thirdparty' => array(
		'table'  => 'societe',
		'head'   => array($langs->transnoentitiesnoconv('ColNameCompany'), $langs->transnoentitiesnoconv('Type'), $langs->transnoentitiesnoconv('CustomerCode'), $langs->transnoentitiesnoconv('ColCreatedAt')),
		'select' => "SELECT s.rowid, s.nom, IF(s.client IN(1,2),'Client',IF(s.fournisseur=1,'Fournisseur','Autre')) AS type, IFNULL(s.code_client,'—') AS cc, DATE_FORMAT(DATE_ADD(s.datec, INTERVAL TIME_TO_SEC(TIMEDIFF(NOW(),UTC_TIMESTAMP())) SECOND),'%d/%m/%Y %H:%i') AS cree_le FROM " . MAIN_DB_PREFIX . "societe s ORDER BY s.rowid DESC LIMIT {NB}",
		'url'    => '/societe/card.php?socid=',
	),
	'generate-product' => array(
		'table'  => 'product',
		'head'   => array($langs->transnoentitiesnoconv('Label'), $langs->transnoentitiesnoconv('ColPriceHT'), $langs->transnoentitiesnoconv('Stock'), $langs->transnoentitiesnoconv('Type'), $langs->transnoentitiesnoconv('Batch'), $langs->transnoentitiesnoconv('Status'), $langs->transnoentitiesnoconv('ColCreatedAt')),
		'select' => "SELECT p.rowid, p.ref, p.label, CONCAT(ROUND(p.price,2),' €') AS prix, CAST(IFNULL(ROUND(SUM(ps.reel),0),0) AS SIGNED) AS stock, IF(p.fk_product_type=0,'Produit','Service') AS type_prod, IF(p.tobatch=0,'Non',IF(p.tobatch=1,'Lot','Serie')) AS lot_serie, IF(p.tosell=1,'En vente','Hors vente') AS statut, DATE_FORMAT(DATE_ADD(p.datec, INTERVAL TIME_TO_SEC(TIMEDIFF(NOW(),UTC_TIMESTAMP())) SECOND),'%d/%m/%Y %H:%i') AS cree_le FROM " . MAIN_DB_PREFIX . "product p LEFT JOIN " . MAIN_DB_PREFIX . "product_stock ps ON ps.fk_product=p.rowid GROUP BY p.rowid ORDER BY p.rowid DESC LIMIT {NB}",
		'url'    => '/product/card.php?id=',
	),
	'generate-invoice' => array(
		'table'  => 'facture',
		'head'   => array($langs->transnoentitiesnoconv('ThirdParty'), $langs->transnoentitiesnoconv('Date'), $langs->transnoentitiesnoconv('AmountTTC'), $langs->transnoentitiesnoconv('Status'), $langs->transnoentitiesnoconv('ColCreatedAt')),
		'select' => "SELECT f.rowid, f.ref, s.nom AS tiers, DATE_FORMAT(f.datef,'%d/%m/%Y') AS date_f, CONCAT(ROUND(f.total_ttc,2),' €') AS ttc, IF(f.paye=1,'Payee',IF(f.fk_statut=1,'Ouverte','Brouillon')) AS statut, DATE_FORMAT(DATE_ADD(f.datec, INTERVAL TIME_TO_SEC(TIMEDIFF(NOW(),UTC_TIMESTAMP())) SECOND),'%d/%m/%Y %H:%i') AS cree_le FROM " . MAIN_DB_PREFIX . "facture f LEFT JOIN " . MAIN_DB_PREFIX . "societe s ON s.rowid=f.fk_soc ORDER BY f.rowid DESC LIMIT {NB}",
		'url'    => '/compta/facture/card.php?id=',
	),
	'generate-order' => array(
		'table'  => 'commande',
		'head'   => array($langs->transnoentitiesnoconv('FieldProductSelector'), $langs->transnoentitiesnoconv('FieldNumberPerOrder'), 'Expédiable', $langs->transnoentitiesnoconv('ThirdParty'), $langs->transnoentitiesnoconv('Date'), $langs->transnoentitiesnoconv('AmountHT'), $langs->transnoentitiesnoconv('Status'), $langs->transnoentitiesnoconv('ColCreatedAt')),
		'select' => "SELECT c.rowid, c.ref, '-' AS selecteur, (SELECT COUNT(*) FROM " . MAIN_DB_PREFIX . "commandedet WHERE fk_commande = c.rowid) AS nb_prod, IF((SELECT COUNT(*) FROM " . MAIN_DB_PREFIX . "commandedet WHERE fk_commande = c.rowid AND product_type = 0) > 0, 'Oui', 'Non') AS expediable, s.nom AS tiers, DATE_FORMAT(c.date_commande,'%d/%m/%Y') AS date_c, CONCAT(ROUND(c.total_ht,2),' €') AS ht, IF(c.fk_statut=1,'Brouillon',IF(c.fk_statut=2,'Validee','Livree')) AS statut, DATE_FORMAT(DATE_ADD(c.date_creation, INTERVAL TIME_TO_SEC(TIMEDIFF(NOW(),UTC_TIMESTAMP())) SECOND),'%d/%m/%Y %H:%i') AS cree_le FROM " . MAIN_DB_PREFIX . "commande c LEFT JOIN " . MAIN_DB_PREFIX . "societe s ON s.rowid=c.fk_soc ORDER BY c.rowid DESC LIMIT {NB}",
		'url'    => '/commande/card.php?id=',
	),
	'generate-proposal' => array(
		'table'  => 'propal',
		'head'   => array($langs->transnoentitiesnoconv('ThirdParty'), $langs->transnoentitiesnoconv('Date'), $langs->transnoentitiesnoconv('AmountHT'), $langs->transnoentitiesnoconv('Status'), $langs->transnoentitiesnoconv('ColCreatedAt')),
		'select' => "SELECT p.rowid, p.ref, s.nom AS tiers, DATE_FORMAT(p.datep,'%d/%m/%Y') AS date_p, CONCAT(ROUND(p.total_ht,2),' €') AS ht, IF(p.fk_statut=0,'Brouillon',IF(p.fk_statut=1,'Ouverte',IF(p.fk_statut=2,'Signee','Clot.'))) AS statut, DATE_FORMAT(DATE_ADD(p.datec, INTERVAL TIME_TO_SEC(TIMEDIFF(NOW(),UTC_TIMESTAMP())) SECOND),'%d/%m/%Y %H:%i') AS cree_le FROM " . MAIN_DB_PREFIX . "propal p LEFT JOIN " . MAIN_DB_PREFIX . "societe s ON s.rowid=p.fk_soc ORDER BY p.rowid DESC LIMIT {NB}",
		'url'    => '/comm/propal/card.php?id=',
	),
	'generate-project' => array(
		'table'  => 'projet',
		'head'   => array($langs->transnoentitiesnoconv('Title'), $langs->transnoentitiesnoconv('ColOppAmount'), $langs->transnoentitiesnoconv('Budget'), $langs->transnoentitiesnoconv('ColCreatedAt')),
		'select' => "SELECT p.rowid, p.ref, p.title, CONCAT(FORMAT(IFNULL(p.opp_amount,0),0),' €') AS opp, CONCAT(FORMAT(IFNULL(p.budget_amount,0),0),' €') AS budget, DATE_FORMAT(DATE_ADD(p.datec, INTERVAL TIME_TO_SEC(TIMEDIFF(NOW(),UTC_TIMESTAMP())) SECOND),'%d/%m/%Y %H:%i') AS cree_le FROM " . MAIN_DB_PREFIX . "projet p ORDER BY p.rowid DESC LIMIT {NB}",
		'url'    => '/projet/card.php?id=',
	),
	'generate-expedition' => array(
		'table'  => 'expedition',
		'head'   => array($langs->transnoentitiesnoconv('ThirdParty'), $langs->transnoentitiesnoconv('DeliveryDate'), $langs->transnoentitiesnoconv('Status'), $langs->transnoentitiesnoconv('ColCreatedAt')),
		'select' => "SELECT e.rowid, e.ref, s.nom AS tiers, IFNULL(DATE_FORMAT(e.date_delivery,'%d/%m/%Y'),'—') AS date_liv, IF(e.fk_statut=0,'Brouillon',IF(e.fk_statut=1,'Validee','Livree')) AS statut, DATE_FORMAT(DATE_ADD(e.date_creation, INTERVAL TIME_TO_SEC(TIMEDIFF(NOW(),UTC_TIMESTAMP())) SECOND),'%d/%m/%Y %H:%i') AS cree_le FROM " . MAIN_DB_PREFIX . "expedition e LEFT JOIN " . MAIN_DB_PREFIX . "societe s ON s.rowid=e.fk_soc ORDER BY e.rowid DESC LIMIT {NB}",
		'url'    => '/expedition/card.php?id=',
	),
	'generate-supplier-order' => array(
		'table'  => 'commande_fournisseur',
		'head'   => array($langs->transnoentitiesnoconv('Supplier'), $langs->transnoentitiesnoconv('Date'), $langs->transnoentitiesnoconv('AmountHT'), $langs->transnoentitiesnoconv('Status'), $langs->transnoentitiesnoconv('ColCreatedAt')),
		'select' => "SELECT c.rowid, c.ref, s.nom AS fourn, DATE_FORMAT(c.date_commande,'%d/%m/%Y') AS date_c, CONCAT(ROUND(c.total_ht,2),' €') AS ht, IF(c.fk_statut=3,'Validee',IF(c.fk_statut=5,'Livree','Autre')) AS statut, DATE_FORMAT(DATE_ADD(c.date_creation, INTERVAL TIME_TO_SEC(TIMEDIFF(NOW(),UTC_TIMESTAMP())) SECOND),'%d/%m/%Y %H:%i') AS cree_le FROM " . MAIN_DB_PREFIX . "commande_fournisseur c LEFT JOIN " . MAIN_DB_PREFIX . "societe s ON s.rowid=c.fk_soc ORDER BY c.rowid DESC LIMIT {NB}",
		'url'    => '/fourn/commande/card.php?id=',
	),
	'generate-reception' => array(
		'table'  => 'reception',
		'head'   => array($langs->transnoentitiesnoconv('Supplier'), $langs->transnoentitiesnoconv('ColReceptionDate'), $langs->transnoentitiesnoconv('Status'), $langs->transnoentitiesnoconv('ColCreatedAt')),
		'select' => "SELECT r.rowid, r.ref, s.nom AS fourn, IFNULL(DATE_FORMAT(r.date_reception,'%d/%m/%Y'),'—') AS date_rec, IF(r.fk_statut=0,'Brouillon','Validee') AS statut, DATE_FORMAT(DATE_ADD(r.date_creation, INTERVAL TIME_TO_SEC(TIMEDIFF(NOW(),UTC_TIMESTAMP())) SECOND),'%d/%m/%Y %H:%i') AS cree_le FROM " . MAIN_DB_PREFIX . "reception r LEFT JOIN " . MAIN_DB_PREFIX . "societe s ON s.rowid=r.fk_soc ORDER BY r.rowid DESC LIMIT {NB}",
		'url'    => '/reception/card.php?id=',
	),
	'generate-supplier-invoice' => array(
		'table'  => 'facture_fourn',
		'head'   => array($langs->transnoentitiesnoconv('Supplier'), $langs->transnoentitiesnoconv('Date'), $langs->transnoentitiesnoconv('AmountTTC'), $langs->transnoentitiesnoconv('Status'), $langs->transnoentitiesnoconv('ColCreatedAt')),
		'select' => "SELECT f.rowid, f.ref, s.nom AS fourn, DATE_FORMAT(f.datef,'%d/%m/%Y') AS date_f, CONCAT(ROUND(f.total_ttc,2),' €') AS ttc, IF(f.paye=1,'Payee',IF(f.fk_statut=1,'Ouverte','Brouillon')) AS statut, DATE_FORMAT(DATE_ADD(f.datec, INTERVAL TIME_TO_SEC(TIMEDIFF(NOW(),UTC_TIMESTAMP())) SECOND),'%d/%m/%Y %H:%i') AS cree_le FROM " . MAIN_DB_PREFIX . "facture_fourn f LEFT JOIN " . MAIN_DB_PREFIX . "societe s ON s.rowid=f.fk_soc ORDER BY f.rowid DESC LIMIT {NB}",
		'url'    => '/fourn/facture/card.php?id=',
	),
	'generate-warehouse' => array(
		'table'  => 'entrepot',
		'head'   => array($langs->transnoentitiesnoconv('Label'), $langs->transnoentitiesnoconv('ColPlace'), $langs->transnoentitiesnoconv('Town'), $langs->transnoentitiesnoconv('ColCreatedAt')),
		'select' => "SELECT e.rowid, e.ref, e.label, IFNULL(e.lieu,'—') AS lieu, IFNULL(e.town,'—') AS town, DATE_FORMAT(DATE_ADD(e.datec, INTERVAL TIME_TO_SEC(TIMEDIFF(NOW(),UTC_TIMESTAMP())) SECOND),'%d/%m/%Y %H:%i') AS cree_le FROM " . MAIN_DB_PREFIX . "entrepot e ORDER BY e.rowid DESC LIMIT {NB}",
		'url'    => '/product/stock/card.php?id=',
	),
	'workflow-opp-cl-pr' => array(
		'table'  => 'propal',
		'head'   => array($langs->transnoentitiesnoconv('ColOpportunity'), $langs->transnoentitiesnoconv('ThirdParty'), $langs->transnoentitiesnoconv('AmountHT'), $langs->transnoentitiesnoconv('Status'), $langs->transnoentitiesnoconv('ColCreatedAt')),
		'select' => "SELECT p.rowid, p.ref, IFNULL(pj.ref,'—') AS opp, IFNULL(s.nom,'—') AS tiers, CONCAT(ROUND(p.total_ht,2),' €') AS ht, IF(p.fk_statut=0,'Brouillon',IF(p.fk_statut=1,'Ouverte',IF(p.fk_statut=2,'Signee','Clot.'))) AS statut, DATE_FORMAT(DATE_ADD(p.datec, INTERVAL TIME_TO_SEC(TIMEDIFF(NOW(),UTC_TIMESTAMP())) SECOND),'%d/%m/%Y %H:%i') AS cree_le FROM " . MAIN_DB_PREFIX . "propal p LEFT JOIN " . MAIN_DB_PREFIX . "societe s ON s.rowid=p.fk_soc LEFT JOIN " . MAIN_DB_PREFIX . "projet pj ON pj.rowid=p.fk_projet ORDER BY p.rowid DESC LIMIT {NB}",
		'url'    => '/comm/propal/card.php?id=',
	),
	'generate-stock' => array(
		'table'  => 'product',
		'head'   => array($langs->transnoentitiesnoconv('Label'), $langs->transnoentitiesnoconv('Stock'), $langs->transnoentitiesnoconv('Type'), $langs->transnoentitiesnoconv('Batch'), $langs->transnoentitiesnoconv('ColModifiedAt')),
		'select' => "SELECT p.rowid, p.ref, p.label, CAST(IFNULL(ROUND(SUM(ps.reel),0),0) AS SIGNED) AS stock, IF(p.fk_product_type=0,'Produit','Service') AS type_prod, IF(p.tobatch=0,'Non',IF(p.tobatch=1,'Lot','Serie')) AS lot_serie, DATE_FORMAT(DATE_ADD(p.tms, INTERVAL TIME_TO_SEC(TIMEDIFF(NOW(),UTC_TIMESTAMP())) SECOND),'%d/%m/%Y %H:%i') AS modifie_le FROM " . MAIN_DB_PREFIX . "product p LEFT JOIN " . MAIN_DB_PREFIX . "product_stock ps ON ps.fk_product=p.rowid GROUP BY p.rowid ORDER BY p.rowid DESC LIMIT {NB}",
		'url'    => '/product/card.php?id=',
	),
	'generate-rental' => array(
		'table'  => 'loc_location',
		'head'   => array($langs->transnoentitiesnoconv('Ref'), $langs->transnoentitiesnoconv('ThirdParty'), $langs->transnoentitiesnoconv('Date'), $langs->transnoentitiesnoconv('Status')),
		'select' => "SELECT l.rowid, l.ref, s.nom AS tiers, DATE_FORMAT(l.date_creation,'%d/%m/%Y') AS date_crea, IF(l.statut=0,'Brouillon','Validé') AS statut FROM " . MAIN_DB_PREFIX . "loc_location l LEFT JOIN " . MAIN_DB_PREFIX . "societe s ON s.rowid=l.fk_soc ORDER BY l.rowid DESC LIMIT {NB}",
		'url'    => '/rental/card.php?id=',
	),
	'generate-rental-product' => array(
		'table'  => 'product',
		'head'   => array('Label', 'Prix Vente', 'Prix Loc/J', 'Prix Revient Loc/J', 'Infos Loc'),
		'select' => "SELECT p.rowid, p.ref, p.label, CONCAT(ROUND(p.price,2),' €') AS vente, CONCAT(ROUND(pe.rental_price,2),' €') AS loc, CONCAT(ROUND(pe.rental_costprice,2),' €') AS cost, pe.rental_infos AS infos FROM " . MAIN_DB_PREFIX . "product p LEFT JOIN " . MAIN_DB_PREFIX . "product_extrafields pe ON pe.fk_object = p.rowid WHERE pe.rental_product=1 ORDER BY p.rowid DESC LIMIT {NB}",
		'url'    => '/product/card.php?id=',
	),
	'generate-rental-project' => array(
		'table'  => 'projet',
		'head'   => array('Titre', 'Statut', 'Montant', 'Budget', 'Nb Entrepôts', 'Factures Réc.'),
		'select' => "SELECT p.rowid, p.ref, p.title, IFNULL(ps.code,'-') AS opp_statut, CONCAT(ROUND(p.opp_amount,2),' €') AS opp_mnt, CONCAT(ROUND(p.budget_amount,2),' €') AS budget, (SELECT COUNT(*) FROM " . MAIN_DB_PREFIX . "entrepot WHERE fk_project=p.rowid) AS nb_wh, (SELECT COUNT(*) FROM " . MAIN_DB_PREFIX . "facture_rec WHERE fk_projet=p.rowid) AS nb_rec FROM " . MAIN_DB_PREFIX . "projet p LEFT JOIN " . MAIN_DB_PREFIX . "c_lead_status ps ON ps.rowid=p.fk_opp_status LEFT JOIN " . MAIN_DB_PREFIX . "projet_extrafields pe ON pe.fk_object = p.rowid WHERE pe.rental_ltrproject=2 ORDER BY p.rowid DESC LIMIT {NB}",
		'url'    => '/projet/card.php?id=',
	),
	'generate-rental-order' => array(
		'table'  => 'commande',
		'head'   => array('Projet LLD', 'Type de projet', 'Nb Produits', 'Tiers', 'Date', 'Reste à livrer', 'Montant HT'),
		'select' => "SELECT c.rowid, c.ref, IFNULL(pj.ref,'-') AS projet, IFNULL(CONCAT(CASE pje.rental_ltrproject WHEN 1 THEN 'Loc. Classique' WHEN 2 THEN 'Loc. Longue Durée' ELSE 'Non' END, ' (', CASE pje.rental_ltr_sales_billing WHEN 1 THEN 'Mensuelle' WHEN 2 THEN 'Depuis onglet' WHEN 3 THEN 'Manuel' ELSE '-' END, ')'), '-') AS type_projet, (SELECT COUNT(*) FROM " . MAIN_DB_PREFIX . "commandedet WHERE fk_commande=c.rowid) AS nb_prod, s.nom AS tiers, DATE_FORMAT(c.date_commande,'%d/%m/%Y') AS date_c, (SELECT SUM(cd.qty) - COALESCE((SELECT SUM(ed.qty) FROM " . MAIN_DB_PREFIX . "expeditiondet ed JOIN " . MAIN_DB_PREFIX . "expedition e ON e.rowid=ed.fk_expedition WHERE ed.fk_elementdet = cd.rowid AND e.fk_statut > 0), 0) FROM " . MAIN_DB_PREFIX . "commandedet cd WHERE cd.fk_commande = c.rowid) AS reste_livrer, CONCAT(ROUND(c.total_ht,2),' €') AS ht FROM " . MAIN_DB_PREFIX . "commande c LEFT JOIN " . MAIN_DB_PREFIX . "societe s ON s.rowid=c.fk_soc LEFT JOIN " . MAIN_DB_PREFIX . "projet pj ON pj.rowid=c.fk_projet LEFT JOIN " . MAIN_DB_PREFIX . "projet_extrafields pje ON pje.fk_object = pj.rowid WHERE c.source=1 ORDER BY c.rowid DESC LIMIT {NB}",
		'url'    => '/commande/card.php?id=',
	),
	'generate-rental-workflow' => array(
		'table'  => 'expedition',
		'head'   => array('Commande', 'Projet LLD', 'Tiers', 'Qté Expédiée'),
		'select' => "SELECT e.rowid, e.ref, IFNULL(c.ref,'-') AS commande, IFNULL(pj.ref,'-') AS projet, s.nom AS tiers, (SELECT SUM(qty) FROM " . MAIN_DB_PREFIX . "expeditiondet WHERE fk_expedition=e.rowid) AS qte FROM " . MAIN_DB_PREFIX . "expedition e LEFT JOIN " . MAIN_DB_PREFIX . "element_element ee ON ee.fk_target=e.rowid AND ee.targettype='shipping' AND ee.sourcetype='commande' LEFT JOIN " . MAIN_DB_PREFIX . "commande c ON c.rowid=ee.fk_source LEFT JOIN " . MAIN_DB_PREFIX . "societe s ON s.rowid=e.fk_soc LEFT JOIN " . MAIN_DB_PREFIX . "projet pj ON pj.rowid=c.fk_projet ORDER BY e.rowid DESC LIMIT {NB}",
		'url'    => '/expedition/card.php?id=',
	),
);
$preExecMaxRowid = 0;

// ACTIONS — logique inline, pas de subprocess
// ═══════════════════════════════════════════════════════════════════════════════
// ══ Handler AJAX : streaming de logs ligne à ligne ══════════════════════════════════
if ($action === 'fetch_order_lines') {
    header('Content-Type: application/json; charset=utf-8');
    $fk_commande = GETPOST('fk_commande', 'int');
    if (!$fk_commande) { echo json_encode(array('error' => 'No order ID')); exit; }
    global $db;
    require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';
    $cmd = new Commande($db);
    if ($cmd->fetch($fk_commande) <= 0) { echo json_encode(array('error' => 'Order not found')); exit; }
    
    $res = array('start_date' => date('Y-m-d', $cmd->date_commande), 'lines' => array());
    foreach ($cmd->lines as $line) {
        if ($line->product_type == 0) { // Only products
            $shipped = 0;
            $sql = "SELECT SUM(ed.qty) as qty FROM " . MAIN_DB_PREFIX . "expeditiondet ed JOIN " . MAIN_DB_PREFIX . "expedition e ON e.rowid=ed.fk_expedition WHERE ed.fk_elementdet = " . $line->id . " AND e.fk_statut > 0";
            $res_ship = $db->query($sql);
    $resWh = $db->query("SELECT rowid, ref, lieu as label FROM " . MAIN_DB_PREFIX . "entrepot WHERE fk_project=" . $fk_project);
            $rem = max(0, $line->qty - $shipped);
            $res['lines'][] = array('id' => $line->id, 'ref' => $line->ref, 'qty' => $line->qty, 'qty_shipped' => $shipped, 'qty_rem' => $rem);
        }
    }
    echo json_encode($res);
    $sqlCmd = "SELECT rowid FROM " . MAIN_DB_PREFIX . "commande WHERE fk_projet=" . $fk_project . " AND fk_statut IN (1,2,3)";
}
if ($action === 'fetch_project_flow_data') {
    $fk_project = (int)$_GET['fk_project'];
    require_once DOL_DOCUMENT_ROOT . '/projet/class/project.class.php';
    require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';
    $proj = new Project($db);
    if ($proj->fetch($fk_project) <= 0) { echo json_encode(array('error' => 'Project not found')); exit; }
    $res = array();
    $res['start_date'] = date('Y-m-d', $proj->date_start ?: $proj->date_c);
    
    // Fetch warehouses
    $resWh = $db->query("SELECT rowid, ref, lieu as label FROM " . MAIN_DB_PREFIX . "entrepot WHERE fk_project=" . $fk_project);
    while ($resWh && $objWh = $db->fetch_object($resWh)) {
        $res['warehouses'][] = array('id' => $objWh->rowid, 'ref' => $objWh->ref, 'label' => $objWh->label);
    }
    
    $res['classic_warehouses'] = array();
    $resCWh = $db->query("SELECT rowid, ref, lieu as label FROM " . MAIN_DB_PREFIX . "entrepot WHERE statut=1 AND (fk_project IS NULL OR fk_project = 0) ORDER BY ref");
    while ($resCWh && $objCWh = $db->fetch_object($resCWh)) {
        $res['classic_warehouses'][] = array('id' => $objCWh->rowid, 'ref' => $objCWh->ref, 'label' => $objCWh->label);
    }
    
    // Fetch existing expeditions
    $existing_exp = array();
    $sqlExp = "SELECT e.rowid as id, e.ref, e.date_delivery, e.date_creation, ed.fk_elementdet as cmdline_id, ed.qty ";
    $sqlExp.= "FROM " . MAIN_DB_PREFIX . "expedition as e ";
    $sqlExp.= "JOIN " . MAIN_DB_PREFIX . "expeditiondet as ed ON ed.fk_expedition = e.rowid ";
    $sqlExp.= "JOIN " . MAIN_DB_PREFIX . "commandedet as cd ON cd.rowid = ed.fk_elementdet ";
    $sqlExp.= "JOIN " . MAIN_DB_PREFIX . "commande as c ON c.rowid = cd.fk_commande ";
    $sqlExp.= "WHERE c.fk_projet = " . $fk_project . " AND e.entity IN (" . getEntity('expedition') . ")";
    $resExp = $db->query($sqlExp);
    if ($resExp) {
        require_once DOL_DOCUMENT_ROOT . '/expedition/class/expedition.class.php';
        $tmpExp = new Expedition($db);
        while ($obj = $db->fetch_object($resExp)) {
            $tmpExp->id = $obj->id;
            $tmpExp->ref = $obj->ref;
            $date = $obj->date_delivery ?: $obj->date_creation;
            $month = substr($date, 0, 7); // YYYY-MM
            $existing_exp[$obj->cmdline_id][] = array(
                'id' => $obj->id,
                'ref' => $obj->ref,
                'qty' => $obj->qty,
                'month' => $month,
                'date' => dol_print_date($db->jdate($date), 'day'),
                'url' => $tmpExp->getNomUrl(1)
            );
        }
    }

    // Fetch existing returns
    $existing_ret = array();
    $sqlRet = "SELECT p.rowid as id, p.ref, p.date_return, p.date_creation, pd.fk_origin_line as cmdline_id, pd.qty ";
    $sqlRet.= "FROM " . MAIN_DB_PREFIX . "productreturn as p ";
    $sqlRet.= "JOIN " . MAIN_DB_PREFIX . "productreturndet as pd ON pd.fk_productreturn = p.rowid ";
    $sqlRet.= "JOIN " . MAIN_DB_PREFIX . "commandedet as cd ON cd.rowid = pd.fk_origin_line ";
    $sqlRet.= "JOIN " . MAIN_DB_PREFIX . "commande as c ON c.rowid = cd.fk_commande ";
    $sqlRet.= "WHERE c.fk_projet = " . $fk_project . " AND p.entity IN (" . getEntity('productreturn') . ")";
    $resRet = $db->query($sqlRet);
    if ($resRet) {
        require_once DOL_DOCUMENT_ROOT . '/custom/productreturn/class/productreturn.class.php';
        $tmpRet = new Productreturn($db);
        while ($obj = $db->fetch_object($resRet)) {
            $tmpRet->id = $obj->id;
            $tmpRet->ref = $obj->ref;
            $date = $obj->date_return ?: $obj->date_creation;
            $month = substr($date, 0, 7); // YYYY-MM
            $existing_ret[$obj->cmdline_id][] = array(
                'id' => $obj->id,
                'ref' => $obj->ref,
                'qty' => $obj->qty,
                'month' => $month,
                'date' => dol_print_date($db->jdate($date), 'day'),
                'url' => $tmpRet->getNomUrl(1)
            );
        }
    }
    
    // Fetch order lines (only physical products)
    $sqlCmd = "SELECT rowid FROM " . MAIN_DB_PREFIX . "commande WHERE fk_projet=" . $fk_project . " AND fk_statut IN (1,2,3)";
    $resCmd = $db->query($sqlCmd);
    while ($resCmd && $objCmd = $db->fetch_object($resCmd)) {
        $cmd = new Commande($db);
        if ($cmd->fetch($objCmd->rowid) > 0) {
            foreach ($cmd->lines as $line) {
                if ($line->product_type == 0) { // Only products
                    $shipped = 0;
                    $exp_arr = isset($existing_exp[$line->id]) ? $existing_exp[$line->id] : array();
                    foreach ($exp_arr as $e) $shipped += $e['qty'];
                    
                    $returned = 0;
                    $ret_arr = isset($existing_ret[$line->id]) ? $existing_ret[$line->id] : array();
                    foreach ($ret_arr as $r) $returned += $r['qty'];
                    
                    $res['lines'][] = array(
                        'id' => $line->id,
                        'ref' => $line->ref,
                        'qty' => $line->qty,
                        'cmd_id' => $cmd->id,
                        'cmd_ref' => $cmd->ref,
                        'shipped_qty' => $shipped,
                        'returned_qty' => $returned,
                        'existing_exp' => $exp_arr,
                        'existing_ret' => $ret_arr
                    );
                }
            }
        }
    }
    echo json_encode($res);
    exit;
}
if ($action === 'poll_log') {
	if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
	$_pollScript = GETPOST('script', 'alphanohtml');
	$_pollSince  = max(0, (int) GETPOST('since', 'int'));
	$_pollFile   = DOL_DATA_ROOT . '/dolistream/live-' . preg_replace('/[^a-z0-9]/', '', session_id()) . '-' . preg_replace('/[^a-z0-9-]/', '', $_pollScript) . '.jsonl';
	$_pollLines  = file_exists($_pollFile) ? file($_pollFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : array();
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-store');
	echo json_encode(array('lines' => array_slice($_pollLines, $_pollSince), 'total' => count($_pollLines)), JSON_UNESCAPED_UNICODE);
	exit;
}

if ($action === 'mass_action_ship_orders') {
	$toselect = GETPOST('toselect', 'array');
	if (is_array($toselect) && count($toselect) > 0) {
		require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';
		require_once DOL_DOCUMENT_ROOT . '/expedition/class/expedition.class.php';
		
		$warehouses = dolinstreamGetWarehouseIds($db);
		$defaultWarehouse = !empty($warehouses) ? $warehouses[0] : 0;
		$ok = $ko = 0;
		
		foreach ($toselect as $id) {
			$id = (int)$id;
			if ($id <= 0) continue;
			
			$order = new Commande($db);
			if ($order->fetch($id) > 0) {
				// 1. Valider la commande si elle est brouillon
				if ($order->statut == Commande::STATUS_DRAFT) {
					if ($order->valid($user) < 0) {
						setEventMessage("Erreur validation commande $order->ref : " . $order->error, 'errors');
						$ko++;
						continue;
					}
				}
				
				// 2. Vérifier s'il y a des produits physiques
				$hasPhysical = false;
				foreach ($order->lines as $line) {
					if ($line->product_type == 0) {
						$hasPhysical = true;
						break;
					}
				}
				
				// 3. Créer l'expédition
				if ($hasPhysical) {
					$exp = new Expedition($db);
					$exp->socid = $order->socid;
					$exp->origin = 'commande';
					$exp->origin_id = $order->id;
					$exp->date_delivery = dol_now();
					$exp->note_private = 'Généré en masse par DoliStream';
					
					// Ajouter les lignes physiques
					$hasValidLines = false;
					foreach ($order->lines as $line) {
						if ($line->product_type == 0) {
							$ret = 0;
							if (!empty($line->product_tobatch) && !empty($conf->productbatch->enabled)) {
								// Produit avec lots/séries
								$sql = "SELECT rowid as id_batch, qty FROM " . MAIN_DB_PREFIX . "product_batch WHERE fk_product = " . ((int)$line->fk_product) . " AND fk_warehouse = " . ((int)$defaultWarehouse) . " AND qty > 0 ORDER BY rowid ASC";
								$resBatch = $db->query($sql);
								$qty_needed = (float)$line->qty;
								$dbatch = array('qty' => $qty_needed, 'ix_l' => $line->id, 'detail' => array());
								if ($resBatch) {
									while ($objBatch = $db->fetch_object($resBatch)) {
										if ($qty_needed <= 0) break;
										$take = min($qty_needed, $objBatch->qty);
										$dbatch['detail'][] = array('id_batch' => $objBatch->id_batch, 'q' => $take);
										$qty_needed -= $take;
									}
									$db->free($resBatch);
								}
								
								if ($qty_needed > 0 && !empty($conf->global->STOCK_MUST_BE_ENOUGH_FOR_SHIPMENT)) {
									$exp->error = "Stock par lot insuffisant pour le produit ID " . $line->fk_product;
									$ret = -1;
								} else {
									$ret = $exp->addline_batch($dbatch, array(), $line);
								}
							} else {
								// Produit standard
								$ret = $exp->addline($defaultWarehouse, $line->id, (float)$line->qty, array(), (int)$line->fk_product);
							}
							
							if ($ret > 0) $hasValidLines = true;
							
							if ($ret < 0) {
								setEventMessage("Erreur ligne expédition (Produit ID " . $line->fk_product . ") : " . $exp->error, 'errors');
							}
						}
					}
					
					if ($hasValidLines) {
						$expid = $exp->create($user);
						if ($expid > 0) {
							// Valider l'expédition
							if ($exp->valid($user) > 0) {
								// Générer le document PDF (Bon de livraison)
								$exp->generateDocument($exp->model_pdf ? $exp->model_pdf : 'rouget', $langs);
								$ok++;
							} else {
								setEventMessage("Erreur validation expédition pour $order->ref : " . $exp->error, 'errors');
								$ko++;
							}
						} else {
							setEventMessage("Erreur création expédition pour $order->ref : " . $exp->error, 'errors');
							$ko++;
						}
					} else {
						// Impossible d'ajouter la moindre ligne
						$ko++;
					}
				} else {
					// Pas de produit physique, juste validée
					$ok++;
				}
			} else {
				$ko++;
			}
		}
		
		if ($ok > 0) setEventMessage($langs->trans('RecordsModified', $ok));
	} else {
		setEventMessage($langs->trans('NoRecordSelected'), 'warnings');
	}
	header('Location: ' . $_SERVER['PHP_SELF'] . '?script=' . urlencode(GETPOST('script', 'alphanohtml')));
	exit;
}

if ($action === 'run' && !empty($script) && (int) GETPOST('token_check') >= 0) {
	// Vérification du token CSRF
	if (!newToken() && empty($conf->global->DOLISTREAM_DISABLE_TOKEN_CHECK)) {
		// token check géré par Dolibarr via newToken()
	}

	@set_time_limit(300);

	// Capture rowid max AVANT creation (pour recuperer les elements crees)
	if (isset($dsDbConf[$script])) {
		$_preSql = 'SELECT MAX(rowid) AS m FROM ' . MAIN_DB_PREFIX . $dsDbConf[$script]['table'];
		$_preRes = $db->query($_preSql);
		if ($_preRes && ($_preRow = $db->fetch_object($_preRes))) {
			$preExecMaxRowid = (int)$_preRow->m;
		}
	}

	// Initialise le fichier de progression
	dolinstreamProgress(0, 0);

	// Prépare le fichier live pour le streaming AJAX (1 ligne JSON par dsLog)
	global $dsLiveLogFile;
	$_liveDir  = DOL_DATA_ROOT . '/dolistream/';
	if (!is_dir($_liveDir)) @mkdir($_liveDir, 0755, true);
	$dsLiveLogFile = $_liveDir . 'live-' . preg_replace('/[^a-z0-9]/', '', session_id()) . '-' . preg_replace('/[^a-z0-9-]/', '', $script) . '.jsonl';
	@unlink($dsLiveLogFile); // repart de zéro à chaque exécution

	$nb   = max(1, (int) GETPOST('nb', 'int'));
	$mode = GETPOST('mode', 'alpha');
	$opt  = GETPOST('opt', 'alpha');
	$date = GETPOST('date', 'alpha');

	// ── Persistance de preExecMaxRowid pour le reload (fichier — plus fiable que session avec NOREQUIREMENU) ──
	$_prevRowidFile = DOL_DATA_ROOT . '/dolistream/prev-'
		. preg_replace('/[^a-z0-9]/', '', session_id()) . '-'
		. preg_replace('/[^a-z0-9-]/', '', $script) . '.prev.json';
	file_put_contents($_prevRowidFile, json_encode([
		'rowid'  => $preExecMaxRowid,
		'script' => $script,
		'ts'     => time(),
	]), LOCK_EX);

	// Libère le verrou de session (session déjà potentiellement fermée par NOREQUIREMENU)
	if (session_status() === PHP_SESSION_ACTIVE) {
		$_SESSION['ds_prev_rowid']  = $preExecMaxRowid; // belt-and-suspenders
		$_SESSION['ds_prev_script'] = $script;
		session_write_close();
	}

	// ── Utiliser l'utilisateur connecté directement ───────────────────────────
	// L'accès admin est déjà vérifié en haut de page par Dolibarr.
	$fuser = $user;
	if (empty($fuser->rights)) {
		$fuser->loadRights();
	}

	// ════════════════════════════════════════════════════════════════════════
	if ($script === 'generate-thirdparty') {
	// ════════════════════════════════════════════════════════════════════════
		$listoftown = array(
			'Paris', 'Lyon', 'Marseille', 'Bordeaux', 'Nantes', 'Toulouse',
			'Strasbourg', 'Lille', 'Rennes', 'Auray', 'Vannes', 'Haguenau',
			'Lauterbourg', 'Souffelweiersheim', 'Baden', 'Le Bono',
		);
		$listoffirstname = array(
			'Marc', 'Julie', 'Steve', 'Laurent', 'Nicolas', 'Isabelle',
			'Dorothée', 'Brigitte', 'Karine', 'José', 'Céline', 'Virginie',
			'Thomas', 'Emma', 'Lucas', 'Léa', 'Maxime', 'Camille',
		);

		dsLog($langs->transnoentitiesnoconv('GenerateThirdparties') . ' : ' . $nb);

		// Correspondance type → flags Dolibarr
		// client : 0=non-client, 1=client, 2=prospect  |  fournisseur : 0/1
		$socTypeMap = array(
			'prospect'   => array('client' => 2, 'fourn' => 0, 'label' => 'Prospect'),
			'client'     => array('client' => 1, 'fourn' => 0, 'label' => 'Client'),
			'supplier'   => array('client' => 0, 'fourn' => 1, 'label' => 'Fournisseur'),
			'both'       => array('client' => 1, 'fourn' => 1, 'label' => 'Client & Fournisseur'),
		);
		// Si l'option choisie n'est pas reconnue (ou 'random'), on tire au sort à chaque itération
		$fixedType = isset($socTypeMap[$opt]) ? $socTypeMap[$opt] : null;

		$ok = $ko = 0;
		for ($s = 0; $s < $nb; $s++) {
			// Déterminer les flags client/fournisseur
			if ($fixedType !== null) {
				$socClient  = $fixedType['client'];
				$socFourn   = $fixedType['fourn'];
				$socTypeLabel = $fixedType['label'];
			} else {
				// Mode aléatoire : tire un type parmi les 4
				$rndType    = $socTypeMap[array_rand($socTypeMap)];
				$socClient  = $rndType['client'];
				$socFourn   = $rndType['fourn'];
				$socTypeLabel = $rndType['label'];
			}

			$soc               = new Societe($db);
			$soc->name         = 'Company ' . dol_print_date(dol_now(), 'dayhour') . '-' . $s;
			$soc->town         = $listoftown[array_rand($listoftown)];
			$soc->client       = $socClient;
			$soc->fournisseur  = $socFourn;
			// -1 = génération automatique via le module de numérotation configuré
			// (SOCIETE_CODECLIENT_ADDON / SOCIETE_CODEFOURNISSEUR_ADDON)
			$soc->code_client      = -1;
			$soc->code_fournisseur = -1;
			$soc->tva_assuj    = 1;
			$soc->country_id   = 1;
			$soc->country_code = 'FR';
			if (mt_rand(1, 3) === 3) {
				$soc->remise_percent = 5;
			}
			$soc->note_private = 'Créé par DoliStream';

			$socid = $soc->create($fuser);
			if ($socid > 0) {
				$rand = mt_rand(1, 3);
				for ($c = 0; $c < $rand; $c++) {
					$contact            = new Contact($db);
					$contact->socid     = $soc->id;
					$contact->lastname  = 'Lastname' . $c;
					$contact->firstname = $listoffirstname[array_rand($listoffirstname)];
					$contact->create($fuser);
				}
				dsLog(
					'✔ #' . $s . ' — ' . $soc->name . ' [' . $socTypeLabel . ']'
					. ' [cli=' . ($soc->code_client ?: '—') . ', fourn=' . ($soc->code_fournisseur ?: '—') . ']',
					'success'
				);
				$ok++;
			} else {
				dsLog('✘ #' . $s . ' — ' . $soc->error, 'error');
				$ko++;
			}
		}
		dsLog('─── ' . $ok . ' ' . $langs->transnoentitiesnoconv('ResultSuccess') . ', ' . $ko . ' ' . $langs->transnoentitiesnoconv('ResultErrors') . ' ───');

	// ════════════════════════════════════════════════════════════════════════
	} elseif ($script === 'generate-product') {
	// ─
		// Options utilisateur
		$productType  = GETPOST('product_type',  'alpha') ?: 'random'; // random / product / service
		$withStock    = (GETPOST('with_stock', 'alpha') === 'yes');
		$stockQtyMax  = max(1, (int)(GETPOST('stock_qty_max', 'int') ?: 100));
		$batchMode    = GETPOST('batch_mode', 'alpha') ?: 'none';      // none / lot / serial
		$hasBatchMod  = isModEnabled('productbatch');

		if ($batchMode !== 'none' && !$hasBatchMod) {
			dsLog('⚠ Module Lots/Séries non activé → numérotation désactivée', 'warn');
			$batchMode = 'none';
		}
		if ($withStock) {
			require_once DOL_DOCUMENT_ROOT . '/product/stock/class/mouvementstock.class.php';
		}

		$whIds = $withStock ? dolinstreamGetWarehouseIds($db) : array();
		if ($withStock && empty($whIds)) {
			dsLog('⚠ Aucun entrepôt ouvert — stock ignoré. Créez-en via Pré-requis > Entrepôt.', 'warn');
			$withStock = false;
		}

		// Cache noms entrepôts
		$whNames = array();
		foreach ($whIds as $wid) {
			$res = $db->query('SELECT ref FROM ' . MAIN_DB_PREFIX . 'entrepot WHERE rowid=' . (int)$wid);
			if ($res && ($owh = $db->fetch_object($res))) $whNames[$wid] = $owh->ref;
		}

		dsLog('Générer des produits : ' . $nb . ' | type=' . $productType . ' | stock=' . ($withStock?'oui':'non') . ' | batch=' . $batchMode);

		$productAddonName = getDolGlobalString('PRODUCT_ADDON', 'mod_codeproduct_leopard');
		$productAddonFile = DOL_DOCUMENT_ROOT . '/core/modules/product/' . $productAddonName . '.php';
		$productMod = null;
		if (file_exists($productAddonFile)) {
			require_once $productAddonFile;
			$productMod = new $productAddonName();
		}

		// Préfixe de référence : AAMM (année 2 chiffres + mois 2 chiffres)
		$_refDateBase = date('ym'); // ex: 2606

		// Séquence de départ = MAX(rowid) du dernier produit en base
		// À chaque lancement on repart du dernier id connu → +1, +2, ...
		$_lastRefNum = 0;
		$_rRes = $db->query('SELECT MAX(rowid) AS m FROM ' . MAIN_DB_PREFIX . 'product');
		if ($_rRes && ($_rRow = $db->fetch_object($_rRes))) {
			$_lastRefNum = (int)$_rRow->m;
		}
		// Prochain numéro à utiliser pour le fallback.
		// Se recale après chaque produit créé (module ou fallback) sur last_ref_num + 1.
		$_nextFallbackNum = $_lastRefNum + 1;
		dsLog('Séquence de départ : dernier id=' . $_lastRefNum . ' → prochain = PRD-' . $_refDateBase . '-' . sprintf('%05d', $_nextFallbackNum));

		$ok = $ko = 0;
		for ($s = 0; $s < $nb; $s++) {
			$product = new Product($db);

			// Type
			if ($productType === 'product') {
				$product->type = 0;
			} elseif ($productType === 'service') {
				$product->type = 1;
			} else {
				$product->type = mt_rand(0, 1);
			}

			$product->status            = 1;
			$product->status_buy        = 1;
			$product->finished          = 0;
			$product->stockable_product = ($product->type === 0) ? 1 : 0;
			$product->description       = 'Généré automatiquement par DoliStream.';
			$product->price             = round(mt_rand(100, 99999) / 100, 2);
			$product->tva_tx            = '20.000';

			// Batch config avant création (uniquement produits physiques)
			if ($product->type === 0) {
				if ($batchMode === 'lot')    $product->tobatch = 1;
				if ($batchMode === 'serial') $product->tobatch = 2;
			}

			// Référence
			if ($productMod !== null) {
				$productRef = $productMod->getNextValue($product, $product->type);
			} else {
				$productRef = '';
			}
			if (!$productRef || $productRef === -1) {
				$productRef = ($product->type ? 'SRV' : 'PRD') . '-' . $_refDateBase . '-' . sprintf('%05d', $_nextFallbackNum);
				dsLog('⚠ #' . ($s + 1) . ' — module ' . $productAddonName . ' non configuré, ref fallback : ' . $productRef, 'warn');
			}
			$product->ref   = $productRef;
			$product->label = ($product->type ? 'Service ' : 'Produit ') . date('ymd-His') . '-' . sprintf('%04d', $s);

			$ret = $product->create($fuser);
			if ($ret < 0) {
				dsLog('✗ #' . ($s + 1) . ' — ' . $product->error, 'error');
				$ko++;
				continue;
			}

			// Recale le compteur fallback sur la dernière réf effectivement attribuée
			// Que ce soit via module (PRD-2606-01616) ou fallback, on repart toujours de là
			if (preg_match('/^(?:PRD|SRV)-\d{4}-(\d+)$/', $productRef, $_refM)) {
				$_nextFallbackNum = (int)$_refM[1] + 1;
			} else {
				$_nextFallbackNum++;
			}

			// UPDATE tobatch en DB après create() (le champ n'est pas toujours persisté par l'INSERT)
			if ($product->type === 0 && $batchMode === 'serial') {
				$db->query('UPDATE ' . MAIN_DB_PREFIX . 'product SET tobatch=2 WHERE rowid=' . (int)$product->id);
				$product->tobatch = 2;
			} elseif ($product->type === 0 && $batchMode === 'lot') {
				$db->query('UPDATE ' . MAIN_DB_PREFIX . 'product SET tobatch=1 WHERE rowid=' . (int)$product->id);
				$product->tobatch = 1;
			}

			$typeLabel  = $product->type ? 'Service' : 'Produit';
			$stockInfo  = '';
			$batchInfo  = '';

			// Ajout du stock
			if ($withStock && !empty($whIds)) {
				$whId   = $whIds[array_rand($whIds)];
				$whName = $whNames[$whId] ?? ('#' . $whId);
				$qty    = mt_rand(1, $stockQtyMax);

				if ($batchMode === 'serial' && $product->tobatch == 2) {
					for ($u = 1; $u <= $qty; $u++) {
						$serial = 'SN-' . strtoupper(substr(md5(uniqid('', true)), 0, 8));
						$mv = new MouvementStock($db);
						$mv->_create($fuser, $product->id, $whId, 1, 0, $product->price, 'DoliStream stock', '', '', 0, 0, $serial);
					}
					$batchInfo = 'SN×' . $qty;
					$stockInfo = '+' . $qty . ' @ ' . $whName;
				} elseif ($batchMode === 'lot' && $product->tobatch == 1) {
					$lot = 'LOT-' . date('Ymd') . '-' . sprintf('%04d', $s);
					$mv = new MouvementStock($db);
					$mv->_create($fuser, $product->id, $whId, $qty, 0, $product->price, 'DoliStream stock', '', '', 0, 0, $lot);
					$batchInfo = $lot;
					$stockInfo = '+' . $qty . ' @ ' . $whName;
				} else {
					// Pas de lot/série (service ou batch_mode=none)
					$mv = new MouvementStock($db);
					$mv->_create($fuser, $product->id, $whId, $qty, 0, $product->price, 'DoliStream stock');
					$stockInfo = '+' . $qty . ' @ ' . $whName;
				}
			}

			dsLog('✓ #' . ($s + 1) . ' | id=' . $product->id . ' | ' . $product->ref . ' | ' . $typeLabel . ' | ' . $product->price . ' € | ' . $stockInfo . ($batchInfo ? ' | ' . $batchInfo : ''), 'success');
			$ok++;
		}
		dsLog('═ ' . $ok . ' OK, ' . $ko . ' erreur(s) ═');
	} elseif ($script === 'generate-rental-product') {
	// ════════════════════════════════════════════════════════════════════════
		$rentalRatio = max(1, (int)(GETPOST('rental_ratio', 'int') ?: 5));
		$_refDateBase = date('ym');
		$_lastRefNum = 0;
		$_rRes = $db->query('SELECT MAX(rowid) AS m FROM ' . MAIN_DB_PREFIX . 'product');
		if ($_rRes && ($_rRow = $db->fetch_object($_rRes))) $_lastRefNum = (int)$_rRow->m;
		$_nextFallbackNum = $_lastRefNum + 1;
		$ok = $ko = 0;
		for ($s = 0; $s < $nb; $s++) {
			$product = new Product($db);
			$product->type = 0;
			$product->status = 1;
			$product->status_buy = 1;
			$product->finished = 0;
			$product->stockable_product = 1;
			$product->description = 'Généré automatiquement par DoliStream (Location).';
			$sellPrice = round(mt_rand(50000, 999900) / 100, 2);
			$costPrice = round($sellPrice * 0.6, 2);
			$product->price = $sellPrice;
			$product->cost_price = $costPrice;
			$product->tva_tx = '20.000';
			$product->ref = 'PRDL-' . $_refDateBase . '-' . sprintf('%05d', $_nextFallbackNum);
			$product->label = 'Produit LLD ' . date('ymd-His') . '-' . sprintf('%04d', $s);
			$product->array_options = array(
				'options_rental_product' => 1,
				'options_rental_label' => $product->ref . '-location',
				'options_rental_price' => round($sellPrice * ($rentalRatio / 100), 2),
				'options_rental_costprice' => round($costPrice * ($rentalRatio / 100), 2),
				'options_rental_infos' => 'Produit de location généré automatiquement.'
			);
			$ret = $product->create($fuser);
			if ($ret < 0) {
				dsLog('✗ #' . ($s + 1) . ' — ' . $product->error, 'error');
				$ko++;
				continue;
			}
			$_nextFallbackNum++;
			dsLog('✓ #' . ($s + 1) . ' | id=' . $product->id . ' | ' . $product->ref . ' | ' . $product->label . ' | ' . $product->price . ' € | ' . $product->array_options['options_rental_price'] . ' €/j | ' . $product->array_options['options_rental_costprice'] . ' €/j | ' . $product->array_options['options_rental_infos'], 'success');
			$ok++;
		}
		dsLog('═ ' . $ok . ' OK, ' . $ko . ' erreur(s) ═');
	} elseif ($script === 'generate-invoice') {
	// ════════════════════════════════════════════════════════════════════════
		$date_start   = GETPOST('date_start', 'alpha') ?: date('Y-m-01');
		$date_end     = GETPOST('date_end', 'alpha') ?: date('Y-m-t');
		$nb_per_month = max(1, (int) GETPOST('nb_per_month', 'int'));

		$socids  = dolinstreamGetClientIds($db);
		$prodids = dolinstreamGetProductIds($db);

		if (empty($socids)) {
			dsLog('❌ ' . $langs->transnoentitiesnoconv('NoClientThirdparty'), 'error');
			goto render;
		}
		if (empty($prodids)) {
			dsLog('❌ ' . $langs->transnoentitiesnoconv('NoProduct'), 'error');
			goto render;
		}

		// Générer les dates par mois
		$start = new DateTime($date_start);
		$end   = new DateTime($date_end);
		
		$dates = array();
		if ($start <= $end) {
			$interval = DateInterval::createFromDateString('1 month');
			$period = new DatePeriod(
				(clone $start)->modify('first day of this month'),
				$interval,
				(clone $end)->modify('first day of next month')
			);

			foreach ($period as $dt) {
				$m_start = max(new DateTime($date_start), $dt);
				$m_end   = min(new DateTime($date_end), (clone $dt)->modify('last day of this month'));
				
				$start_ts = $m_start->getTimestamp();
				$end_ts   = $m_end->getTimestamp();
				if ($start_ts > $end_ts) continue; // Sécurité
				
				for ($i = 0; $i < $nb_per_month; $i++) {
					$dates[] = mt_rand($start_ts, $end_ts);
				}
			}
		}

		$total_invoices = count($dates);
		if ($total_invoices === 0) {
			dsLog('❌ Période invalide ou aucun mois trouvé.', 'error');
			goto render;
		}

		dsLog($langs->transnoentities('GenerateInvoices') . ' : ' . $total_invoices . ' (' . count($socids) . ' tiers, ' . count($prodids) . ' produits)');
		$ok = $ko = 0;

		foreach ($dates as $idx => $inv_date) {
			$obj                    = new Facture($db);
			$obj->socid             = $socids[array_rand($socids)];
			$obj->date              = $inv_date;
			$obj->cond_reglement_id = 3;
			$obj->mode_reglement_id = 3;

			// Note : pas de $db->begin() manuel — Facture::create() et validate()
			// gèrent leurs propres transactions. Un wrapping externe crée un
			// snapshot REPEATABLE READ qui bloque le module de numérotation.
			$result = $obj->create($fuser);
			if ($result >= 0) {
				$nbLines = mt_rand(2, 5);
				$lineOk  = true;
				for ($l = 0; $l < $nbLines; $l++) {
					$pid     = $prodids[array_rand($prodids)];
					$product = new Product($db);
					$product->fetch($pid);
					$r = $obj->addline(
						$product->description,
						$product->price,
						mt_rand(1, 5),
						$product->tva_tx ?? 20,
						0, 0,
						$pid,
						0, '', '', 0, 0, '',
						$product->price_base_type,
						$product->price_ttc,
						$product->type
					);
					if ($r < 0) {
						$lineOk = false;
						break;
					}
				}
				$obj->fetch($obj->id);
				$obj->fetch_thirdparty();
				$obj->fetch_lines();
				if ($lineOk && $obj->validate($fuser) > 0) {
					$ht  = price2num($obj->total_ht, 'MT');
					$ttc = price2num($obj->total_ttc, 'MT');
					dsLog('✔ #' . $idx . ' | ' . $obj->ref . ' | soc=' . $obj->socid . ' | ' . dol_print_date($obj->date, 'day') . ' | HT=' . $ht . ' | TTC=' . $ttc, 'success');
					$ok++;
				} else {
					dsLog('✘ #' . $idx . ' — validation : ' . $obj->error, 'error');
					$ko++;
				}
			} else {
				dsLog('✘ #' . $idx . ' — création : ' . $obj->error, 'error');
				$ko++;
			}
			
			if ($idx % 5 === 0 || $idx === $total_invoices - 1) dolinstreamProgress($idx + 1, $total_invoices);
		}
		dolinstreamProgress($total_invoices, $total_invoices, true);
		dsLog('─── ' . $ok . ' OK, ' . $ko . ' erreur(s) ───');

	// ════════════════════════════════════════════════════════════════════════
	} elseif ($script === 'generate-order') {
	// ════════════════════════════════════════════════════════════════════════
		$nbLinesOpt  = GETPOST('nb_lines', 'int') > 0 ? GETPOST('nb_lines', 'int') : mt_rand(2, 5);
		$batchMode   = GETPOST('batch_mode', 'alpha') ?: 'all';
		$inStock     = GETPOST('in_stock', 'int') > 0 ? 'yes' : 'all';

		$socids  = dolinstreamGetClientIds($db);
		$prodids = dolinstreamGetProductIds($db, $batchMode, $inStock);

		if (empty($socids)) { dsLog('❌ ' . $langs->transnoentitiesnoconv('NoClientThirdparty'), 'error'); goto render; }
		if (empty($prodids)) { dsLog('❌ ' . $langs->transnoentitiesnoconv('NoProduct'), 'error'); goto render; }

		$dates = dolinstreamGetRandomDates();
		dsLog($langs->transnoentities('GenerateOrders') . ' : ' . $nb);
		$ok = $ko = 0;

		for ($s = 0; $s < $nb; $s++) {
			$obj                     = new Commande($db);
			$obj->socid              = $socids[array_rand($socids)];
			$obj->date_commande      = $dates[array_rand($dates)];
			$obj->note               = 'Généré par DoliStream';
			$obj->source             = 1;
			$obj->fk_project         = 0;
			$obj->remise_percent     = 0;
			$obj->shipping_method_id = mt_rand(1, 2);
			$obj->cond_reglement_id  = mt_rand(1, 3);
			$obj->availability_id    = mt_rand(0, 1);

			// Note : pas de $db->begin() manuel — Commande::create() et valid()
			// gèrent leurs propres transactions. Un wrapping externe crée un
			// snapshot REPEATABLE READ qui bloque le module de numérotation.
			$result = $obj->create($fuser);
			if ($result >= 0) {
				$nbLines = GETPOST('nb_lines', 'int') > 0 ? GETPOST('nb_lines', 'int') : mt_rand(2, 5);
				$lineOk  = true;
				for ($l = 0; $l < $nbLines; $l++) {
					$pid     = $prodids[array_rand($prodids)];
					$product = new Product($db);
					$product->fetch($pid);
					$r = $obj->addline(
						$product->description,
						$product->price,
						mt_rand(1, 5),
						$product->tva_tx ?? 20,
						0, 0,
						$pid,
						0, 0, 0,
						$product->price_base_type,
						$product->price_ttc,
						'', '',
						$product->type
					);
					if ($r < 0) { $lineOk = false; break; }
				}
				if ($lineOk) {
					// ── Reproduire exactement ce que Facture::validate() fait ──────────
					// fetch() complet + fetch_thirdparty() + fetch_lines() AVANT valid()
					// pour que le module de numérotation ait un $soc et des lignes chargées
					$obj->fetch($obj->id);
					$obj->fetch_thirdparty();
					$obj->fetch_lines();
					// ─────────────────────────────────────────────────────────────────
					if ($obj->valid($fuser) > 0) {
						$obj->fetch($obj->id);
						$ht = price2num($obj->total_ht, 'MT');
						$isShippable = 'Non';
						foreach ($obj->lines as $line) {
							if ($line->product_type == 0) { $isShippable = 'Oui'; break; }
						}
						$batchStr = $batchMode === 'no_batch' ? 'Sans_lot' : $batchMode;
						dsLog('✔ #' . $s . ' | ' . $obj->ref . ' | batch=' . $batchStr . ' | nb=' . $nbLines . ' | ship=' . $isShippable . ' | soc=' . $obj->socid . ' | ' . dol_print_date($obj->date_commande, 'day') . ' | HT=' . $ht, 'success');
						$ok++;
					} else {
						dsLog('✘ #' . $s . ' — ' . $obj->error, 'error');
						$ko++;
					}
				} else {
					dsLog('✘ #' . $s . ' — addline : ' . $obj->error, 'error');
					$ko++;
				}
			} else {
				dsLog('✘ #' . $s . ' — création : ' . $obj->error, 'error');
				$ko++;
			}
		}
		dsLog('─── ' . $ok . ' OK, ' . $ko . ' erreur(s) ───');

	// ════════════════════════════════════════════════════════════════════════
	} elseif ($script === 'generate-proposal') {
	// ════════════════════════════════════════════════════════════════════════
		$socids  = dolinstreamGetClientIds($db);
		$prodids = dolinstreamGetProductIds($db);

		if (empty($socids)) { dsLog('❌ ' . $langs->transnoentitiesnoconv('NoClientThirdparty'), 'error'); goto render; }

		$dates = dolinstreamGetRandomDates();
		dsLog($langs->transnoentities('GenerateProposals') . ' : ' . $nb);
		$ok = $ko = 0;

		for ($s = 0; $s < $nb; $s++) {
			$obj                    = new Propal($db);
			$obj->socid             = $socids[array_rand($socids)];
			$obj->date              = $dates[array_rand($dates)];
			$obj->date_fin_validite = $obj->date + (30 * 24 * 3600);
			$obj->cond_reglement_id = 3;
			$obj->mode_reglement_id = 3;
			$obj->fk_project        = 0;

			// Note : pas de $db->begin() manuel — Propal::create() gère sa
			// propre transaction. Un wrapping externe perturbe le module de numérotation.
			$result = $obj->create($fuser);
			if ($result >= 0) {
				$nbLines = mt_rand(1, 4);
				for ($l = 0; $l < $nbLines; $l++) {
					if (empty($prodids)) break;
					$pid     = $prodids[array_rand($prodids)];
					$product = new Product($db);
					$product->fetch($pid);
					$rLine = $obj->addline(
						$obj->id,
						$product->description,
						$product->price,
						$product->tva_tx ?? 20,
						0, 0,
						mt_rand(1, 5),
						$pid,
						'',
						$product->price_base_type,
						$product->price_ttc,
						0,
						$product->type
					);
					if ($rLine < 0) dsLog('⚠ addline pid=' . $pid . ' : ' . $obj->error, 'warn');
				}
				// ── Même pattern que Facture::validate() et Commande::valid() ─────────
				// Recharge l'objet + tiers + lignes AVANT valid() pour que le module
				// de numérotation dispose d'un $soc complet (évite les refs PROV)
				$obj->fetch($obj->id);
				$obj->fetch_thirdparty();
				$obj->fetch_lines();
				if ($obj->valid($fuser) > 0) {
					$obj->fetch($obj->id);
					$ht = price2num($obj->total_ht, 'MT');
					dsLog('✔ #' . $s . ' | ' . $obj->ref . ' | soc=' . $obj->socid . ' | ' . dol_print_date($obj->date, 'day') . ' | HT=' . $ht, 'success');
					$ok++;
				} else {
					dsLog('✘ #' . $s . ' — valid() : ' . $obj->error, 'error');
					$ko++;
				}
			} else {
				dsLog('✘ #' . $s . ' — création : ' . $obj->error, 'error');
				$ko++;
			}
		}
		dsLog('─── ' . $ok . ' OK, ' . $ko . ' erreur(s) ───');

	// ════════════════════════════════════════════════════════════════════════
	} elseif ($script === 'generate-project') {
	// ════════════════════════════════════════════════════════════════════════
		// mode : 'free' = sans tiers | 'linked' = lié à un tiers aléatoire
		$projectMode = in_array($mode, array('free', 'linked')) ? $mode : 'free';
		$socids      = ($projectMode === 'linked') ? dolinstreamGetClientIds($db) : array();

		if ($projectMode === 'linked' && empty($socids)) {
			dsLog('❌ ' . $langs->transnoentitiesnoconv('NoClientThirdparty') . ' (mode lié)', 'error');
			goto render;
		}

		// Statuts d'opportunité Dolibarr (table llx_c_lead_status, rowid standards)
		// 1=PROSP  2=QUAL  3=PROP  4=NEGO  5=WON  — on exclut WON/LOST pour du réaliste
		$oppStatuses = array(
			1 => array('code' => 'PROSP', 'label' => 'Prospection',   'pct' => mt_rand(10, 25)),
			2 => array('code' => 'QUAL',  'label' => 'Qualifié',       'pct' => mt_rand(25, 45)),
			3 => array('code' => 'PROP',  'label' => 'Proposition',    'pct' => mt_rand(40, 65)),
			4 => array('code' => 'NEGO',  'label' => 'Négociation',    'pct' => mt_rand(60, 85)),
			5 => array('code' => 'WON',   'label' => 'Gagné',          'pct' => 100),
		);

		$projectNames = array(
			'Refonte SI', 'Migration Cloud', 'Audit Sécurité', 'Développement App',
			'Intégration ERP', 'Formation Équipe', 'Déploiement Infrastructure',
			'Conseil Stratégique', 'Accompagnement Digital', 'Mise en conformité RGPD',
			'Optimisation Processus', 'Projet Innovation', 'Étude de Marché',
			'Implémentation CRM', 'Transformation Agile',
		);

		$dates = dolinstreamGetRandomDates();
		dsLog('Générer des projets/opportunités : ' . $nb . ' (mode=' . $projectMode . ')');
		$ok = $ko = 0;

		for ($s = 0; $s < $nb; $s++) {
			// Choisir un statut d'opportunité aléatoire
			$oppKey    = array_rand($oppStatuses);
			$oppStatus = $oppStatuses[$oppKey];

			$proj = new Project($db);

			// ── Obtenir la prochaine référence via le module Dolibarr configuré ────
			// Reproduit exactement ce que Project::createFromClone() fait.
			// Le module par défaut (mod_project_simple) génère : PJyymm-nnnn
			$projRef   = '';
			$addonName = getDolGlobalString('PROJECT_ADDON', 'mod_project_simple');
			$addonFile = DOL_DOCUMENT_ROOT . '/core/modules/project/' . $addonName . '.php';
			if (file_exists($addonFile)) {
				require_once $addonFile;
				$modProject = new $addonName();
				$proj->date_c   = $dates[array_rand($dates)]; // date de création fictive pour le yymm
				$projRef = $modProject->getNextValue(null, $proj);
			}
			if (!$projRef || is_numeric($projRef) && (int) $projRef <= 0) {
				dsLog('✘ #' . $s . ' — Impossible d\'obtenir une référence via ' . $addonName, 'error');
				$ko++;
				continue;
			}

			$proj->ref               = $projRef;
			$proj->title             = $projectNames[array_rand($projectNames)] . ' ' . ($s + 1);
			$proj->description       = 'Généré automatiquement par DoliStream (' . $oppStatus['label'] . ')';
			$proj->date_start        = $proj->date_c;
			$proj->date_end          = $proj->date_start + mt_rand(30, 365) * 24 * 3600;
			$proj->statut            = Project::STATUS_VALIDATED; // ouvert d'emblée
			$proj->usage_opportunity = 1;
			$proj->opp_status        = $oppKey;                  // rowid du statut
			$proj->opp_percent       = $oppStatus['pct'];
			$proj->opp_amount        = mt_rand(1000, 150000);    // montant aléatoire
			$proj->budget_amount     = $proj->opp_amount * (mt_rand(90, 110) / 100);
			$proj->public            = 1;
			$proj->fk_user_creat     = $fuser->id;

			// Lier à un tiers si mode 'linked'
			if ($projectMode === 'linked' && !empty($socids)) {
				$proj->socid = $socids[array_rand($socids)];
			}

			$result = $proj->create($fuser);
			if ($result > 0) {
				dsLog(
					'✔ #' . $s . ' | ' . $proj->ref . ' | ' . $proj->title
					. ' | ' . $oppStatus['label']
					. ' | ' . number_format((int)$proj->opp_amount, 0, ',', ' ') . ' €'
					. ' | ' . number_format((int)$proj->budget_amount, 0, ',', ' ') . ' €',
					'success'
				);
				$ok++;
			} else {
				dsLog('✘ #' . $s . ' — ' . $proj->error, 'error');
				$ko++;
			}
			// Progression toutes les 5 items ou au dernier
			if ($s % 5 === 0 || $s === $nb - 1) {
				dolinstreamProgress($s + 1, $nb);
			}
		}
		dolinstreamProgress($nb, $nb, true); // marque terminé
		dsLog('─── ' . $ok . ' OK, ' . $ko . ' erreur(s) ───');

	// ════════════════════════════════════════════════════════════════════════
	} elseif ($script === 'generate-rental-project') {
	// ════════════════════════════════════════════════════════════════════════
		$dateStartInput = GETPOST('date_start', 'alpha');
		$baseTs = !empty($dateStartInput) ? strtotime($dateStartInput) : strtotime('-1 year');
		$salesBilling = (int) GETPOST('sales_billing', 'int') ?: 3;
		$nbWh = max(0, (int) GETPOST('nb_wh', 'int'));

		$socids      = GETPOST('socids', 'array');
		if (empty($socids)) {
			dsLog('❌ ' . $langs->transnoentitiesnoconv('NoClientThirdparty') . ' (tiers obligatoire)', 'error');
			goto render;
		}
		$oppStatuses = array(
			1 => array('code' => 'PROSP', 'label' => 'Prospection',   'pct' => mt_rand(10, 25)),
			2 => array('code' => 'QUAL',  'label' => 'Qualifié',       'pct' => mt_rand(25, 45)),
			3 => array('code' => 'PROP',  'label' => 'Proposition',    'pct' => mt_rand(40, 65)),
			4 => array('code' => 'NEGO',  'label' => 'Négociation',    'pct' => mt_rand(60, 85)),
			5 => array('code' => 'WON',   'label' => 'Gagné',          'pct' => 100),
		);
		$projectNames = array('Location Longue Durée Flotte Auto', 'LLD Matériel Chantier', 'Location Informatique 36 mois', 'Contrat LLD Équipement BTP', 'Pack LLD Serveurs', 'Location Nacelles Élévatrices');
		
		$ok = $ko = 0;
		for ($s = 0; $s < $nb; $s++) {
			$oppStatus = $oppStatuses[array_rand($oppStatuses)];
			$randDate  = $baseTs + mt_rand(0, 5 * 24 * 3600);
			$proj = new Project($db);
			$proj->title       = $projectNames[array_rand($projectNames)] . ' - ' . date('ym', $randDate) . '-' . sprintf('%04d', $s);
			$proj->ref         = 'LLD-' . date('ym', $randDate) . '-' . sprintf('%05d', mt_rand(1, 99999));
			$proj->opp_status  = array_search($oppStatus, $oppStatuses);
			$proj->opp_percent = $oppStatus['pct'];
			$proj->date_c      = $randDate;
			$proj->date_start  = $randDate;
			$proj->date_end    = $randDate + mt_rand(30, 365) * 24 * 3600;
			$proj->statut      = Project::STATUS_DRAFT;
			$proj->usage_opportunity = 1;
			$proj->public      = 1;
			$proj->fk_user_creat = $fuser->id;
			$proj->budget_amount = mt_rand(5000, 50000);
			$proj->opp_amount    = $proj->budget_amount * (mt_rand(80, 120) / 100);
			$proj->array_options = array(
				'options_rental_ltrproject' => 2,
				'options_rental_ltr_sales_billing' => $salesBilling
			);
			if (!empty($socids)) {
				$proj->socid = $socids[array_rand($socids)];
			}
			$result = $proj->create($fuser);
			if ($result > 0) {
				if ($nbWh > 0) {
					require_once DOL_DOCUMENT_ROOT . '/product/stock/class/entrepot.class.php';
					for ($w = 1; $w <= $nbWh; $w++) {
						$wh = new Entrepot($db);
						$wh->ref = $proj->ref . '-WH' . sprintf('%02d', $w);
						$wh->label = 'Entrepôt LLD ' . $proj->ref . ' - ' . $w;
						$wh->description = 'Généré automatiquement et lié au projet ' . $proj->ref;
						$wh->lieu = 'Sur site';
						$wh->statut = 1;
						$wh->fk_project = $proj->id;
						$wh->create($fuser);
					}
				}
				$sql = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."facture_rec WHERE fk_projet = ".$proj->id;
				$resRec = $db->query($sql);
				$nbRec = ($resRec && $objRec = $db->fetch_object($resRec)) ? $objRec->nb : 0;

				$sql = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."entrepot WHERE fk_project = ".$proj->id;
				$resWh = $db->query($sql);
				$nbWhActual = ($resWh && $objWh = $db->fetch_object($resWh)) ? $objWh->nb : 0;

				dsLog('✔ #' . $s . ' | ' . $proj->ref . ' | ' . $proj->title . ' | ' . $oppStatus['label'] . ' | ' . number_format((int)$proj->opp_amount, 0, ',', ' ') . ' € | ' . number_format((int)$proj->budget_amount, 0, ',', ' ') . ' € | ' . $nbWhActual . ' | ' . $nbRec, 'success');
				$ok++;
			} else {
				$errStr = $proj->error ?: (is_array($proj->errors) ? join(', ', $proj->errors) : 'Erreur inconnue');
				dsLog('✘ #' . $s . ' — ' . $errStr, 'error');
				$ko++;
			}
			if ($s % 5 === 0 || $s === $nb - 1) dolinstreamProgress($s + 1, $nb);
		}
		dolinstreamProgress($nb, $nb, true);
		dsLog('─── ' . $ok . ' OK, ' . $ko . ' erreur(s) ───');

	// ════════════════════════════════════════════════════════════════════════
	} elseif ($script === 'generate-rental-order') {
	// ════════════════════════════════════════════════════════════════════════
		$fk_project = GETPOST('fk_project', 'int');
		$product_ids = GETPOST('product_ids', 'array');
		$qty_mode = GETPOST('qty_mode', 'alpha');
		$qty_val  = max(1, GETPOST('qty_val', 'int'));
		
		if (empty($fk_project)) { dsLog('❌ Projet non sélectionné', 'error'); goto render; }
		if (empty($product_ids)) { dsLog('❌ Aucun produit sélectionné', 'error'); goto render; }

		$project = new Project($db);
		if ($project->fetch($fk_project) <= 0) { dsLog('❌ Projet introuvable', 'error'); goto render; }
		if (empty($project->socid)) { dsLog('❌ Le projet sélectionné doit être lié à un tiers', 'error'); goto render; }
		
		$dates = dolinstreamGetRandomDates();
		dsLog('Générer des commandes de location : ' . $nb);
		$ok = $ko = 0;

		for ($s = 0; $s < $nb; $s++) {
			$obj = new Commande($db);
			$obj->socid = $project->socid;
			$obj->date_commande = $dates[array_rand($dates)];
			$obj->note_private = 'Commande de location générée par DoliStream';
			$obj->source = 1;
			$obj->fk_project = $project->id;
			$obj->remise_percent = 0;
			$obj->shipping_method_id = mt_rand(1, 2);
			$obj->cond_reglement_id = mt_rand(1, 3);
			$obj->availability_id = mt_rand(0, 1);

			$result = $obj->create($fuser);
			if ($result >= 0) {
				$lineOk = true;
				foreach ($product_ids as $pid) {
					$product = new Product($db);
					if ($product->fetch($pid) > 0) {
						$qty = ($qty_mode === 'random') ? mt_rand(1, $qty_val) : $qty_val;
						$r = $obj->addline(
							$product->description,
							$product->price,
							$qty,
							$product->tva_tx ?? 20,
							0, 0,
							$pid,
							0, 0, 0,
							$product->price_base_type,
							$product->price_ttc,
							'', '',
							$product->type
						);
						if ($r < 0) { $lineOk = false; break; }
					}
				}
				if ($lineOk) {
					$obj->fetch($obj->id);
					$obj->fetch_thirdparty();
					$obj->valid($fuser);
					dsLog('✔ #' . $s . ' | ' . $obj->ref . ' | Projet: ' . $project->ref . ' | ' . count($product_ids) . ' produit(s) | ' . htmlspecialchars($obj->thirdparty->nom) . ' | ' . dol_print_date($obj->date_commande, 'day') . ' | ' . number_format((float)$obj->total_ht, 2, ',', ' ') . ' €', 'success');
					$ok++;
				} else {
					$obj->delete($fuser);
					dsLog('✘ #' . $s . ' — Erreur lors de l\'ajout des lignes. Commande annulée.', 'error');
					$ko++;
				}
			} else {
				$errStr = $obj->error ?: (is_array($obj->errors) ? join(', ', $obj->errors) : 'Erreur inconnue');
				dsLog('✘ #' . $s . ' — ' . $errStr, 'error');
				$ko++;
			}
			if ($s % 5 === 0 || $s === $nb - 1) dolinstreamProgress($s + 1, $nb);
		}
		dolinstreamProgress($nb, $nb, true);
		dsLog('─── ' . $ok . ' OK, ' . $ko . ' erreur(s) ───');

	// ════════════════════════════════════════════════════════════════════════
	} elseif ($script === 'generate-rental-workflow') {
	// ════════════════════════════════════════════════════════════════════════
		if (!isModEnabled('expedition') || !isModEnabled('productreturn')) {
			dsLog('❌ Modules Expéditions et ProductReturn requis.', 'error');
			goto render;
		}
		
		$fk_project = GETPOST('fk_project', 'int');
		$grid_exp = GETPOST('grid_exp', 'array');
		$grid_ret = GETPOST('grid_ret', 'array');
		$gen_rec = GETPOST('gen_recurring_invoices', 'int');
		$ajax_run = GETPOST('ajax_run', 'int');
		
		if ($fk_project <= 0) { dsLog('❌ Projet de location requis.', 'error'); goto render; }
		
		require_once DOL_DOCUMENT_ROOT . '/expedition/class/expedition.class.php';
		require_once DOL_DOCUMENT_ROOT . '/custom/productreturn/class/productreturn.class.php';
		require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';
		require_once DOL_DOCUMENT_ROOT . '/projet/class/project.class.php';
		
		$project = new Project($db);
		if ($project->fetch($fk_project) <= 0) { dsLog('❌ Projet introuvable.', 'error'); goto render; }
		
		// Force negative stock to allow simulation without stock constraints
		global $conf;
		if (empty($conf->global->STOCK_ALLOW_NEGATIVE_TRANSFER)) {
			require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
				dolibarr_set_const($db, 'STOCK_ALLOW_NEGATIVE_TRANSFER', '1', 'chaine', 0, '', $conf->entity);
			dsLog('ℹ️ Option "Autoriser les stocks négatifs" activée automatiquement.', 'info');
		}

		$expeditions_by_group = array();
		$returns_by_group = array();
		
		$sqlc = "SELECT rowid FROM " . MAIN_DB_PREFIX . "commande WHERE fk_projet=" . $fk_project . " AND fk_statut IN(1,2,3)";
		$resc = $db->query($sqlc);
		
		$orders_lines = array();
		while ($resc && $objcmd = $db->fetch_object($resc)) {
			$cmd = new Commande($db);
			if ($cmd->fetch($objcmd->rowid) > 0) {
			    $cmd->fetch_thirdparty();
			    foreach($cmd->lines as $l) {
			        $orders_lines[$l->id] = array('cmd' => clone $cmd, 'line' => clone $l);
			    }
			}
		}
		
		if (is_array($grid_exp)) {
		    foreach($grid_exp as $line_id => $months) {
		        if (isset($orders_lines[$line_id])) {
		            $lineData = $orders_lines[$line_id];
		            $qty_total = $lineData['line']->qty;
		            foreach($months as $month_str => $blocks) {
		                foreach($blocks as $idx => $b) {
                            $pct = (float)($b['pct'] ?? 0);
                            $fixed_qty = (float)($b['fixed_qty'] ?? 0);
                            if ($fixed_qty > 0 || $pct > 0) {
                                $qty_to_ship = $fixed_qty > 0 ? $fixed_qty : max(1, round(($qty_total * $pct) / 100));
                                $date_str = !empty($b['date']) ? $b['date'] : $month_str . '-01';
                                $wh_id = !empty($b['wh']) ? (int)$b['wh'] : 1;
                                $group_key = $date_str . '|' . $wh_id;
                                $expeditions_by_group[$group_key][$lineData['cmd']->id][] = array('line' => $lineData['line'], 'qty' => $qty_to_ship, 'cmd' => $lineData['cmd'], 'date' => $date_str, 'wh_id' => $wh_id, 'col' => $month_str);
                            }
                        }
		            }
		        }
		    }
		}
		
		if (is_array($grid_ret)) {
		    foreach($grid_ret as $line_id => $months) {
		        if (isset($orders_lines[$line_id])) {
		            $lineData = $orders_lines[$line_id];
		            $qty_total = $lineData['line']->qty;
		            foreach($months as $month_str => $blocks) {
		                foreach($blocks as $idx => $b) {
                            $pct = (float)($b['pct'] ?? 0);
                            $fixed_qty = (float)($b['fixed_qty'] ?? 0);
                            if ($fixed_qty > 0 || $pct > 0) {
                                $qty_to_ret = $fixed_qty > 0 ? $fixed_qty : max(1, round(($qty_total * $pct) / 100));
                                $date_str = !empty($b['date']) ? $b['date'] : $month_str . '-01';
                                $wh_id = !empty($b['wh']) ? (int)$b['wh'] : 1;
                                $group_key = $date_str . '|' . $wh_id;
                                $returns_by_group[$group_key][$lineData['cmd']->id][] = array('line' => $lineData['line'], 'qty' => $qty_to_ret, 'cmd' => $lineData['cmd'], 'date' => $date_str, 'wh_id' => $wh_id, 'col' => $month_str);
                            }
                        }
		            }
		        }
		    }
		}
		
		$ok = 0; $ko = 0;
		$createdObjects = array();
		
		foreach($expeditions_by_group as $group_key => $cmds) {
		    foreach($cmds as $cmd_id => $linesToShip) {
		        $cmd = $linesToShip[0]['cmd'];
                $mdate = strtotime($linesToShip[0]['date']);
                $wh_id = $linesToShip[0]['wh_id'];
                
		        $exp = new Expedition($db);
				$exp->socid = $cmd->socid;
				$exp->origin = 'commande';
				$exp->origin_id = $cmd->id;
				$exp->date_creation = $mdate;
				$exp->date_delivery = $mdate;
				$exp->array_options = array('options_rental_date_start' => $mdate);
				
				foreach ($linesToShip as $l) {
				    $expline = new ExpeditionLigne($db);
					$expline->entrepot_id = $wh_id;
					$expline->origin_line_id = $l['line']->id;
					$expline->qty = $l['qty'];
					$expline->rang = $l['line']->rang;
					$exp->lines[] = $expline;
				}
				$r = $exp->create($fuser);
				if ($r > 0) {
					$exp->valid($fuser);
					dsLog('✔ Expédition ' . $exp->ref . ' créée pour ' . dol_print_date($mdate, 'day') . ' (Entrepôt ID: '.$wh_id.') | Cmd: ' . $cmd->ref, 'success');
					$ok++;
                    foreach ($linesToShip as $l) {
                        $createdObjects[] = array(
                            'type' => 'exp',
                            'col' => $l['col'],
                            'line_id' => $l['line']->id,
                            'qty' => $l['qty'],
                            'date' => dol_print_date($mdate, 'day'),
                            'url' => $exp->getNomUrl(1)
                        );
                    }
				} else {
				    dsLog('✘ Erreur expédition: ' . $exp->error, 'error');
					$ko++;
				}
		    }
		}
		
		foreach($returns_by_group as $group_key => $cmds) {
		    foreach($cmds as $cmd_id => $linesToRet) {
		        $cmd = $linesToRet[0]['cmd'];
                $mdate = strtotime($linesToRet[0]['date']);
                $wh_id = $linesToRet[0]['wh_id'];
                
		        $pr = new Productreturn($db);
				$pr->socid = $cmd->socid;
				$pr->origin = 'commande';
				$pr->origin_id = $cmd->id;
				$pr->fk_projet = $cmd->fk_project;
				$pr->date_return = $mdate;
				$pr->statut = 0;
				$pr->brouillon = 1;
				$pr->array_options = array('options_rental_date_end' => $mdate);
				
				$res = $pr->create($fuser);
				if ($res > 0) {
				    foreach ($linesToRet as $l) {
				        $pr->addline($wh_id, $l['line']->id, $l['line']->fk_product, $l['qty'], $l['qty'], $l['qty'], array(), '', false);
				    }
				    $pr->valid($fuser);
					dsLog('✔ Retour ' . $pr->ref . ' créé pour ' . dol_print_date($mdate, 'day') . ' | Cmd: ' . $cmd->ref . ' | Entrepôt: ' . $wh_id, 'success');
					$ok++;
                    foreach ($linesToRet as $l) {
                        $createdObjects[] = array(
                            'type' => 'ret',
                            'col' => $l['col'],
                            'line_id' => $l['line']->id,
                            'qty' => $l['qty'],
                            'date' => dol_print_date($mdate, 'day'),
                            'url' => $pr->getNomUrl(1)
                        );
                    }
				} else {
				    dsLog('✘ Erreur retour: ' . $pr->error, 'error');
					$ko++;
				}
		    }
		}
		
		if ($gen_rec) {
		    $resRec = $db->query("SELECT rowid, ref FROM " . MAIN_DB_PREFIX . "facture_rec WHERE fk_projet=" . $fk_project);
		    while ($resRec && $objRec = $db->fetch_object($resRec)) {
		        dsLog('ℹ️ Facture récurrente trouvée : ' . $objRec->ref . ' (Prise en compte par les crons)', 'info');
		    }
		}
		
		dsLog('─── ' . $ok . ' opérations créées, ' . $ko . ' erreur(s) ───');

		if ($ajax_run) {
		    // Prepare logs for JSON
		    $jsonLogs = array();
		    foreach($scriptLog as $l) {
		        $clsMap = array('success'=>'ds-log-s', 'error'=>'ds-log-e', 'warn'=>'ds-log-w', 'info'=>'ds-log-i');
		        $jsonLogs[] = array('time'=>$l['time'], 'cls'=>($clsMap[$l['level']]??'ds-log-i'), 'msg'=>$l['msg']);
		    }
		    header('Content-Type: application/json');
		    echo json_encode(array('logs' => $jsonLogs, 'created' => $createdObjects));
		    exit;
		}

	} elseif ($script === 'workflow-opp-cl-pr') {
	// ════════════════════════════════════════════════════════════════════════
		// Workflow : chaque itération crée ① un client ② une opportunité liée
		// à ce client ③ un devis rattaché à l'opportunité et au client.
		if (!isModEnabled('project')) {
			dsLog('❌ Module Projets requis pour créer des opportunités.', 'error');
			goto render;
		}
		if (!isModEnabled('propal')) {
			dsLog('❌ Module Propositions commerciales requis pour créer des devis.', 'error');
			goto render;
		}

		$nbLinesOpt = max(1, (int) (GETPOST('nb_lines', 'int') ?: 2));
		$prodids    = dolinstreamGetProductIds($db);
		if (empty($prodids)) {
			dsLog('⚠ Aucun produit en vente — les devis seront créés sans ligne.', 'warn');
		}

		// Module de numérotation projet (même pattern que generate-project)
		$addonName  = getDolGlobalString('PROJECT_ADDON', 'mod_project_simple');
		$addonFile  = DOL_DOCUMENT_ROOT . '/core/modules/project/' . $addonName . '.php';
		$modProject = null;
		if (file_exists($addonFile)) {
			require_once $addonFile;
			$modProject = new $addonName();
		}

		$listoftown = array('Paris', 'Lyon', 'Marseille', 'Bordeaux', 'Nantes', 'Toulouse', 'Strasbourg', 'Lille', 'Rennes', 'Vannes');
		// Statuts d'opportunité (llx_c_lead_status) — cycle ouvert uniquement
		$oppStatuses = array(
			1 => array('label' => 'Prospection', 'pct' => mt_rand(10, 25)),
			2 => array('label' => 'Qualifié',    'pct' => mt_rand(25, 45)),
			3 => array('label' => 'Proposition', 'pct' => mt_rand(40, 65)),
			4 => array('label' => 'Négociation', 'pct' => mt_rand(60, 85)),
		);

		dsLog('Workflow OPP+CL+PR : ' . $nb . ' exécution(s) — Client → Opportunité → Devis');
		$ok = $ko = 0;

		for ($s = 0; $s < $nb; $s++) {
			dsLog('── Workflow #' . ($s + 1) . ' ──');

			// ① CLIENT ────────────────────────────────────────────────────────
			$soc               = new Societe($db);
			$soc->name         = 'Client WF ' . dol_print_date(dol_now(), 'dayhour') . '-' . $s;
			$soc->town         = $listoftown[array_rand($listoftown)];
			$soc->client       = 1;
			$soc->fournisseur  = 0;
			$soc->code_client  = -1; // numérotation auto
			$soc->tva_assuj    = 1;
			$soc->country_id   = 1;
			$soc->country_code = 'FR';
			$soc->note_private = 'Créé par DoliStream (workflow OPP+CL+PR)';

			$socid = $soc->create($fuser);
			if ($socid <= 0) {
				dsLog('✘ #' . $s . ' — création client : ' . $soc->error, 'error');
				$ko++;
				continue;
			}
			dsLog('① Client : ' . $soc->name . ' (id=' . $socid . ')');

			// ② OPPORTUNITÉ liée au client ────────────────────────────────────
			$oppKey    = array_rand($oppStatuses);
			$oppStatus = $oppStatuses[$oppKey];

			$proj         = new Project($db);
			$proj->date_c = dol_now();
			$projRef      = ($modProject !== null) ? $modProject->getNextValue(null, $proj) : '';
			if (!$projRef || (is_numeric($projRef) && (int) $projRef <= 0)) {
				dsLog('✘ #' . $s . ' — référence projet impossible via ' . $addonName, 'error');
				$ko++;
				continue;
			}

			$proj->ref               = $projRef;
			$proj->title             = 'Opportunité ' . $soc->name;
			$proj->description       = 'Généré par DoliStream (workflow OPP+CL+PR)';
			$proj->date_start        = $proj->date_c;
			$proj->date_end          = $proj->date_start + mt_rand(30, 180) * 24 * 3600;
			$proj->statut            = Project::STATUS_VALIDATED;
			$proj->usage_opportunity = 1;
			$proj->opp_status        = $oppKey;
			$proj->opp_percent       = $oppStatus['pct'];
			$proj->opp_amount        = 0; // recalé sur le total HT du devis après validation
			$proj->public            = 1;
			$proj->socid             = $socid;
			$proj->fk_user_creat     = $fuser->id;

			if ($proj->create($fuser) <= 0) {
				dsLog('✘ #' . $s . ' — création opportunité : ' . $proj->error, 'error');
				$ko++;
				continue;
			}
			dsLog('② Opportunité : ' . $proj->ref . ' | ' . $oppStatus['label'] . ' (' . $proj->opp_percent . '%) | client=' . $soc->name);

			// ③ DEVIS sur l'opportunité ───────────────────────────────────────
			$devis                    = new Propal($db);
			$devis->socid             = $socid;
			$devis->date              = dol_now();
			$devis->date_fin_validite = $devis->date + (30 * 24 * 3600);
			$devis->cond_reglement_id = 3;
			$devis->mode_reglement_id = 3;
			$devis->fk_project        = $proj->id;

			if ($devis->create($fuser) < 0) {
				dsLog('✘ #' . $s . ' — création devis : ' . $devis->error, 'error');
				$ko++;
				continue;
			}

			for ($l = 0; $l < $nbLinesOpt; $l++) {
				if (empty($prodids)) break;
				$pid     = $prodids[array_rand($prodids)];
				$product = new Product($db);
				$product->fetch($pid);
				$rLine = $devis->addline(
					$product->description ?: $product->label,
					$product->price,
					mt_rand(1, 5),
					$product->tva_tx ?? 20,
					0, 0,
					$pid,
					0,
					$product->price_base_type,
					$product->price_ttc,
					0,
					$product->type
				);
				if ($rLine < 0) dsLog('⚠ addline pid=' . $pid . ' : ' . $devis->error, 'warn');
			}

			// Même pattern que generate-proposal : recharge complète avant valid()
			$devis->fetch($devis->id);
			$devis->fetch_thirdparty();
			$devis->fetch_lines();
			if ($devis->valid($fuser) <= 0) {
				dsLog('✘ #' . $s . ' — validation devis : ' . $devis->error, 'error');
				$ko++;
				continue;
			}
			$devis->fetch($devis->id);
			$ht = price2num($devis->total_ht, 'MT');

			// Recale le montant de l'opportunité sur le total HT du devis
			$db->query('UPDATE ' . MAIN_DB_PREFIX . 'projet SET opp_amount = ' . ((float) $devis->total_ht) . ', budget_amount = ' . ((float) $devis->total_ht) . ' WHERE rowid = ' . ((int) $proj->id));

			dsLog('③ Devis : ' . $devis->ref . ' | HT=' . $ht . ' | opp=' . $proj->ref);
			dsLog('✔ #' . $s . ' | ' . $soc->name . ' | ' . $proj->ref . ' | ' . $devis->ref . ' | HT=' . $ht, 'success');
			$ok++;

			if ($s % 5 === 0 || $s === $nb - 1) {
				dolinstreamProgress($s + 1, $nb);
			}
		}
		dolinstreamProgress($nb, $nb, true);
		dsLog('─── ' . $ok . ' OK, ' . $ko . ' erreur(s) ───');

	// ─
	} elseif ($script === 'generate-warehouse') {
	// ─
		require_once DOL_DOCUMENT_ROOT . '/product/stock/class/entrepot.class.php';

		$prefix = GETPOST('prefix', 'alpha') ?: 'WH';
		$prefix = preg_replace('/[^A-Za-z0-9_-]/', '', strtoupper($prefix));
		if (empty($prefix)) $prefix = 'WH';

		dsLog('Générer des entrepôts : ' . $nb . ' (préfixe=' . $prefix . ')');
		$ok = $ko = 0;

		for ($s = 1; $s <= $nb; $s++) {
			$wh              = new Entrepot($db);
			$wh->ref         = $prefix . '-' . sprintf('%03d', $s);
			$wh->label       = 'Entrepôt ' . $prefix . '-' . sprintf('%03d', $s);
			$wh->description = 'Généré automatiquement par DoliStream';
			$wh->lieu        = 'DoliStream';
			$wh->address     = $s . ' rue de la Génération';
			$wh->zip         = '75' . sprintf('%03d', $s);
			$wh->town        = 'Paris';
			$wh->country_id  = 1;
			$wh->statut      = 1;

			$whid = $wh->create($fuser);
			if ($whid > 0) {
				dsLog('✓ #' . $s . ' | ' . $wh->ref . ' | ' . $wh->label . ' | ' . $wh->town, 'success');
				$ok++;
			} else {
				dsLog('✗ #' . $s . ' - ' . $wh->error, 'error');
				$ko++;
			}
		}
		dsLog('═ ' . $ok . ' OK, ' . $ko . ' erreur(s) ═');

	// ─
	} elseif ($script === 'generate-stock') {
	// ─
		require_once DOL_DOCUMENT_ROOT . '/product/stock/class/mouvementstock.class.php';

		$batchMode   = GETPOST('batch_mode',   'alpha') ?: 'none';
		$productType = GETPOST('product_type', 'alpha') ?: 'all';
		$qtyMax      = max(1, (int) GETPOST('qty_max', 'int') ?: 50);

		$hasBatchModule = isModEnabled('productbatch');

		$prodIds = dolinstreamGetStockableProductIds($db, $productType);
		$whIds   = dolinstreamGetWarehouseIds($db);

		if (empty($prodIds)) { dsLog('✗ Aucun produit trouvé. Générez des produits d\'abord.', 'error'); goto render; }
		if (empty($whIds))   { dsLog('✗ Aucun entrepôt ouvert. Créez-en via Pré-requis > Entrepôt.', 'error'); goto render; }

		// Cache noms entrepôts
		$whNames = array();
		foreach ($whIds as $wid) {
			$resql = $db->query('SELECT ref FROM ' . MAIN_DB_PREFIX . 'entrepot WHERE rowid=' . (int)$wid);
			if ($resql && ($owh = $db->fetch_object($resql))) $whNames[$wid] = $owh->ref;
		}

		if ($batchMode !== 'none' && !$hasBatchModule) {
			dsLog('⚠ Module Lots/Séries non activé → mode "sans lot/série" utilisé', 'warn');
			$batchMode = 'none';
		}

		dsLog('Générer du stock : ' . $nb . ' mouvements | type=' . $productType . ' | mode=' . $batchMode . ' | qtyMax=' . $qtyMax);
		$ok = $ko = 0;

		for ($s = 1; $s <= $nb; $s++) {
			$productId = $prodIds[array_rand($prodIds)];
			$whId      = $whIds[array_rand($whIds)];
			$whName    = $whNames[$whId] ?? ('#' . $whId);
			$qty       = mt_rand(1, $qtyMax);

			$product = new Product($db);
			$product->fetch($productId);

			$batchStr = '';

			if ($batchMode === 'lot' && $product->type === 0) {
				// Force tobatch=1 uniquement sur produits physiques
				if ((int)$product->tobatch < 1) {
					$db->query('UPDATE ' . MAIN_DB_PREFIX . 'product SET tobatch=1 WHERE rowid=' . (int)$productId);
					$product->tobatch = 1;
				}
				$batchStr = 'LOT-' . date('Ymd') . '-' . sprintf('%04d', $s);
				$mouvement = new MouvementStock($db);
				$res = $mouvement->_create($fuser, $productId, $whId, $qty, 0, $product->price, 'DoliStream stock', '', '', 0, 0, $batchStr);
				if ($res > 0) {
					dsLog('✓ #' . $s . ' | ' . $product->ref . ' | ' . $whName . ' | +' . $qty . ' | ' . $batchStr, 'success');
					$ok++;
				} else {
					dsLog('✗ #' . $s . ' [' . $product->ref . '] ' . $mouvement->error, 'error');
					$ko++;
				}

			} elseif ($batchMode === 'serial' && $product->type === 0) {
				// Force tobatch=2 uniquement sur produits physiques
				// Règle Dolibarr : qty=1 par mouvement, un SN unique par unité
				if ((int)$product->tobatch < 2) {
					$db->query('UPDATE ' . MAIN_DB_PREFIX . 'product SET tobatch=2 WHERE rowid=' . (int)$productId);
					$product->tobatch = 2;
				}
				$serials  = array();
				$ok_unit  = 0;
				for ($u = 1; $u <= $qty; $u++) {
					$serial = 'SN-' . strtoupper(substr(md5(uniqid('', true)), 0, 8));
					$serials[] = $serial;
					$mouvement = new MouvementStock($db);
					// qty forcé à 1 — règle numéro de série Dolibarr
					$res = $mouvement->_create($fuser, $productId, $whId, 1, 0, $product->price, 'DoliStream stock', '', '', 0, 0, $serial);
					if ($res > 0) $ok_unit++;
				}
				$batchStr = implode(', ', array_slice($serials, 0, 3)) . ($qty > 3 ? '…' : '');
				if ($ok_unit > 0) {
					dsLog('✓ #' . $s . ' | ' . $product->ref . ' | ' . $whName . ' | +' . $qty . ' SN (qty=1/unité) | ' . $batchStr, 'success');
					$ok++;
				} else {
					dsLog('✗ #' . $s . ' [' . $product->ref . '] série impossible', 'error');
					$ko++;
				}

			} else {
				// Sans lot ni série
				$mouvement = new MouvementStock($db);
				$res = $mouvement->_create($fuser, $productId, $whId, $qty, 0, $product->price, 'DoliStream stock');
				if ($res > 0) {
					dsLog('✓ #' . $s . ' | ' . $product->ref . ' | ' . $whName . ' | +' . $qty, 'success');
					$ok++;
				} else {
					dsLog('✗ #' . $s . ' [' . $product->ref . '] ' . $mouvement->error, 'error');
					$ko++;
				}
			}
		}
		dsLog('═ ' . $ok . ' OK, ' . $ko . ' erreur(s) ═');

	// ════════════════════════════════════════════════════════════════════════
	} elseif ($script === 'generate-expedition') {
	// ════════════════════════════════════════════════════════════════════════
		$orderIds = dolinstreamGetClientOrderIds($db);

		if (empty($orderIds)) {
			dsLog('❌ Aucune commande expédiable trouvée. Générez des commandes client (contenant des produits physiques) d\'abord.', 'error');
			goto render;
		}

		dsLog('Générer des expéditions : ' . $nb);
		$ok = $ko = 0;

		for ($s = 0; $s < $nb; $s++) {
			$orderId = $orderIds[array_rand($orderIds)];
			$order   = new Commande($db);
			if ($order->fetch($orderId) <= 0 || $order->fetch_lines() < 0) {
				dsLog('✘ #' . $s . ' — Impossible de charger la commande #' . $orderId, 'error');
				$ko++;
				continue;
			}

			$exp = new Expedition($db);
			$exp->socid     = $order->socid;
			$exp->origin    = 'commande';
			$exp->origin_id = $orderId;
			$exp->date_delivery = dol_now();
			$exp->note_private  = 'Généré par DoliStream';
			$exp->fk_project    = 0;

			$expid = $exp->create($fuser);
			if ($expid <= 0) {
				dsLog('✘ #' . $s . ' — create : ' . $exp->error, 'error');
				$ko++;
				continue;
			}

			// Ajouter les lignes depuis la commande
			foreach ($order->lines as $line) {
				if (empty($line->fk_product)) continue;
				// addline($entrepot_id, $id_order_line, $qty, $array_options, $fk_product)
				$exp->addline(0, $line->id, (float) $line->qty, array(), (int) $line->fk_product);
			}

			if ($exp->valid($fuser) > 0) {
				dsLog('✔ #' . $s . ' | ' . $exp->ref . ' | soc=' . $exp->socid . ' | ' . dol_print_date($exp->date_delivery, 'day'), 'success');
				$ok++;
			} else {
				dsLog('✘ #' . $s . ' — valid : ' . $exp->error, 'error');
				$ko++;
			}
		}
		dsLog('─── ' . $ok . ' OK, ' . $ko . ' erreur(s) ───');

	// ════════════════════════════════════════════════════════════════════════
	} elseif ($script === 'generate-supplier-order') {
	// ════════════════════════════════════════════════════════════════════════
		$supplierIds = dolinstreamGetSupplierIds($db);
		$prodIds     = dolinstreamGetBuyProductIds($db);

		if (empty($supplierIds)) {
			dsLog('❌ Aucun fournisseur trouvé. Générez des tiers de type Fournisseur d\'abord.', 'error');
			goto render;
		}

		$dates = dolinstreamGetRandomDates();
		dsLog('Générer des commandes fournisseur : ' . $nb);
		$ok = $ko = 0;

		for ($s = 0; $s < $nb; $s++) {
			$socid = $supplierIds[array_rand($supplierIds)];

			$obj = new CommandeFournisseur($db);
			$obj->socid         = $socid;
			$obj->date_commande = $dates[array_rand($dates)];
			$obj->note_private  = 'Généré par DoliStream';
			$obj->source        = 0;

			$result = $obj->create($fuser);
			if ($result <= 0) {
				dsLog('✘ #' . $s . ' — create : ' . $obj->error, 'error');
				$ko++;
				continue;
			}

			// Ajouter des lignes
			$nbLines = mt_rand(1, 4);
			for ($l = 0; $l < $nbLines; $l++) {
				if (empty($prodIds)) break;
				$pid     = $prodIds[array_rand($prodIds)];
				$product = new Product($db);
				$product->fetch($pid);
				$obj->addline(
					$socid,
					$product->description ?: $product->label,
					$product->price_min > 0 ? $product->price_min : $product->price * 0.7,
					$product->tva_tx ?? 20,
					0, 0,
					mt_rand(1, 10),
					$pid,
					'',
					$product->price_base_type,
					$product->price * 0.7,
					0,
					$product->type
				);
			}

			$obj->fetch($obj->id);
			$obj->fetch_thirdparty();
			$obj->fetch_lines();
			if ($obj->valid($fuser, 0) >= 0) {
				$obj->fetch($obj->id);
				$ht = price2num($obj->total_ht, 'MT');
				dsLog('✔ #' . $s . ' | ' . $obj->ref . ' | soc=' . $socid . ' | ' . dol_print_date($obj->date_commande, 'day') . ' | HT=' . $ht, 'success');
				$ok++;
			} else {
				dsLog('✘ #' . $s . ' — valid : ' . $obj->error, 'error');
				$ko++;
			}
		}
		dsLog('─── ' . $ok . ' OK, ' . $ko . ' erreur(s) ───');

	// ════════════════════════════════════════════════════════════════════════
	} elseif ($script === 'generate-reception') {
	// ════════════════════════════════════════════════════════════════════════
		if (!class_exists('Reception')) {
			dsLog('❌ Module Réception non disponible dans cette version de Dolibarr.', 'error');
			goto render;
		}

		$orderIds = dolinstreamGetSupplierOrderIds($db);

		if (empty($orderIds)) {
			dsLog('❌ Aucune commande fournisseur validée. Générez des commandes fournisseur d\'abord.', 'error');
			goto render;
		}

		dsLog('Générer des réceptions fournisseur : ' . $nb);
		$ok = $ko = 0;

		for ($s = 0; $s < $nb; $s++) {
			$orderId = $orderIds[array_rand($orderIds)];
			$order   = new CommandeFournisseur($db);
			if ($order->fetch($orderId) <= 0 || $order->fetch_lines() < 0) {
				dsLog('✘ #' . $s . ' — Commande fourn #' . $orderId . ' introuvable', 'error');
				$ko++;
				continue;
			}

			$rec = new Reception($db);
			$rec->socid     = $order->socid;
			$rec->origin    = 'order_supplier';
			$rec->origin_id = $orderId;
			$rec->date_reception = dol_now();
			$rec->note_private   = 'Généré par DoliStream';

			$recid = $rec->create($fuser);
			if ($recid <= 0) {
				dsLog('✘ #' . $s . ' — create : ' . $rec->error, 'error');
				$ko++;
				continue;
			}

			if ($rec->valid($fuser) >= 0) {
				dsLog('✔ #' . $s . ' | ' . $rec->ref . ' | soc=' . $rec->socid . ' | ' . dol_print_date($rec->date_reception, 'day'), 'success');
				$ok++;
			} else {
				dsLog('✘ #' . $s . ' — valid : ' . $rec->error, 'error');
				$ko++;
			}
		}
		dsLog('─── ' . $ok . ' OK, ' . $ko . ' erreur(s) ───');

	// ════════════════════════════════════════════════════════════════════════
	} elseif ($script === 'generate-supplier-invoice') {
	// ════════════════════════════════════════════════════════════════════════
		$supplierIds = dolinstreamGetSupplierIds($db);
		$prodIds     = dolinstreamGetBuyProductIds($db);

		if (empty($supplierIds)) {
			dsLog('❌ Aucun fournisseur trouvé. Générez des tiers de type Fournisseur d\'abord.', 'error');
			goto render;
		}

		$dates = dolinstreamGetRandomDates();
		dsLog('Générer des factures fournisseur : ' . $nb);
		$ok = $ko = 0;

		for ($s = 0; $s < $nb; $s++) {
			$socid = $supplierIds[array_rand($supplierIds)];

			$obj = new FactureFournisseur($db);
			$obj->socid        = $socid;
			$obj->date         = $dates[array_rand($dates)];
			$obj->ref_supplier = 'FOURN-' . dol_print_date(dol_now(), '%Y%m') . '-' . str_pad($s, 4, '0', STR_PAD_LEFT);
			$obj->note_private = 'Généré par DoliStream';
			$obj->type         = FactureFournisseur::TYPE_STANDARD;

			$result = $obj->create($fuser);
			if ($result <= 0) {
				dsLog('✘ #' . $s . ' — create : ' . $obj->error, 'error');
				$ko++;
				continue;
			}

			$nbLines = mt_rand(1, 4);
			for ($l = 0; $l < $nbLines; $l++) {
				if (empty($prodIds)) break;
				$pid     = $prodIds[array_rand($prodIds)];
				$product = new Product($db);
				$product->fetch($pid);
				$unitPrice = $product->price_min > 0 ? $product->price_min : $product->price * 0.7;
				$obj->addline(
					$product->description ?: $product->label,
					$unitPrice,
					mt_rand(1, 10),
					$product->tva_tx ?? 20,
					0, 0, 0,
					$pid,
					'',
					$product->price_base_type,
					$product->price * 0.7,
					$product->type
				);
			}

			$obj->fetch($obj->id);
			$obj->fetch_thirdparty();
			$obj->fetch_lines();
			if ($obj->validate($fuser) >= 0) {
				$obj->fetch($obj->id);
				$ht  = price2num($obj->total_ht, 'MT');
				$ttc = price2num($obj->total_ttc, 'MT');
				dsLog('✔ #' . $s . ' | ' . $obj->ref . ' | soc=' . $socid . ' | ' . dol_print_date($obj->date, 'day') . ' | HT=' . $ht . ' | TTC=' . $ttc, 'success');
				$ok++;
			} else {
				dsLog('✘ #' . $s . ' — validate : ' . $obj->error, 'error');
				$ko++;
			}
		}
		dsLog('─── ' . $ok . ' OK, ' . $ko . ' erreur(s) ───');

	// ════════════════════════════════════════════════════════════════════════
	
	} elseif ($script === 'purge-data') {
	// ════════════════════════════════════════════════════════════════════════
		if (!$user->admin && !$user->hasRight('dolistream', 'purge', 'run')) {
			accessforbidden();
		}
		if (!in_array($mode, array('test', 'confirm'))) {
			dsLog('❌ Mode invalide. Utilisez "test" ou "confirm".', 'error');
			goto render;
		}

		$cutoff = ($date === 'all' || empty($date)) ? '2199-01-01' : $date;
		if ($date !== 'all' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $cutoff)) {
			dsLog('❌ Date invalide. Format attendu : YYYY-MM-DD ou "all".', 'error');
			goto render;
		}

		// Table des DELETE par famille
		$families = array(
			'user'     => array("DELETE FROM " . MAIN_DB_PREFIX . "user WHERE admin=0 AND login!='admin' AND datec<'__DATE__'"),
			'event'    => array("DELETE FROM " . MAIN_DB_PREFIX . "actioncomm WHERE datec<'__DATE__'"),
			'payment'  => array(
				"DELETE FROM " . MAIN_DB_PREFIX . "paiement_facture WHERE fk_facture IN (SELECT rowid FROM " . MAIN_DB_PREFIX . "facture WHERE datec<'__DATE__')",
				"DELETE FROM " . MAIN_DB_PREFIX . "paiement WHERE rowid NOT IN (SELECT fk_paiement FROM " . MAIN_DB_PREFIX . "paiement_facture)",
			),
			'invoice'  => array(
				'@payment',
				"DELETE FROM " . MAIN_DB_PREFIX . "facturedet WHERE fk_facture IN (SELECT rowid FROM " . MAIN_DB_PREFIX . "facture WHERE datec<'__DATE__')",
				"DELETE FROM " . MAIN_DB_PREFIX . "facture WHERE datec<'__DATE__'",
			),
			'proposal' => array(
				"DELETE FROM " . MAIN_DB_PREFIX . "propaldet WHERE fk_propal IN (SELECT rowid FROM " . MAIN_DB_PREFIX . "propal WHERE datec<'__DATE__')",
				"DELETE FROM " . MAIN_DB_PREFIX . "propal WHERE datec<'__DATE__'",
			),
			'order'    => array(
				"DELETE FROM " . MAIN_DB_PREFIX . "commandedet WHERE fk_commande IN (SELECT rowid FROM " . MAIN_DB_PREFIX . "commande WHERE date_creation<'__DATE__')",
				"DELETE FROM " . MAIN_DB_PREFIX . "commande WHERE date_creation<'__DATE__'",
			),
			'product'  => array(
				"DELETE FROM " . MAIN_DB_PREFIX . "product_price WHERE fk_product IN (SELECT rowid FROM " . MAIN_DB_PREFIX . "product WHERE datec<'__DATE__')",
				"DELETE FROM " . MAIN_DB_PREFIX . "product WHERE datec<'__DATE__'",
			),
			'contact'  => array(
				"DELETE FROM " . MAIN_DB_PREFIX . "socpeople WHERE datec<'__DATE__'",
			),
			'thirdparty' => array(
				'@contact',
				"DELETE FROM " . MAIN_DB_PREFIX . "societe WHERE datec<'__DATE__'",
			),
			'project'  => array(
				"DELETE FROM " . MAIN_DB_PREFIX . "projet_task WHERE fk_projet IN (SELECT rowid FROM " . MAIN_DB_PREFIX . "projet WHERE datec<'__DATE__')",
				"DELETE FROM " . MAIN_DB_PREFIX . "projet WHERE datec<'__DATE__'",
			),
			'bank'     => array(
				"DELETE FROM " . MAIN_DB_PREFIX . "bank WHERE datec<'__DATE__'",
			),
		);

		$toProcess = ($opt === 'all') ? array_keys($families) : array($opt);
		$processed = array();

		// Fonction récursive pour gérer les dépendances (@famille)
		$runFamily = function (string $fam) use (&$runFamily, $families, $cutoff, $mode, &$processed, $db, $langs): void {
			if (in_array($fam, $processed)) return;
			$processed[] = $fam;
			if (!isset($families[$fam])) {
				dsLog('⚠ Famille inconnue : ' . $fam, 'warn');
				return;
			}
			dsLog('── Famille : ' . $fam . ' ──');
			foreach ($families[$fam] as $sql) {
				if (preg_match('/^@(.+)$/', $sql, $m)) {
					$runFamily($m[1]);
					continue;
				}
				$sql = str_replace('__DATE__', $cutoff, $sql);
				if ($mode === 'test') {
					dsLog('[SIMULATION] ' . $sql, 'warn');
					dsLog('  → (mode test : rien ne sera supprimé)', 'warn');
				} else {
					dsLog($sql, 'info');
					$r = $db->query($sql);
					if (!$r) {
						dsLog('  ✘ Erreur SQL : ' . $db->lasterror(), 'error');
					} else {
						dsLog('  ✔ ' . $db->affected_rows($r) . ' ligne(s) supprimée(s)', 'success');
					}
				}
			}
		};

		if ($mode === 'confirm') $db->begin();
		foreach ($toProcess as $fam) $runFamily(trim($fam));
		if ($mode === 'confirm') {
			// Vérifier s'il y a des erreurs dans le log
			$hasError = count(array_filter($scriptLog, static fn($l) => $l['level'] === 'error')) > 0;
			if ($hasError) {
				$db->rollback();
				dsLog('✘ Erreurs détectées — ROLLBACK effectué', 'error');
			} else {
				$db->commit();
				dsLog('✔ Transaction validée (COMMIT)', 'success');
			}
		} else {
			dsLog('ℹ Mode test terminé — aucune donnée modifiée.', 'warn');
		}
	}
}

// Post-execution: console log + fichier + ActionComm
$dbResults   = array();
$dbHead      = array();
$dbUrl       = '';
$consoleText = '';
$acId        = 0;
$acLabel     = '';
$logFilePath = '';

if ($action === 'run' && !empty($scriptLog)) {
	$_scriptParams = 'nb=' . $nb;
	foreach (array('product_type','with_stock','batch_mode','prefix','qty_max','stock_qty_max') as $_p) {
		$_v = GETPOST($_p, 'alpha');
		if ($_v !== '') $_scriptParams .= ' | ' . $_p . '=' . $_v;
	}
	$acLabel = 'DoliStream > ' . $script . ' | ' . $_scriptParams;

	$consoleText  = 'Script     : ' . $script . "\n";
	$consoleText .= 'Parametres : ' . $_scriptParams . "\n";
	$consoleText .= 'Date       : ' . dol_print_date(dol_now(), 'dayhour') . "\n";
	$consoleText .= str_repeat('─', 70) . "\n";
	foreach ($scriptLog as $_le) {
		$_lic = $_le['level'] === 'success' ? '[OK] ' : ($_le['level'] === 'error' ? '[ERR]' : '[WRN]');
		$consoleText .= ($_le['time'] ?? date('H:i:s')) . ' ' . $_lic . ' ' . $_le['msg'] . "\n";
	}
	$consoleText .= str_repeat('─', 70) . "\n";
	$_okN  = count(array_filter($scriptLog, fn($l) => $l['level'] === 'success'));
	$_errN = count(array_filter($scriptLog, fn($l) => $l['level'] === 'error'));
	$consoleText .= $_okN . ' OK — ' . $_errN . ' erreur(s)' . "\n";

	$_logDir = DOL_DATA_ROOT . '/dolistream/';
	if (!is_dir($_logDir)) dol_mkdir($_logDir);
	$logFilePath = $_logDir . 'dolistream-' . preg_replace('/[^a-z0-9-]/', '', $script) . '-' . date('Ymd-His') . '.txt';
	file_put_contents($logFilePath, $consoleText);

	$_noteHtml = '<pre style="font-family:monospace;font-size:12px;background:#1e1e1e;color:#d4d4d4;padding:12px;">' . htmlspecialchars($consoleText) . '</pre>';
	$_ac = new ActionComm($db);
	$_ac->type_code      = 'AC_OTH_AUTO';
	$_ac->label          = $acLabel;
	$_ac->note_private   = $_noteHtml;
	$_ac->datep          = dol_now();
	$_ac->fk_user_action = $fuser->id;
	$_ac->percentage     = 100;
	$acId = (int)$_ac->create($fuser);
}

// ══ Mode AJAX : retourner JSON et quitter avant tout rendu HTML ═══════════════
// Appelé depuis ajax/run.php qui a défini DOLISTREAM_AJAX_RUN.
if (defined('DOLISTREAM_AJAX_RUN')) {
	if ($action === 'run') {
		$__ok   = count(array_filter($scriptLog, static fn($l) => $l['level'] === 'success'));
		$__ko   = count(array_filter($scriptLog, static fn($l) => $l['level'] === 'error'));
		$__warn = count(array_filter($scriptLog, static fn($l) => $l['level'] === 'warn'));
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store');
		echo json_encode([
			'ok'           => $__ok,
			'ko'           => $__ko,
			'warn'         => $__warn,
			'total'        => count($scriptLog),
			'prev_max_rowid' => $preExecMaxRowid,
		], JSON_UNESCAPED_UNICODE);
	} else {
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(['error' => 'Expected action=run, got: ' . htmlspecialchars($action ?? '')]);
	}
	$db->close();
	exit;
}

$logFilePath = '';
render:

// ── Stats base de données ────────────────────────────────────────────────────
$statsMap = array(
	'thirdparties' => array('icon' => '🏢', 'label' => $langs->transnoentitiesnoconv('StatsThirdparties'), 'sql' => 'SELECT COUNT(*) FROM ' . MAIN_DB_PREFIX . 'societe'),
	'products'     => array('icon' => '🏷️', 'label' => $langs->transnoentitiesnoconv('StatsProducts'),     'sql' => 'SELECT COUNT(*) FROM ' . MAIN_DB_PREFIX . 'product'),
	'invoices'     => array('icon' => '🧾', 'label' => $langs->transnoentitiesnoconv('StatsInvoices'),      'sql' => 'SELECT COUNT(*) FROM ' . MAIN_DB_PREFIX . 'facture'),
	'orders'       => array('icon' => '📦', 'label' => $langs->transnoentitiesnoconv('StatsOrders'),        'sql' => 'SELECT COUNT(*) FROM ' . MAIN_DB_PREFIX . 'commande'),
	'proposals'    => array('icon' => '📋', 'label' => $langs->transnoentitiesnoconv('StatsProposals'),     'sql' => 'SELECT COUNT(*) FROM ' . MAIN_DB_PREFIX . 'propal'),
	'projects'     => array('icon' => '🎯', 'label' => 'Projets/Opportunités',              'sql' => 'SELECT COUNT(*) FROM ' . MAIN_DB_PREFIX . 'projet'),
);
$stats = array();
foreach ($statsMap as $key => $info) {
	$r           = $db->query($info['sql']);
	$stats[$key] = array_merge($info, array('count' => $r ? (int) $db->fetch_row($r)[0] : 0));
}

// ── Script actif ─────────────────────────────────────────────────────────────
$activeScript = $script ?: 'generate-thirdparty';

// ── DB listing toujours actif (25 derniers elements) ─────────────────────────
$num_res = 0;
$limit = GETPOST('limit', 'int') ?: 25;

if (!empty($dsDbConf[$activeScript])) {
	$_conf  = $dsDbConf[$activeScript];
	$dbHead = $_conf['head'];
	$dbUrl  = $_conf['url'];
	$offset = $limit * $page;
	$_sql   = str_replace('LIMIT {NB}', 'LIMIT ' . ($limit + 1) . ' OFFSET ' . $offset, $_conf['select']);
	$_res   = $db->query($_sql);
	$num_res = $_res ? $db->num_rows($_res) : 0;
	$i = 0;
	while ($_res && ($_obj = $db->fetch_object($_res)) && $i < $limit) {
		$dbResults[] = (array) $_obj;
		$i++;
	}
}

// ── Reload post-AJAX : lit preExecMaxRowid depuis le fichier .prev.json ─────────────────
$dsPrevMaxRowid = 0;
if ($action !== 'run') {
	// Priorité 1 : fichier prev (robuste même quand NOREQUIREMENU ferme la session)
	$_prevFile = DOL_DATA_ROOT . '/dolistream/prev-'
		. preg_replace('/[^a-z0-9]/', '', session_id()) . '-'
		. preg_replace('/[^a-z0-9-]/', '', $activeScript) . '.prev.json';
	if (file_exists($_prevFile) && (time() - filemtime($_prevFile)) < 120) {
		$__prev = json_decode(file_get_contents($_prevFile), true);
		if (isset($__prev['rowid']) && (int)$__prev['rowid'] > 0) {
			$dsPrevMaxRowid = (int)$__prev['rowid'];
		}
		@unlink($_prevFile); // consommé en une fois
	}
	// Priorité 2 : session (si la session était encore active)
	if ($dsPrevMaxRowid === 0 && !empty($_SESSION['ds_prev_script']) && $_SESSION['ds_prev_script'] === $activeScript) {
		$dsPrevMaxRowid = (int)($_SESSION['ds_prev_rowid'] ?? 0);
		unset($_SESSION['ds_prev_rowid'], $_SESSION['ds_prev_script']);
	}
	// Priorité 3 : paramètre URL ds_prev (fallback navigation)
	if ($dsPrevMaxRowid === 0) {
		$dsPrevMaxRowid = max(0, (int) GETPOST('ds_prev', 'int'));
	}
}
if ($dsPrevMaxRowid > 0 && $action !== 'run' && empty($scriptLog)) {
	// Lire le live .jsonl pour rouvrir la console avec les vrais logs
	$_liveFile = DOL_DATA_ROOT . '/dolistream/live-'
		. preg_replace('/[^a-z0-9]/', '', session_id()) . '-'
		. preg_replace('/[^a-z0-9-]/', '', $activeScript) . '.jsonl';
	if (file_exists($_liveFile)) {
		foreach (file($_liveFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $__ll) {
			$__e = json_decode($__ll, true);
			if (is_array($__e)) $scriptLog[] = $__e;
		}
	}
}
// Compte les lignes vraiment nouvelles (rowid > prevMax) pour le compteur
$dbNewCount = 0;
if ($dsPrevMaxRowid > 0) {
	foreach ($dbResults as $_r) {
		$__v = array_values($_r);
		if ((int)$__v[0] > $dsPrevMaxRowid) $dbNewCount++;
	}
}

// ── Définitions des formulaires avec colonnes de résultat ─────────────────────
$scriptDefs = array(
	'generate-thirdparty' => array(
		'label'   => $langs->transnoentitiesnoconv('GenerateThirdparties'),
		'icon'    => 'company',
		'hint'    => $langs->transnoentitiesnoconv('HintThirdparty'),
		'danger'  => false,
		'perm'    => 'generate',
		'columns' => array($langs->transnoentitiesnoconv('ColNameCompany'), $langs->transnoentitiesnoconv('Type'), $langs->transnoentitiesnoconv('CustomerCode'), $langs->transnoentitiesnoconv('SupplierCode')),
		'fields'  => array(
			array('name' => 'nb', 'label' => $langs->transnoentitiesnoconv('NumberToGenerate'), 'type' => 'number', 'default' => 10, 'min' => 1, 'max' => 10000),
			array(
				'name'    => 'opt',
				'label'   => $langs->transnoentitiesnoconv('FieldThirdpartyType'),
				'type'    => 'select',
				'options' => array(
					'random'   => $langs->transnoentitiesnoconv('OptRandomMix'),
					'prospect' => $langs->transnoentitiesnoconv('Prospect'),
					'client'   => $langs->transnoentitiesnoconv('OptClient'),
					'supplier' => $langs->transnoentitiesnoconv('OptSupplier'),
					'both'     => $langs->transnoentitiesnoconv('OptClientAndSupplier'),
				),
				'default' => 'random',
			),
		),
	),
	'generate-product' => array(
		'label'   => $langs->transnoentitiesnoconv('GenerateProducts'),
		'icon'    => 'product',
		'hint'    => $langs->transnoentitiesnoconv('HintProduct'),
		'danger'  => false,
		'perm'    => 'generate',
		'columns' => array($langs->transnoentitiesnoconv('Ref'), $langs->transnoentitiesnoconv('Type'), $langs->transnoentitiesnoconv('ColPriceHT'), $langs->transnoentitiesnoconv('Stock'), $langs->transnoentitiesnoconv('Batch')),
		'fields'  => array(
			// ① Type
			array(
				'name'    => 'product_type',
				'label'   => $langs->transnoentitiesnoconv('FieldProductType'),
				'type'    => 'select',
				'default' => 'random',
				'options' => array(
					'random'  => $langs->transnoentitiesnoconv('OptRandom'),
					'product' => $langs->transnoentitiesnoconv('Product'),
					'service' => $langs->transnoentitiesnoconv('Service'),
				),
			),
			// ② Nombre à générer
			array('name' => 'nb', 'label' => $langs->transnoentitiesnoconv('FieldNumber'), 'type' => 'number', 'default' => 10, 'min' => 1, 'max' => 10000),
			// ③ Numérotation
			array(
				'name'    => 'batch_mode',
				'label'   => $langs->transnoentitiesnoconv('FieldBatchMode'),
				'type'    => 'select',
				'default' => 'none',
				'options' => array(
					'none'   => $langs->transnoentitiesnoconv('OptNoBatch'),
					'lot'    => $langs->transnoentitiesnoconv('OptLotNumbers'),
					'serial' => $langs->transnoentitiesnoconv('OptSerialNumbers'),
				),
			),
			// ④ Ajouter du stock
			array(
				'name'    => 'with_stock',
				'label'   => $langs->transnoentitiesnoconv('FieldAddStock'),
				'type'    => 'select',
				'default' => 'no',
				'options' => array(
					'no'  => $langs->transnoentitiesnoconv('OptNo'),
					'yes' => $langs->transnoentitiesnoconv('OptYes'),
				),
			),
			// ⑤ Qté stock max
			array('name' => 'stock_qty_max', 'label' => $langs->transnoentitiesnoconv('FieldStockQtyMax'), 'type' => 'number', 'default' => 100, 'min' => 1, 'max' => 10000),
		),
	),
	'generate-invoice' => array(
		'label'   => $langs->transnoentitiesnoconv('GenerateInvoices'),
		'icon'    => 'bill',
		'hint'    => $langs->transnoentitiesnoconv('HintInvoice'),
		'danger'  => false,
		'perm'    => 'generate',
		'columns' => array($langs->transnoentitiesnoconv('Ref'), $langs->transnoentitiesnoconv('Date'), $langs->transnoentitiesnoconv('ThirdParty'), $langs->transnoentitiesnoconv('AmountHT'), $langs->transnoentitiesnoconv('AmountTTC')),
		'fields'  => array(
			array('name' => 'date_start', 'label' => 'Date début', 'type' => 'date', 'default' => date('Y-01-01')),
			array('name' => 'date_end', 'label' => 'Date fin', 'type' => 'date', 'default' => date('Y-12-31')),
			array('name' => 'nb_per_month', 'label' => 'Factures / mois', 'type' => 'number', 'default' => 5, 'min' => 1, 'max' => 5000),
		),
	),
	'generate-order' => array(
		'label'   => $langs->transnoentitiesnoconv('GenerateOrders'),
		'icon'    => 'order',
		'hint'    => $langs->transnoentitiesnoconv('HintOrder'),
		'danger'  => false,
		'perm'    => 'generate',
		'columns' => array($langs->transnoentitiesnoconv('Ref'), $langs->transnoentitiesnoconv('FieldProductSelector'), $langs->transnoentitiesnoconv('FieldNumberPerOrder'), 'Expédiable', $langs->transnoentitiesnoconv('ThirdParty'), $langs->transnoentitiesnoconv('Date'), $langs->transnoentitiesnoconv('AmountHT')),
		'fields'  => array(
			array('name' => 'nb', 'label' => $langs->transnoentitiesnoconv('NumberToGenerate'), 'type' => 'number', 'default' => 10, 'min' => 1, 'max' => 5000),
			array('name' => 'nb_lines', 'label' => $langs->transnoentitiesnoconv('FieldNumberPerOrder'), 'type' => 'number', 'default' => 3, 'min' => 1, 'max' => 100),
			array(
				'name'    => 'batch_mode',
				'label'   => $langs->transnoentitiesnoconv('FieldProductSelector'),
				'type'    => 'select',
				'default' => 'all',
				'options' => array(
					'all'      => $langs->transnoentitiesnoconv('OptAll'),
					'no_batch' => $langs->transnoentitiesnoconv('OptNoBatch'),
					'lot'      => $langs->transnoentitiesnoconv('OptLotNumbers'),
					'serial'   => $langs->transnoentitiesnoconv('OptSerialNumbers'),
				),
			),
			array(
				'name'    => 'in_stock',
				'label'   => $langs->transnoentitiesnoconv('FieldProductWithStock'),
				'type'    => 'checkbox',
				'default' => false,
			),
		),
	),
	'generate-proposal' => array(
		'label'   => $langs->transnoentitiesnoconv('GenerateProposals'),
		'icon'    => 'propal',
		'hint'    => $langs->transnoentitiesnoconv('HintProposal'),
		'danger'  => false,
		'perm'    => 'generate',
		'columns' => array($langs->transnoentitiesnoconv('Ref'), $langs->transnoentitiesnoconv('Date'), $langs->transnoentitiesnoconv('ThirdParty'), $langs->transnoentitiesnoconv('AmountHT')),
		'fields'  => array(
			array('name' => 'nb', 'label' => $langs->transnoentitiesnoconv('NumberToGenerate'), 'type' => 'number', 'default' => 10, 'min' => 1, 'max' => 5000),
		),
	),
	'generate-project' => array(
		'label'   => $langs->transnoentitiesnoconv('GenerateProjects'),
		'icon'    => 'project',
		'hint'    => $langs->transnoentitiesnoconv('HintProject'),
		'danger'  => false,
		'perm'    => 'generate',
		'columns' => array($langs->transnoentitiesnoconv('Ref'), $langs->transnoentitiesnoconv('Title'), $langs->transnoentitiesnoconv('ColOppStatus'), $langs->transnoentitiesnoconv('ColOppAmount'), $langs->transnoentitiesnoconv('Budget')),
		'fields'  => array(
			array('name' => 'nb', 'label' => $langs->transnoentitiesnoconv('NumberToGenerate'), 'type' => 'number', 'default' => 10, 'min' => 1, 'max' => 2000),
			array(
				'name'    => 'mode',
				'label'   => $langs->transnoentitiesnoconv('FieldThirdpartyLinkMode'),
				'type'    => 'select',
				'options' => array(
					'free'   => $langs->transnoentitiesnoconv('OptFreeProject'),
					'linked' => $langs->transnoentitiesnoconv('OptLinkedThirdparty'),
				),
				'default' => 'free',
			),
		),
	),
	'generate-stock' => array(
		'label'   => $langs->transnoentitiesnoconv('GenerateStock'),
		'icon'    => 'stock',
		'hint'    => $langs->transnoentitiesnoconv('HintStock'),
		'danger'  => false,
		'perm'    => 'generate',
		'columns' => array($langs->transnoentitiesnoconv('Product'), $langs->transnoentitiesnoconv('Warehouse'), $langs->transnoentitiesnoconv('Quantity'), $langs->transnoentitiesnoconv('Batch')),
		'fields'  => array(
			array('name' => 'nb',      'label' => $langs->transnoentitiesnoconv('FieldNbMovements'),  'type' => 'number', 'default' => 20,  'min' => 1, 'max' => 500),
			array('name' => 'qty_max', 'label' => $langs->transnoentitiesnoconv('FieldQtyMaxPerMov'), 'type' => 'number', 'default' => 50, 'min' => 1, 'max' => 1000),
			array(
				'name'    => 'product_type',
				'label'   => $langs->transnoentitiesnoconv('FieldProductTypeFilter'),
				'type'    => 'select',
				'default' => 'all',
				'options' => array(
					'all'     => $langs->transnoentitiesnoconv('OptAll'),
					'product' => $langs->transnoentitiesnoconv('OptProductsOnly'),
					'service' => $langs->transnoentitiesnoconv('OptServicesOnly'),
				),
			),
			array(
				'name'    => 'batch_mode',
				'label'   => $langs->transnoentitiesnoconv('FieldBatchMode'),
				'type'    => 'select',
				'default' => 'none',
				'options' => array(
					'none'   => $langs->transnoentitiesnoconv('OptNoBatch'),
					'lot'    => $langs->transnoentitiesnoconv('OptLotNumbers'),
					'serial' => $langs->transnoentitiesnoconv('OptSerialNumbers1u'),
				),
			),
		),
	),
	'generate-warehouse' => array(
		'label'   => $langs->transnoentitiesnoconv('GenerateWarehouses'),
		'icon'    => 'stock',
		'hint'    => $langs->transnoentitiesnoconv('HintWarehouse'),
		'danger'  => false,
		'perm'    => 'generate',
		'columns' => array($langs->transnoentitiesnoconv('Ref'), $langs->transnoentitiesnoconv('Label'), $langs->transnoentitiesnoconv('Town')),
		'fields'  => array(
			array('name' => 'nb', 'label' => $langs->transnoentitiesnoconv('NumberToGenerate'), 'type' => 'number', 'default' => 3, 'min' => 1, 'max' => 50),
			array(
				'name'        => 'prefix',
				'label'       => $langs->transnoentitiesnoconv('FieldRefPrefix'),
				'type'        => 'text',
				'default'     => 'WH',
				'placeholder' => $langs->transnoentitiesnoconv('FieldRefPrefixPlaceholder'),
			),
		),
	),
	'generate-rental-product' => array(
		'label'   => 'Générer Produits Loc',
		'icon'    => 'product',
		'hint'    => 'Génère des produits configurés pour la location avec un ratio paramétrable de prix locatif par rapport au prix de vente.',
		'danger'  => false,
		'perm'    => 'generate',
		'columns' => array('Réf', 'Titre', 'Prix Vente', 'Prix Loc/J'),
		'fields'  => array(
			array('name' => 'nb', 'label' => 'Nombre à générer', 'type' => 'number', 'default' => 10, 'min' => 1, 'max' => 5000),
			array('name' => 'rental_ratio', 'label' => 'Ratio Prix Loc/Vente (%)', 'type' => 'number', 'default' => 5, 'min' => 1, 'max' => 100),
		),
	),
	'generate-rental-project' => array(
		'label'   => 'Générer Projets LLD',
		'icon'    => 'project',
		'hint'    => 'Génère des projets pré-configurés comme Location Longue Durée (LLD).',
		'danger'  => false,
		'perm'    => 'generate',
		'columns' => array('Réf', 'Titre', 'Statut', 'Montant', 'Budget', 'Nb Entrepôts', 'Factures Réc.'),
		'fields'  => array(
			array('name' => 'nb', 'label' => 'Nombre à générer', 'type' => 'number', 'default' => 10, 'min' => 1, 'max' => 2000),
			array(
				'name'    => 'socids',
				'label'   => 'Tiers',
				'type'    => 'multiselect_tiers',
			),
			array(
				'name'    => 'nb_wh',
				'label'   => "Entrepôts liés",
				'type'    => 'number',
				'default' => 0,
				'min'     => 0,
				'max'     => 50
			),
			array(
				'name'    => 'date_start',
				'label'   => 'Date de début',
				'type'    => 'date',
				'default' => date('Y-m-d', strtotime('-1 year'))
			),
			array(
				'name'    => 'sales_billing',
				'label'   => 'Facturation LLD',
				'type'    => 'select',
				'options' => array(
					'1' => 'Facture mensuelle',
					'2' => 'Depuis l\'onglet LLD',
					'3' => 'Facturation manuelle',
				),
				'default' => '3'
			)
		),
	),
	'generate-rental-order' => array(
		'label'   => 'Générer Commande Loc',
		'icon'    => 'order',
		'hint'    => 'Génère des commandes de location associées à un projet LLD sélectionné.',
		'danger'  => false,
		'perm'    => 'generate',
		'columns' => array('Réf Commande', 'Projet LLD', 'Type de projet', 'Nb Produits', 'Tiers', 'Date', 'Montant HT'),
		'fields'  => array(
			array('name' => 'nb', 'label' => 'Nombre à générer', 'type' => 'number', 'default' => 1, 'min' => 1, 'max' => 50),
			array(
				'name'    => 'fk_project',
				'label'   => 'Projet de location',
				'type'    => 'select_rental_project',
			),
			array(
				'name'    => 'product_ids',
				'label'   => 'Produits de location',
				'type'    => 'multiselect_rental_product',
			),
			array(
				'name'    => 'qty_mode',
				'label'   => 'Mode de quantité',
				'type'    => 'select',
				'options' => array('fixed' => 'Fixe', 'random' => 'Aléatoire'),
				'default' => 'fixed',
			),
			array('name' => 'qty_val', 'label' => 'Quantité (ou max)', 'type' => 'number', 'default' => 1, 'min' => 1, 'max' => 1000)
		),
	),
	'generate-rental-workflow' => array(
		'label'   => 'Flux location',
		'icon'    => 'sending',
		'hint'    => 'Génère des expéditions réparties sur 1 an pour une commande LLD sélectionnée.',
		'danger'  => false,
		'perm'    => 'generate',
		'columns' => array('Réf Exp.', 'Commande', 'Mois', 'Qté Expédiée', 'Lot/Série'),
		'fields'  => array(
			array('name' => 'fk_project', 'label' => 'Projet de location', 'type' => 'select_rental_project'),
			array('name' => 'grid', 'label' => '', 'type' => 'custom_rental_flow_grid')
		),
	),

	'generate-rental-return' => array(
		'label'   => 'Retour Loc',
		'icon'    => 'truck',
		'hint'    => 'Génère des retours pour les commandes LLD sélectionnées (utilise le module productreturn).',
		'danger'  => false,
		'perm'    => 'generate',
		'columns' => array('Réf Retour', 'Commande', 'Projet LLD', 'Tiers', 'Nb Produits Retournés'),
		'fields'  => array(
			array('name' => 'fk_project', 'label' => 'Projet de location', 'type' => 'select_rental_project'),
			array('name' => 'nb', 'label' => 'Retours aléatoires à générer', 'type' => 'number', 'default' => 1, 'min' => 1, 'max' => 20)
		),
	),
	'purge-data' => array(
		'label'   => $langs->transnoentitiesnoconv('PurgeData'),
		'icon'    => 'delete',
		'hint'    => $langs->transnoentitiesnoconv('HintPurge'),
		'danger'  => true,
		'perm'    => 'purge',
		'columns' => array($langs->transnoentitiesnoconv('ColOperation'), $langs->transnoentitiesnoconv('Status'), $langs->transnoentitiesnoconv('ColDetail')),
		'fields'  => array(
			array(
				'name'    => 'mode',
				'label'   => $langs->transnoentitiesnoconv('ExecutionMode'),
				'type'    => 'select',
				'options' => array(
					'test'    => $langs->transnoentitiesnoconv('TestMode'),
					'confirm' => $langs->transnoentitiesnoconv('ConfirmMode'),
				),
				'default' => 'test',
			),
			array(
				'name'    => 'opt',
				'label'   => $langs->transnoentitiesnoconv('CategoryToPurge'),
				'type'    => 'select',
				'options' => array(
					'all'        => $langs->transnoentitiesnoconv('AllCategories'),
					'invoice'    => $langs->transnoentitiesnoconv('PurgeCatInvoice'),
					'order'      => $langs->transnoentitiesnoconv('PurgeCatOrder'),
					'proposal'   => $langs->transnoentitiesnoconv('PurgeCatProposal'),
					'product'    => $langs->transnoentitiesnoconv('PurgeCatProduct'),
					'contact'    => $langs->transnoentitiesnoconv('PurgeCatContact'),
					'thirdparty' => $langs->transnoentitiesnoconv('PurgeCatThirdparty'),
					'payment'    => $langs->transnoentitiesnoconv('PurgeCatPayment'),
					'project'    => $langs->transnoentitiesnoconv('PurgeCatProject'),
					'bank'       => $langs->transnoentitiesnoconv('PurgeCatBank'),
					'event'      => $langs->transnoentitiesnoconv('PurgeCatEvent'),
					'user'       => $langs->transnoentitiesnoconv('PurgeCatUser'),
				),
				'default' => 'all',
			),
			array('name' => 'date', 'label' => $langs->transnoentitiesnoconv('BeforeDate'), 'type' => 'text', 'default' => 'all', 'placeholder' => 'all  ou  2024-01-01'),
		),
	),
	// ── Nouveaux scripts ──────────────────────────────────────────────────────
	'generate-expedition' => array(
		'label'   => $langs->transnoentitiesnoconv('GenerateShipments'),
		'icon'    => 'shipment',
		'hint'    => $langs->transnoentitiesnoconv('HintShipment'),
		'danger'  => false,
		'perm'    => 'generate',
		'columns' => array($langs->transnoentitiesnoconv('Ref'), $langs->transnoentitiesnoconv('ThirdParty'), $langs->transnoentitiesnoconv('DeliveryDate')),
		'fields'  => array(
			array('name' => 'nb', 'label' => $langs->transnoentitiesnoconv('NumberToGenerate'), 'type' => 'number', 'default' => 5, 'min' => 1, 'max' => 500),
		),
	),
	'generate-supplier-order' => array(
		'label'   => $langs->transnoentitiesnoconv('GenerateSupplierOrders'),
		'icon'    => 'supplier_order',
		'hint'    => $langs->transnoentitiesnoconv('HintSupplierOrder'),
		'danger'  => false,
		'perm'    => 'generate',
		'columns' => array($langs->transnoentitiesnoconv('Ref'), $langs->transnoentitiesnoconv('Date'), $langs->transnoentitiesnoconv('Supplier'), $langs->transnoentitiesnoconv('AmountHT')),
		'fields'  => array(
			array('name' => 'nb', 'label' => $langs->transnoentitiesnoconv('NumberToGenerate'), 'type' => 'number', 'default' => 10, 'min' => 1, 'max' => 2000),
		),
	),
	'generate-reception' => array(
		'label'   => $langs->transnoentitiesnoconv('GenerateSupplierReceptions'),
		'icon'    => 'reception',
		'hint'    => $langs->transnoentitiesnoconv('HintSupplierReception'),
		'danger'  => false,
		'perm'    => 'generate',
		'columns' => array($langs->transnoentitiesnoconv('Ref'), $langs->transnoentitiesnoconv('Supplier'), $langs->transnoentitiesnoconv('ColReceptionDate')),
		'fields'  => array(
			array('name' => 'nb', 'label' => $langs->transnoentitiesnoconv('NumberToGenerate'), 'type' => 'number', 'default' => 5, 'min' => 1, 'max' => 500),
		),
	),
	'generate-supplier-invoice' => array(
		'label'   => $langs->transnoentitiesnoconv('GenerateSupplierInvoices'),
		'icon'    => 'supplier_invoice',
		'hint'    => $langs->transnoentitiesnoconv('HintSupplierInvoice'),
		'danger'  => false,
		'perm'    => 'generate',
		'columns' => array($langs->transnoentitiesnoconv('Ref'), $langs->transnoentitiesnoconv('Date'), $langs->transnoentitiesnoconv('Supplier'), $langs->transnoentitiesnoconv('AmountHT'), $langs->transnoentitiesnoconv('AmountTTC')),
		'fields'  => array(
			array('name' => 'nb', 'label' => $langs->transnoentitiesnoconv('NumberToGenerate'), 'type' => 'number', 'default' => 10, 'min' => 1, 'max' => 2000),
		),
	),
	// ── Workflows ─────────────────────────────────────────────────────────────
	'workflow-opp-cl-pr' => array(
		'label'   => $langs->transnoentitiesnoconv('WorkflowOppClPrTitle'),
		'icon'    => 'technic',
		'hint'    => $langs->transnoentitiesnoconv('HintWorkflowOppClPr'),
		'danger'  => false,
		'perm'    => 'generate',
		'columns' => array($langs->transnoentitiesnoconv('Ref'), $langs->transnoentitiesnoconv('ColOpportunity'), $langs->transnoentitiesnoconv('ThirdParty'), $langs->transnoentitiesnoconv('AmountHT')),
		'fields'  => array(
			array('name' => 'nb', 'label' => $langs->transnoentitiesnoconv('FieldNbWorkflowRuns'), 'type' => 'number', 'default' => 1, 'min' => 1, 'max' => 500),
			array('name' => 'nb_lines', 'label' => $langs->transnoentitiesnoconv('FieldNumberPerProposal'), 'type' => 'number', 'default' => 2, 'min' => 1, 'max' => 20),
		),
	),
);

$def = $scriptDefs[$activeScript] ?? null;

// ── Parsing du log en lignes structurées pour le tableau ──
$structLog = array();

// ── Helpers URL (cache) ──────────────────────────────────────────────────────
$linkCache  = array();

// Résout socid → nom de tiers (utilisé dans les parsers avec soc=N)
$socCache = array();
$resolveSoc = static function (int $id) use ($db, &$socCache): string {
    if (isset($socCache[$id])) return $socCache[$id];
    $r = $db->query('SELECT nom FROM ' . MAIN_DB_PREFIX . 'societe WHERE rowid=' . $id);
    $socCache[$id] = ($r && ($o = $db->fetch_object($r))) ? $o->nom : '#' . $id;
    return $socCache[$id];
};

/**
 * Construit un lien <a> à partir d'une réf. + table DB + chemin URL.
 * Retourne array('html', '<a href="...">REF</a>') pour le rendu HTML brut.
 */
$makeLink = static function (string $table, string $ref, string $urlPath) use ($db, &$linkCache): array {
    $key = $table . ':' . $ref;
    if (isset($linkCache[$key])) return $linkCache[$key];
    $r = $db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . $table . " WHERE ref='" . $db->escape($ref) . "'");
    if ($r && ($o = $db->fetch_object($r)) && !empty($o->rowid)) {
        $url  = DOL_URL_ROOT . $urlPath . (int)$o->rowid;
        $html = '<a href="' . $url . '">' . htmlspecialchars($ref) . '</a>';
    } else {
        $html = htmlspecialchars($ref);
    }
    return ($linkCache[$key] = array('html', $html));
};

/** Lien tiers par nom (generate-thirdparty log au format « - NAME [TYPE] ») */
$makeSocLink = static function (string $name) use ($db, &$linkCache): array {
    $key = 'societe_nom:' . $name;
    if (isset($linkCache[$key])) return $linkCache[$key];
    $r = $db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "societe WHERE nom='" . $db->escape($name) . "'");
    if ($r && ($o = $db->fetch_object($r)) && !empty($o->rowid)) {
        $url  = DOL_URL_ROOT . '/societe/card.php?socid=' . (int)$o->rowid;
        $html = '<a href="' . $url . '">' . htmlspecialchars($name) . '</a>';
    } else {
        $html = htmlspecialchars($name);
    }
    return ($linkCache[$key] = array('html', $html));
};

foreach ($scriptLog as $entry) {
    $msg   = $entry['msg'];
    $lvl   = $entry['level'];
    $cells = array();

    // ── generate-thirdparty ─────────────────────────────────────────────────
    if ($activeScript === 'generate-thirdparty') {
        // ✓ #N - NAME [TYPE] [cli=CODE, fourn=CODE]
        if (preg_match('/- (.+?) \[(.+?)\](?:\s*\[cli=(.+?),\s*fourn=(.+?)\])?/', $msg, $m)) {
            $cells = array(
                $makeSocLink(trim($m[1])),
                $m[2],
                $m[3] ?? '-',
                $m[4] ?? '-',
            );
        }

    // ── generate-product ────────────────────────────────────────────────────
    } elseif ($activeScript === 'generate-product') {
        // ✓ #N | REF | TYPE | PRICE € | STOCK | LOT
        if (preg_match('/\| (\S+) \| (\w+) \| ([\d.]+).*\| (.*?) \| (.*)$/', $msg, $m)) {
            $cells = array(
                $makeLink('product', $m[1], '/product/card.php?id='),
                $m[2], $m[3] . ' €', trim($m[4]), trim($m[5]),
            );
        } elseif (preg_match('/- (\S+)\s+\(([\d.]+)/', $msg, $m)) {
            // Ancien format
            $cells = array(
                $makeLink('product', $m[1], '/product/card.php?id='),
                (strpos($m[1], 'SRV') !== false ? 'Service' : 'Produit'),
                $m[2] . ' €', '', '',
            );
        }

    // ── generate-invoice ────────────────────────────────────────────────────
    } elseif ($activeScript === 'generate-invoice') {
        // ✓ #N | REF | soc=SOCID | DATE | HT=X | TTC=Y
        if (preg_match('/\| (\S+) \| soc=(\d+) \| (\S+) \| HT=([\d.]+) \| TTC=([\d.]+)/', $msg, $m)) {
            $cells = array(
                $makeLink('facture', $m[1], '/compta/facture/card.php?id='),
                $resolveSoc((int)$m[2]), $m[3], $m[4] . ' €', $m[5] . ' €',
            );
        }

    // ── generate-order ──────────────────────────────────────────────────────
    } elseif ($activeScript === 'generate-order') {
        // ✓ #N | REF | batch=XXX | nb=X | ship=X | soc=SOCID | DATE | HT=X
        if (preg_match('/\| (\S+) \| batch=(\w+) \| nb=(\d+) \| ship=(\w+) \| soc=(\d+) \| (\S+) \| HT=([\d.]+)/', $msg, $m)) {
            $batchTr = $m[2];
            if ($batchTr === 'all') $batchTr = 'Tous';
            elseif ($batchTr === 'Sans_lot') $batchTr = 'Sans lot/série';
            elseif ($batchTr === 'lot') $batchTr = 'Lot';
            elseif ($batchTr === 'serial') $batchTr = 'Série';

            $cells = array(
                $makeLink('commande', $m[1], '/commande/card.php?id='),
                $batchTr,
                $m[3],
                $m[4],
                $resolveSoc((int)$m[5]), 
                $m[6], 
                $m[7] . ' €',
            );
        }

    // ── generate-proposal ───────────────────────────────────────────────────
    } elseif ($activeScript === 'generate-proposal') {
        // ✓ #N | REF | soc=SOCID | DATE | HT=X
        if (preg_match('/\| (\S+) \| soc=(\d+) \| (\S+) \| HT=([\d.]+)/', $msg, $m)) {
            $cells = array(
                $makeLink('propal', $m[1], '/comm/propal/card.php?id='),
                $resolveSoc((int)$m[2]), $m[3], $m[4] . ' €',
            );
        }

    // ── generate-rental-product ─────────────────────────────────────────────
    } elseif ($activeScript === 'generate-rental-product') {
        // ✓ #N | id=11 | PRD | Label | 15.00 € | 0.75 €/j | 0.45 €/j | Infos
        if (preg_match('/\| id=(\d+) \| (\S+) \| (.+?) \| ([\d\.]+ \S+) \| ([\d\.]+ \S+) \| ([\d\.]+ \S+) \| (.+)/', $msg, $m)) {
            $cells = array(
                $makeLink('product', $m[2], '/product/card.php?id=' . $m[1]),
                trim($m[3]), $m[4], $m[5], $m[6], trim($m[7])
            );
        }

    // ── generate-rental-project ─────────────────────────────────────────────
    } elseif ($activeScript === 'generate-rental-project') {
        if (preg_match('/\| (\S+) \| (.+?) \| (.+?) \| ([\d ]+) €\S* \| ([\d ]+) €\S* \| (\d+) \| (\d+)/', $msg, $m)) {
            $cells = array(
                $makeLink('projet', $m[1], '/projet/card.php?id='),
                trim($m[2]), trim($m[3]),
                str_replace(' ', '', $m[4]) . ' €',
                str_replace(' ', '', $m[5]) . ' €',
                $m[6],
                $m[7]
            );
        }

    // ── generate-rental-order ───────────────────────────────────────────────
    } elseif ($activeScript === 'generate-rental-order') {
        // ✔ #0 | CMD-001 | Projet: PRJ-001 | 2 produit(s) | Tiers Name | 12/06/2025 | 1 500,00 €
        if (preg_match('/\| (\S+) \| Projet: (\S+) \| (.+?) \| (.+?) \| (.+?) \| (.+) €/', $msg, $m)) {
            $cells = array(
                $makeLink('commande', $m[1], '/commande/card.php?ref='),
                $makeLink('projet', $m[2], '/projet/card.php?ref='),
                trim($m[3]), trim($m[4]), trim($m[5]),
                str_replace(' ', '', trim($m[6])) . ' €'
            );
        }

    // ── generate-project ────────────────────────────────────────────────────
    } elseif ($activeScript === 'generate-project') {
        // ✓ #N | REF | TITLE | STATUS | AMOUNT € | BUDGET €
        if (preg_match('/\| (\S+) \| (.+?) \| (.+?) \| ([\d ]+) €\S* \| ([\d ]+)/', $msg, $m)) {
            $cells = array(
                $makeLink('projet', $m[1], '/projet/card.php?id='),
                trim($m[2]), trim($m[3]),
                str_replace(' ', '', $m[4]) . ' €',
                str_replace(' ', '', $m[5]) . ' €',
            );
        }

    // ── generate-stock ──────────────────────────────────────────────────────
    } elseif ($activeScript === 'generate-stock') {
        // ✓ #N | REF | WH | +QTY [| LOT/SN]
        if (preg_match('/\| (\S+) \| (\S+) \| \+(\d+)(?: unités?)?(?: \| (.+))?$/', $msg, $m)) {
            $cells = array(
                $makeLink('product', $m[1], '/product/card.php?id='),
                $makeLink('entrepot', $m[2], '/product/stock/card.php?id='),
                '+' . $m[3], $m[4] ?? '',
            );
        }

    // ── generate-warehouse ──────────────────────────────────────────────────
    } elseif ($activeScript === 'generate-warehouse') {
        // ✓ #N | REF | LABEL | VILLE
        if (preg_match('/\| (\S+) \| (.+?) \| (.+?)$/', $msg, $m)) {
            $cells = array(
                $makeLink('entrepot', $m[1], '/product/stock/card.php?id='),
                trim($m[2]), trim($m[3]),
            );
        }

    // ── generate-expedition ─────────────────────────────────────────────────
    } elseif ($activeScript === 'generate-expedition') {
        // ✓ #N | REF | soc=SOCID | DATE
        if (preg_match('/\| (\S+) \| soc=(\d+) \| (.+)$/', $msg, $m)) {
            $cells = array(
                $makeLink('expedition', $m[1], '/expedition/card.php?id='),
                $resolveSoc((int)$m[2]), trim($m[3]),
            );
        }

    // ── generate-supplier-order ─────────────────────────────────────────────
    } elseif ($activeScript === 'generate-supplier-order') {
        // ✓ #N | REF | soc=SOCID | DATE | HT=X
        if (preg_match('/\| (\S+) \| soc=(\d+) \| (\S+) \| HT=([\d.]+)/', $msg, $m)) {
            $cells = array(
                $makeLink('commande_fournisseur', $m[1], '/fourn/commande/card.php?id='),
                $resolveSoc((int)$m[2]), $m[3], $m[4] . ' €',
            );
        }

    // ── generate-reception ──────────────────────────────────────────────────
    } elseif ($activeScript === 'generate-reception') {
        // ✓ #N | REF | soc=SOCID | DATE
        if (preg_match('/\| (\S+) \| soc=(\d+) \| (.+)$/', $msg, $m)) {
            $cells = array(
                $makeLink('reception', $m[1], '/reception/card.php?id='),
                $resolveSoc((int)$m[2]), trim($m[3]),
            );
        }

    // ── generate-supplier-invoice ───────────────────────────────────────────
    } elseif ($activeScript === 'generate-supplier-invoice') {
        // ✓ #N | REF | soc=SOCID | DATE | HT=X | TTC=Y
        if (preg_match('/\| (\S+) \| soc=(\d+) \| (\S+) \| HT=([\d.]+) \| TTC=([\d.]+)/', $msg, $m)) {
            $cells = array(
                $makeLink('facture_fourn', $m[1], '/fourn/facture/card.php?id='),
                $resolveSoc((int)$m[2]), $m[3], $m[4] . ' €', $m[5] . ' €',
            );
        }

    // ── purge-data ──────────────────────────────────────────────────────────
    } elseif ($activeScript === 'purge-data') {
        if ($lvl !== 'info' && !empty($msg) && !preg_match('/^[\x{2550}\x{2554}\x{2557}]/u', $msg)) {
            $icon  = $lvl === 'success' ? '✓' : ($lvl === 'error' ? '✗' : '!');
            $cells = array($icon, $lvl, $msg);
        }

    // ── fallback ────────────────────────────────────────────────────────────
    } else {
        if ($lvl !== 'info' && !empty($msg)) {
            $cells = array($lvl, $msg);
        }
    }

    if (!empty($cells)) {
        $structLog[] = array('level' => $lvl, 'cells' => $cells);
    } elseif ($lvl !== 'info' && !empty($msg) && !preg_match('/^[✓═╔╗✗]/u', $msg)) {
        $structLog[] = array('level' => $lvl, 'cells' => array($msg));
    }
}
// ── En-tête Dolibarr avec menu gauche natif ───────────────────────────────────
$leftMenuKey = 'dolistream_' . str_replace('-', '_', $activeScript);

llxHeader('', 'DoliStream', '', '', 0, 0, '', '', '', 'mod-dolistream page-index', '', '', 'dolistream', $leftMenuKey);
?>
<style>
.ds-warning-banner {
	background: #fff3cd;
	border: 1px solid #ffc107;
	border-left: 4px solid #993013;
	padding: 8px 14px;
	font-size: 0.88em;
	color: #5a3e0a;
	margin-bottom: 12px;
	display: flex;
	align-items: center;
	gap: 8px;
	border-radius: 2px;
}
.ds-stats {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
	margin-bottom: 12px;
	align-items: center;
}
.ds-stat-chip {
	background: var(--colorbacktabcard1, #fff);
	border: 1px solid var(--inputbordercolor, rgba(0,0,0,.15));
	border-radius: 20px;
	padding: 4px 10px;
	font-size: 0.82em;
	display: flex;
	align-items: center;
	gap: 4px;
}
.ds-stat-chip strong { color: var(--colorbackhmenu1, rgb(90,50,120)); }
.ds-hint {
	background: #eef3fb;
	border: 1px solid #c5d8f5;
	padding: 7px 12px;
	font-size: 0.85em;
	color: #1a3a6e;
	border-radius: 2px;
	margin-bottom: 10px;
}
.ds-danger-banner {
	background: #f8d7da;
	border: 1px solid #f5c2c7;
	padding: 7px 12px;
	font-size: 0.85em;
	color: #842029;
	font-weight: 600;
	border-radius: 2px;
	margin-bottom: 10px;
}
/* ── Spinner inline (CSS pur, aucun JS pour l'animation) ── */
#ds-ring {
	visibility: hidden;
	display: flex;
	align-items: center;
	flex-shrink: 0;
}
.ds-ring-circle {
	width: 26px;
	height: 26px;
	border: 3px solid #e5dff0;
	border-top-color: rgb(90,50,120);
	border-radius: 50%;
	animation: ds-spin 0.75s linear infinite;
	animation-play-state: paused;
}
@keyframes ds-spin {
	to { transform: rotate(360deg); }
}
</style>

<div class="fiche">
<?php
$activeTab = GETPOST('tab', 'alpha');
if (empty($activeTab)) $activeTab = 'index';

// Count ActionComms
$nbEvent = 0;
$sqlAc = "SELECT COUNT(*) as nb FROM " . MAIN_DB_PREFIX . "actioncomm WHERE label LIKE 'DoliStream %'";
$resAc = $db->query($sqlAc);
if ($resAc) {
	$objAc = $db->fetch_object($resAc);
	$nbEvent = $objAc->nb;
	$db->free($resAc);
}

$agendaLabel = $langs->trans("Events") . '/' . $langs->trans("Agenda");
if ($nbEvent > 0) {
	$agendaLabel .= ' <span class="badge marginleftonlyshort">' . $nbEvent . '</span>';
}

$head = array();
$head[] = array(dol_buildpath('/custom/dolistream/view/index.php', 1) . '?script=' . urlencode($activeScript) . '&tab=index', ($def ? $def['label'] : 'DoliStream'), 'index');
$head[] = array(dol_buildpath('/custom/dolistream/view/index.php', 1) . '?script=' . urlencode($activeScript) . '&tab=agenda', $agendaLabel, 'agenda');

print dol_get_fiche_head($head, $activeTab, 'DoliStream', -1, 'technic');
?>



<!-- Bandeau avertissement dev -->
<div class="ds-warning-banner">⚠ <strong><?php print $langs->transnoentities('DoliStreamWarningDevOnly'); ?></strong></div>

<!-- Stats -->
<div class="ds-stats">
<?php foreach ($stats as $s): ?>
	<div class="ds-stat-chip"><?php print $s['icon']; ?> <strong><?php print $s['count']; ?></strong> <?php print $s['label']; ?></div>
<?php endforeach; ?>
	<a href="<?php print $_SERVER['PHP_SELF']; ?>?script=<?php print urlencode($activeScript); ?>" style="margin-left:auto;font-size:0.82em;color:var(--colorbackhmenu1,rgb(90,50,120))">↺ <?php print $langs->transnoentitiesnoconv('RefreshStats'); ?></a>
</div>

<?php if ($activeTab === 'index'): ?>

<?php if ($def): ?>

<?php if ($def['danger']): ?>
<div class="ds-danger-banner">⚠ <?php print $langs->transnoentitiesnoconv('DangerPurge'); ?></div>
<?php endif; ?>

<div class="ds-hint">ℹ <?php print $def['hint']; ?></div>

<!-- Action Line -->
<form id="ds-run-form" method="POST" action="<?php print $_SERVER['PHP_SELF']; ?>" style="margin-top:12px;">
<input type="hidden" name="action" value="run">
<input type="hidden" name="script" value="<?php print htmlspecialchars($activeScript); ?>">
<input type="hidden" name="token" value="<?php print newToken(); ?>">
<input type="hidden" name="token_check" value="1">

<div class="ds-action-line" style="display:block; padding: 12px; border: 1px solid #e0e4e8; background: #fff; border-radius: 4px;">
  <!-- L1: Ref, Icon, Title, Buttons -->
  <div style="display:flex; align-items:center; flex-wrap:wrap; gap:16px;">
    <div class="ds-action-ref" style="font-family:monospace; font-weight:bold; color:#666;">DS-0001</div>
    <div class="ds-action-icon"><?php print img_object('', $def['icon']); ?></div>
    <div class="ds-action-title" style="font-size:1.1em; font-weight:bold; color:var(--colortexttitlenotab);"><?php print $def['label']; ?></div>
    
    <div style="display:inline-flex;align-items:center;gap:8px;margin-left:auto;">
      <div id="ds-ring" style="visibility:hidden;">
        <div class="ds-ring-circle" id="ds-ring-circle"></div>
      </div>
      <?php if ($def['danger']): ?>
        <button type="submit" id="ds-run-btn" class="butActionDelete"
          onclick="return confirm('Continuer ?')"><?php print '&#128163; ' . $langs->transnoentitiesnoconv('RunScript'); ?></button>
      <?php else: ?>
        <button type="submit" id="ds-run-btn" class="butAction">&#9654; EXÉCUTER</button>
      <?php endif; ?>
      <a href="?script=<?php print htmlspecialchars($activeScript); ?>" class="butActionRefused" style="margin:0;">ANNULER</a>
    </div>
  </div>

  <!-- L2: Params -->
  <?php if (!empty($def['fields'])): ?>
  <div style="display:flex; align-items:center; flex-wrap:wrap; gap:16px; margin-top:12px; padding-top:12px; border-top: 1px solid #f0f0f0;">
    <div style="font-size:0.9em; color:#888; font-weight:bold; text-transform:uppercase;">Paramètres :</div>
    <?php foreach ($def['fields'] as $field): ?>
      <label style="display:flex;align-items:center;gap:6px;white-space:nowrap;">
        <span style="font-weight:600;"><?php print $field['label']; ?> <span class="error">*</span></span>
        <?php if ($field['type'] === 'select'): ?>
          <select name="<?php print $field['name']; ?>" id="ds-fld-<?php print $field['name']; ?>" class="flat">
          <?php
            $selectedVal = ($field['name'] === 'opt' && !empty($urlOpt)) ? $urlOpt : ($field['default'] ?? '');
            foreach ($field['options'] as $v => $l):
          ?>
            <option value="<?php print htmlspecialchars($v); ?>" <?php print ($selectedVal === $v ? 'selected' : ''); ?>><?php print htmlspecialchars($l); ?></option>
    <?php endforeach; ?>
          </select>
        <?php elseif ($field['type'] === 'multiselect_tiers'): ?>
          <select name="<?php print $field['name']; ?>[]" id="ds-fld-<?php print $field['name']; ?>" class="flat" multiple="multiple" required style="min-width:300px; max-width:500px;">
          <?php
            global $db;
            $res = $db->query("SELECT rowid, nom FROM " . MAIN_DB_PREFIX . "societe WHERE status=1 AND client IN (1,3) ORDER BY nom");
            while ($res && $obj = $db->fetch_object($res)) {
              print '<option value="'.$obj->rowid.'">'.htmlspecialchars($obj->nom).'</option>';
            }
          ?>
          </select>
          <script>
            document.addEventListener('DOMContentLoaded', function() {
              if (typeof jQuery !== 'undefined' && jQuery.fn.select2) {
                jQuery('#ds-fld-<?php print $field['name']; ?>').select2({
                  placeholder: "Sélectionnez des tiers...",
                  width: '300px'
                });
              }
            });
          </script>
        <?php elseif ($field['type'] === 'select_rental_project'): ?>
          <select name="<?php print $field['name']; ?>" id="ds-fld-<?php print $field['name']; ?>" class="flat" required style="min-width:300px; max-width:500px;">
            <option value="">-- Sélectionnez un projet LLD --</option>
          <?php
            global $db;
            $res = $db->query("SELECT p.rowid, p.ref, p.title FROM " . MAIN_DB_PREFIX . "projet p LEFT JOIN " . MAIN_DB_PREFIX . "projet_extrafields pe ON pe.fk_object=p.rowid WHERE p.fk_statut >= 0 AND pe.rental_ltrproject=2 ORDER BY p.rowid DESC LIMIT 100");
            while ($res && $obj = $db->fetch_object($res)) {
              print '<option value="'.$obj->rowid.'">'.htmlspecialchars($obj->ref . ' - ' . $obj->title).'</option>';
            }
          ?>
          </select>
          
          <span style="display:inline-block; margin-left: 20px;">
              <b>Entrepôt d'expédition par défaut :</b>&nbsp;
              <select id="default_exp_warehouse" name="default_exp_warehouse" class="flat" style="min-width:200px;">
              <?php
                $resWh = $db->query("SELECT rowid, ref FROM " . MAIN_DB_PREFIX . "entrepot WHERE statut = 1 AND (fk_project IS NULL OR fk_project = 0) ORDER BY ref");
                while ($resWh && $objWh = $db->fetch_object($resWh)) {
                    $shortName = strpos($objWh->ref, ' > ') !== false ? substr($objWh->ref, strrpos($objWh->ref, ' > ') + 3) : $objWh->ref;
                    print '<option value="'.$objWh->rowid.'" title="'.htmlspecialchars($objWh->ref).'">'.htmlspecialchars($shortName).'</option>';
                }
              ?>
              </select>
          </span>
          <script>
            document.addEventListener('DOMContentLoaded', function() {
              let sel = document.getElementById('ds-fld-<?php print $field['name']; ?>');
              if (typeof jQuery !== 'undefined' && jQuery.fn.select2) {
                jQuery(sel).select2({ width: '300px' }).on('change', function() {
                    if (typeof loadRentalFlowGrid === 'function') loadRentalFlowGrid(this.value);
                });
              } else if (sel) {
                sel.addEventListener('change', function() {
                    if (typeof loadRentalFlowGrid === 'function') loadRentalFlowGrid(this.value);
                });
              }
              if (sel && sel.value && typeof loadRentalFlowGrid === 'function') loadRentalFlowGrid(sel.value);
            });
          </script>
        <?php elseif ($field['type'] === 'select_rental_order'): ?>
          <select name="<?php print $field['name']; ?>" id="ds-fld-<?php print $field['name']; ?>" class="flat" required style="min-width:300px; max-width:500px;">
            <option value="">-- Sélectionnez une commande LLD --</option>
          <?php
            global $db;
            $res = $db->query("SELECT c.rowid, c.ref, s.nom FROM " . MAIN_DB_PREFIX . "commande c LEFT JOIN " . MAIN_DB_PREFIX . "societe s ON s.rowid=c.fk_soc WHERE c.fk_statut >= 1 AND c.source = 1 ORDER BY c.rowid DESC LIMIT 100");
            while ($res && $obj = $db->fetch_object($res)) {
              print '<option value="'.$obj->rowid.'">'.htmlspecialchars($obj->ref . ' - ' . $obj->nom).'</option>';
            }
          ?>
          </select>
          <script>
            document.addEventListener('DOMContentLoaded', function() {
              if (typeof jQuery !== 'undefined' && jQuery.fn.select2) {
                jQuery('#ds-fld-<?php print $field['name']; ?>').select2({ width: '300px' });
                jQuery('#ds-fld-<?php print $field['name']; ?>').on('change', function() {
                    if (typeof loadExpeditionGrid === 'function') loadExpeditionGrid(this.value);
                });
              } else {
                document.getElementById('ds-fld-<?php print $field['name']; ?>').addEventListener('change', function() {
                    if (typeof loadExpeditionGrid === 'function') loadExpeditionGrid(this.value);
                });
              }
            });
          </script>
                <?php elseif ($field['type'] === 'custom_rental_flow_grid'): ?>
          </label></div><div style="width:100%; margin-bottom: 20px;">
          <div id="rental_grid_container" style="margin-top: 15px; width: 100%; overflow-x: auto; background: #fff; padding: 15px; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
             <p class="opacitymedium"><em>Sélectionnez un projet de location pour afficher la grille de répartition.</em></p>
          </div>
          <script>
function loadRentalFlowGrid(project_id) {
                try {
                    if (!project_id) {
                        document.getElementById('rental_grid_container').innerHTML = '<p class="opacitymedium"><em>Sélectionnez un projet pour afficher la grille.</em></p>';
                        return;
                    }
                    document.getElementById('rental_grid_container').innerHTML = 'Chargement des données...';

                
                fetch('?action=fetch_project_flow_data&fk_project=' + project_id)
                .then(r => r.json())
                .then(data => {
                    if (data.error) { document.getElementById('rental_grid_container').innerHTML = '<span style="color:red">'+data.error+'</span>'; return; }
                    
                    let html = '<table class="liste" style="width:100%; margin-top:10px; border-collapse:collapse;">';
                    html += '<tr class="liste_titre">';
                    html += '<th style="text-align:left;">Produit / Cmd</th><th style="text-align:center; width: 100px;">Flux</th><th style="text-align:center; width: 100px;">Paramètre</th>';
                    
                    let mDate = new Date(data.start_date);
                    let months = [];
                    for(let i=0; i<12; i++) {
                        let y = mDate.getFullYear();
                        let m = mDate.getMonth() + 1;
                        let sDate = y + '-' + (m < 10 ? '0'+m : m) + '-01';
                        let sLabel = (m < 10 ? '0'+m : m) + '/' + y;
                        let lastDay = new Date(y, m, 0).getDate();
                        let eDate = y + '-' + (m < 10 ? '0'+m : m) + '-' + lastDay;
                        months.push({val: sDate, end: eDate, label: sLabel});
                        html += '<th style="text-align:center; font-size:0.9em; min-width:80px;">' + sLabel + '</th>';
                        mDate.setMonth(mDate.getMonth() + 1);
                    }
                    html += '<th style="text-align:center; width: 60px;">Total</th></tr>';
                    
                    let whRetOptions = '';
                    data.warehouses.forEach(wh => { 
                        let shortName = wh.ref.indexOf(' > ') !== -1 ? wh.ref.split(' > ').pop() : wh.ref;
                        whRetOptions += '<option value="'+wh.id+'" title="'+wh.ref+'">'+shortName+'</option>'; 
                    });
                    if(whRetOptions === '') whRetOptions = '<option value="0">Aucun</option>';
                    
                    let whExpOptions = '';
                    if(data.classic_warehouses) {
                        data.classic_warehouses.forEach(wh => { 
                            let shortName = wh.ref.indexOf(' > ') !== -1 ? wh.ref.split(' > ').pop() : wh.ref;
                            whExpOptions += '<option value="'+wh.id+'" title="'+wh.ref+'">'+shortName+'</option>'; 
                        });
                    }
                    if(whExpOptions === '') whExpOptions = '<option value="0">Aucun</option>';
                    
                    window.whExpOptionsGlobal = whExpOptions;
                    window.whRetOptionsGlobal = whRetOptions;
                    
                    data.lines.forEach(line => {
                        let remainExp = line.qty - line.shipped_qty;
                        let remainRet = line.shipped_qty - line.returned_qty;
                        
                        // EXPEDITION (1 row)
                        html += '<tr class="oddeven" style="border-top:2px solid #ccc;">';
                        html += '<td rowspan="2" style="vertical-align:top; background:#fff; padding-top:10px;"><b>'+line.ref+'</b><br><span class="opacitymedium" style="font-size:0.85em;">Cmd: '+line.cmd_ref+' (Qté: '+line.qty+')</span><br><br><span style="font-size:0.85em; color:#2e7d32; white-space:nowrap;">Expédié: '+line.shipped_qty+' <br><b>(Reste: '+remainExp+')</b></span><br><br><span style="font-size:0.85em; color:#e65100; white-space:nowrap;">Retourné: '+line.returned_qty+' <br><b>(Reste: '+remainRet+')</b></span></td>';
                        html += '<td style="background:#eef7e6; color:#2e7d32; font-weight:bold; text-align:center; vertical-align:middle;">Expédition</td>';
                        html += '<td style="background:#eef7e6; text-align:center; font-size:0.85em; font-weight:bold;">Répartition</td>';
                        months.forEach(m => {
                            html += '<td align="center" style="background:#f9fdf5; vertical-align:top; padding:5px; min-width:130px;">';
                            html += '<div id="blocks_exp_'+line.id+'_'+m.val+'">';
                            
                            // Existing expeditions
                            if (line.existing_exp && line.existing_exp.length > 0) {
                                line.existing_exp.forEach(e => {
                                    if (e.month === m.val.substring(0, 7)) {
                                        html += '<div style="font-size:0.8em; margin-bottom:5px; background:#e8f4f8; padding:3px; border-radius:2px; text-align:center;">'+e.url+' ('+e.qty+')<br><span style="color:#666;"><i class="fa fa-calendar"></i> '+e.date+'</span></div>';
                                    }
                                });
                            }
                            
                            // Initial block 0
                            html += '<div class="flow-block" style="border:1px solid #ddd; background:#fff; padding:5px; border-radius:3px; margin-bottom:5px; position:relative;">';
                            html += '<div style="display:flex; justify-content:space-between; margin-bottom:3px; gap:2px;">';
                            html += '<div style="display:flex; gap:2px;"><input type="number" min="0" max="100" class="flat grid-input-exp-pct" data-col="'+m.val+'" data-line="'+line.id+'" data-total="'+line.qty+'" name="grid_exp['+line.id+']['+m.val+'][0][pct]" value="" placeholder="%" style="width:40px; text-align:center;" oninput="syncQty(this, \'pct\'); updateGridTot(this, '+line.id+', \'exp\')">';
                            html += '<input type="number" min="0" max="'+line.qty+'" class="flat grid-input-exp-fixed" data-col="'+m.val+'" data-line="'+line.id+'" data-total="'+line.qty+'" name="grid_exp['+line.id+']['+m.val+'][0][fixed_qty]" value="" placeholder="Qté" style="width:40px; text-align:center;" oninput="syncQty(this, \'fixed\'); updateGridTot(this, '+line.id+', \'exp\')"></div>';
                            html += '<button type="button" onclick="this.closest(\'.flow-block\').remove(); updateGridTot(null, '+line.id+', \'exp\')" style="border:none;background:none;color:red;cursor:pointer;padding:0;" title="Supprimer"><i class="fa fa-times"></i></button>';
                            html += '</div>';
                            html += '<input type="date" name="grid_exp['+line.id+']['+m.val+'][0][date]" min="'+m.val+'" max="'+m.end+'" class="flat grid-input-exp-date" data-line="'+line.id+'" style="width:100%; margin-bottom:3px; font-size:0.85em; box-sizing:border-box;">';
                            html += '<select name="grid_exp['+line.id+']['+m.val+'][0][wh]" class="flat grid-input-exp-wh" data-line="'+line.id+'" style="width:100%; font-size:0.85em; box-sizing:border-box;">'+whExpOptions+'</select>';
                            html += '</div>';
                            html += '</div>';
                            html += '<button type="button" class="button" onclick="addBlock(\'exp\', '+line.id+', \''+m.val+'\', \''+m.val+'\', \''+m.end+'\')" style="width:100%; padding:2px; font-size:0.8em; margin-top:3px;"><i class="fa fa-plus"></i> Ajouter</button>';
                            html += '</td>';
                        });
                        html += '<td align="center" style="background:#f9fdf5; vertical-align:middle;"><strong id="tot_exp_'+line.id+'">0%</strong></td>';
                        html += '</tr>';
                        
                        // RETOUR (1 row)
                        html += '<tr class="oddeven" style="border-top:1px dashed #ccc;">';
                        html += '<td style="background:#fff4e6; color:#e65100; font-weight:bold; text-align:center; vertical-align:middle;">Retour</td>';
                        html += '<td style="background:#fff4e6; text-align:center; font-size:0.85em; font-weight:bold;">Répartition</td>';
                        months.forEach(m => {
                            html += '<td align="center" style="background:#fffcf5; vertical-align:top; padding:5px; min-width:130px;">';
                            html += '<div id="blocks_ret_'+line.id+'_'+m.val+'">';
                            
                            // Existing returns
                            if (line.existing_ret && line.existing_ret.length > 0) {
                                line.existing_ret.forEach(r => {
                                    if (r.month === m.val.substring(0, 7)) {
                                        html += '<div style="font-size:0.8em; margin-bottom:5px; background:#fce8e8; padding:3px; border-radius:2px; text-align:center;">'+r.url+' ('+r.qty+')<br><span style="color:#666;"><i class="fa fa-calendar"></i> '+r.date+'</span></div>';
                                    }
                                });
                            }
                            
                            // Initial block 0
                            html += '<div class="flow-block" style="border:1px solid #ddd; background:#fff; padding:5px; border-radius:3px; margin-bottom:5px; position:relative;">';
                            html += '<div style="display:flex; justify-content:space-between; margin-bottom:3px; gap:2px;">';
                            html += '<div style="display:flex; gap:2px;"><input type="number" min="0" max="100" class="flat grid-input-ret-pct" data-col="'+m.val+'" data-line="'+line.id+'" data-total="'+line.qty+'" name="grid_ret['+line.id+']['+m.val+'][0][pct]" value="" placeholder="%" style="width:40px; text-align:center;" oninput="syncQty(this, \'pct\'); updateGridTot(this, '+line.id+', \'ret\')">';
                            html += '<input type="number" min="0" max="'+line.qty+'" class="flat grid-input-ret-fixed" data-col="'+m.val+'" data-line="'+line.id+'" data-total="'+line.qty+'" name="grid_ret['+line.id+']['+m.val+'][0][fixed_qty]" value="" placeholder="Qté" style="width:40px; text-align:center;" oninput="syncQty(this, \'fixed\'); updateGridTot(this, '+line.id+', \'ret\')"></div>';
                            html += '<button type="button" onclick="this.closest(\'.flow-block\').remove(); updateGridTot(null, '+line.id+', \'ret\')" style="border:none;background:none;color:red;cursor:pointer;padding:0;" title="Supprimer"><i class="fa fa-times"></i></button>';
                            html += '</div>';
                            html += '<input type="date" name="grid_ret['+line.id+']['+m.val+'][0][date]" min="'+m.val+'" max="'+m.end+'" class="flat grid-input-ret-date" data-line="'+line.id+'" style="width:100%; margin-bottom:3px; font-size:0.85em; box-sizing:border-box;">';
                            html += '<select name="grid_ret['+line.id+']['+m.val+'][0][wh]" class="flat grid-input-ret-wh" data-line="'+line.id+'" style="width:100%; font-size:0.85em; box-sizing:border-box;">'+whRetOptions+'</select>';
                            html += '</div>';
                            html += '</div>';
                            html += '<button type="button" class="button" onclick="addBlock(\'ret\', '+line.id+', \''+m.val+'\', \''+m.val+'\', \''+m.end+'\')" style="width:100%; padding:2px; font-size:0.8em; margin-top:3px;"><i class="fa fa-plus"></i> Ajouter</button>';
                            html += '</td>';
                        });
                        html += '<td align="center" style="background:#fffcf5; vertical-align:middle;"><strong id="tot_ret_'+line.id+'">0%</strong></td>';
                        html += '</tr>';
                    });
                    
                    // Ligne de boutons "Exécuter ce mois"
                    html += '<tr class="liste_titre" style="border-top:2px solid #aaa;">';
                    html += '<th colspan="3" style="text-align:right;">Exécuter colonne par colonne :</th>';
                    months.forEach(m => {
                        html += '<th style="text-align:center;"><button type="button" class="button" style="padding: 4px 8px; font-size:0.85em;" onclick="executeMonth(\''+m.val+'\')">▶ Go</button></th>';
                    });
                    html += '<th></th></tr>';
                    
                    html += '</table>';
                    
                    html += '<div style="margin-top:20px; padding:15px; background:#f5f5f5; border:1px solid #ddd; border-radius:4px;">';
                    html += '<table style="width:100%;"><tr>';
                    html += '<td style="width:100%; vertical-align:top;">';
                    html += '<b><i class="fa fa-file-invoice-dollar" style="margin-right:5px; color:#2e7d32;"></i> Options globales :</b><br><br>';
                    html += '<label><input type="checkbox" name="gen_recurring_invoices" value="1" checked> Générer les factures récurrentes mensuelles depuis les expéditions générées</label>';
                    html += '</td></tr></table>';
                    html += '</div>';

                    html += '<div style="margin-top:15px; display:flex; gap:10px;">';
                    html += '<button type="button" class="button" onclick="randomizeFlowGrid(\''+data.start_date+'\')"><i class="fa fa-magic"></i> Répartir Aléatoirement</button>';
                    html += '<button type="button" class="button" onclick="resetFlowGrid()"><i class="fa fa-trash"></i> Effacer la grille</button>';
                    html += '<button type="button" class="button" onclick="exportScenario()" style="margin-left:auto;"><i class="fa fa-download"></i> Exporter JSON</button>';
                    html += '<button type="button" class="button" onclick="document.getElementById(\'import-json-file\').click()"><i class="fa fa-upload"></i> Importer JSON</button>';
                    html += '<input type="file" id="import-json-file" accept=".json" style="display:none;" onchange="importScenario(event)">';
                    html += '</div>';
                    
                    document.getElementById('rental_grid_container').innerHTML = html;
                }).catch(e => { document.getElementById('rental_grid_container').innerHTML = 'Erreur: ' + e; });
                } catch(err) {
                    document.getElementById('rental_grid_container').innerHTML = 'Erreur JS interne: ' + err;
                }
            }
            
            let blockIndexCounter = 100;
            function addBlock(type, lineId, colDate, minDate, maxDate) {
                let container = document.getElementById('blocks_' + type + '_' + lineId + '_' + colDate);
                let optionsHtml = type === 'exp' ? window.whExpOptionsGlobal : window.whRetOptionsGlobal;
                let lineQty = document.querySelector('input.grid-input-'+type+'-pct[data-line="'+lineId+'"]'); // find any existing one to get the total
                let totalData = lineQty ? lineQty.getAttribute('data-total') : '0';
                
                let html = `
                <div class="flow-block" style="border:1px solid #ddd; background:#fff; padding:5px; border-radius:3px; margin-bottom:5px; position:relative;">
                    <div style="display:flex; justify-content:space-between; margin-bottom:3px; gap:2px;">
                        <div style="display:flex; gap:2px;">
                            <input type="number" min="0" max="100" name="grid_${type}[${lineId}][${colDate}][${blockIndexCounter}][pct]" value="" placeholder="%" style="width:40px; text-align:center;" class="flat grid-input-${type}-pct" data-col="${colDate}" data-line="${lineId}" data-total="${totalData}" oninput="syncQty(this, 'pct'); updateGridTot(this, ${lineId}, '${type}')">
                            <input type="number" min="0" max="${totalData}" name="grid_${type}[${lineId}][${colDate}][${blockIndexCounter}][fixed_qty]" value="" placeholder="Qté" style="width:40px; text-align:center;" class="flat grid-input-${type}-fixed" data-col="${colDate}" data-line="${lineId}" data-total="${totalData}" oninput="syncQty(this, 'fixed'); updateGridTot(this, ${lineId}, '${type}')">
                        </div>
                        <button type="button" onclick="this.closest('.flow-block').remove(); updateGridTot(null, ${lineId}, '${type}')" style="border:none;background:none;color:red;cursor:pointer;padding:0;" title="Supprimer"><i class="fa fa-times"></i></button>
                    </div>
                    <input type="date" name="grid_${type}[${lineId}][${colDate}][${blockIndexCounter}][date]" min="${minDate}" max="${maxDate}" class="flat grid-input-${type}-date" data-line="${lineId}" style="width:100%; margin-bottom:3px; font-size:0.85em; box-sizing:border-box;">
                    <select name="grid_${type}[${lineId}][${colDate}][${blockIndexCounter}][wh]" class="flat grid-input-${type}-wh" data-line="${lineId}" style="width:100%; font-size:0.85em; box-sizing:border-box;">${optionsHtml}</select>
                </div>`;
                container.insertAdjacentHTML('beforeend', html);
                blockIndexCounter++;
            }
            
            function updateGridTot(input, lineId, type) {
                let inputs = document.querySelectorAll('input.grid-input-'+type+'-pct[data-line="'+lineId+'"]');
                let sum = 0; inputs.forEach(i => sum += parseInt(i.value || 0));
                let totEl = document.getElementById('tot_'+type+'_'+lineId);
                if (totEl) {
                    totEl.innerHTML = sum + '%';
                    if (sum < 100) totEl.style.color = 'orange'; else if (sum > 100) totEl.style.color = 'red'; else totEl.style.color = 'green';
                }
            }

            function syncQty(input, source) {
                let block = input.closest('.flow-block');
                if (!block) return;
                let pctInp = block.querySelector('input[name$="[pct]"]');
                let fixedInp = block.querySelector('input[name$="[fixed_qty]"]');
                let total = parseInt(input.getAttribute('data-total') || 0);
                
                if (source === 'pct') {
                    let val = pctInp.value;
                    if (val !== '' && parseFloat(val) > 0) {
                        fixedInp.value = Math.round((parseFloat(val) / 100) * total);
                        fixedInp.style.backgroundColor = '#eeeeee';
                        fixedInp.readOnly = true;
                    } else {
                        fixedInp.style.backgroundColor = '';
                        fixedInp.readOnly = false;
                        fixedInp.value = '';
                    }
                } else if (source === 'fixed') {
                    let val = fixedInp.value;
                    if (val !== '' && parseFloat(val) > 0) {
                        if (total > 0) pctInp.value = Math.round((parseFloat(val) / total) * 100);
                        pctInp.style.backgroundColor = '#eeeeee';
                        pctInp.readOnly = true;
                    } else {
                        pctInp.style.backgroundColor = '';
                        pctInp.readOnly = false;
                        pctInp.value = '';
                    }
                }
            }
            
            function randomizeFlowGrid(startDate) {
                // Keep only one block per cell to avoid multiplying them randomly
                document.querySelectorAll('.flow-block').forEach(b => {
                    let container = b.parentElement;
                    if (container.children[0] !== b) b.remove();
                });
                
                let inputsExpPct = document.querySelectorAll('input.grid-input-exp-pct');
                let inputsRetPct = document.querySelectorAll('input.grid-input-ret-pct');
                
                inputsExpPct.forEach(i => i.value = 0);
                inputsRetPct.forEach(i => i.value = 0);
                
                let lines = {};
                inputsExpPct.forEach(i => { let id = i.getAttribute('data-line'); if (!lines[id]) lines[id] = {exp:[], ret:[]}; lines[id].exp.push(i); });
                inputsRetPct.forEach(i => { let id = i.getAttribute('data-line'); if (lines[id]) lines[id].ret.push(i); });
                
                for(let id in lines) {
                    let arrExp = lines[id].exp;
                    let arrRet = lines[id].ret;
                    let totExp = 100;
                    let totRet = 100;
                    
                    while(totExp > 0 && arrExp.length > 0) {
                        let idx = Math.floor(Math.random() * Math.min(3, arrExp.length));
                        let val = parseInt(arrExp[idx].value || 0);
                        let add = Math.min(totExp, Math.floor(Math.random() * 20) + 10);
                        arrExp[idx].value = val + add;
                        totExp -= add;
                    }
                    if(totExp < 0 && arrExp.length > 0) { arrExp[0].value = parseInt(arrExp[0].value) + totExp; }
                    
                    while(totRet > 0 && arrRet.length > 0) {
                        let idx = Math.floor(Math.random() * 4) + (arrRet.length - 4);
                        if(idx >= arrRet.length) idx = arrRet.length - 1;
                        if(idx < 0) idx = 0;
                        let val = parseInt(arrRet[idx].value || 0);
                        let add = Math.min(totRet, Math.floor(Math.random() * 30) + 20);
                        arrRet[idx].value = val + add;
                        totRet -= add;
                    }
                    if(totRet < 0 && arrRet.length > 0) { arrRet[arrRet.length-1].value = parseInt(arrRet[arrRet.length-1].value) + totRet; }
                    
                    if(arrExp.length > 0) updateGridTot(arrExp[0], id, 'exp');
                    if(arrRet.length > 0) updateGridTot(arrRet[0], id, 'ret');
                    
                    arrExp.forEach(inp => { syncQty(inp, 'pct'); });
                    arrRet.forEach(inp => { syncQty(inp, 'pct'); });
                }
                
                // Pré-remplir les dates et entrepôts pour les mois où il y a un pourcentage
                let globalDefExpWh = document.getElementById('default_exp_warehouse');
                ['exp', 'ret'].forEach(type => {
                    document.querySelectorAll('input.grid-input-'+type+'-pct').forEach(inp => {
                        let val = parseInt(inp.value || 0);
                        if (val > 0) {
                            let block = inp.closest('.flow-block');
                            let m = inp.getAttribute('data-col');
                            let dateInp = block.querySelector('.grid-input-'+type+'-date');
                            let whSel = block.querySelector('.grid-input-'+type+'-wh');
                            
                            if(dateInp && !dateInp.value) {
                                let dayStr = startDate.split('-')[2];
                                let yearStr = m.substring(0, 4);
                                let monthStr = m.substring(5, 7);
                                let targetDate = new Date(yearStr, parseInt(monthStr, 10) - 1, parseInt(dayStr, 10));
                                if (targetDate.getMonth() !== parseInt(monthStr, 10) - 1) {
                                    targetDate = new Date(yearStr, parseInt(monthStr, 10), 0);
                                }
                                let targetDateStr = targetDate.getFullYear() + '-' + String(targetDate.getMonth() + 1).padStart(2, '0') + '-' + String(targetDate.getDate()).padStart(2, '0');
                                dateInp.value = targetDateStr;
                            }
                            if(whSel && whSel.options.length > 0) {
                                if (type === 'exp' && globalDefExpWh && globalDefExpWh.value) {
                                    whSel.value = globalDefExpWh.value;
                                } else {
                                    for(let i=0; i<whSel.options.length; i++) {
                                        if(whSel.options[i].value !== "0") { whSel.selectedIndex = i; break; }
                                    }
                                }
                            }
                        }
                    });
                });
            }

            function resetFlowGrid() {
                // Keep only one block per cell
                document.querySelectorAll('.flow-block').forEach(b => {
                    let container = b.parentElement;
                    if (container.children[0] !== b) b.remove();
                });
                document.querySelectorAll('input[type="number"]').forEach(i => { i.value = 0; i.dispatchEvent(new Event('change')); });
                document.querySelectorAll('input[type="date"]').forEach(i => i.value = '');
            }

            function executeMonth(monthStr) {
                // Mettre à zéro tous les pourcentages qui ne sont pas de ce mois
                document.querySelectorAll('input.grid-input-exp-pct, input.grid-input-ret-pct').forEach(inp => {
                    if (inp.getAttribute('data-col') !== monthStr) {
                        inp.value = 0;
                    }
                });
                executeRentalWorkflow();
            }

            function executeRentalWorkflow() {
                let form = document.getElementById('ds-run-form');
                let fd = new FormData(form);
                fd.append('ajax_run', '1');
                
                // Spinner
                let ring = document.getElementById('ds-ring');
                let circle = document.getElementById('ds-ring-circle');
                if (ring) { ring.style.visibility = 'visible'; circle.style.animationPlayState = 'running'; }
                
                let cb = document.getElementById('ds-cb');
                if (cb) { cb.style.display = 'block'; cb.innerHTML = ''; }
                
                let targetUrl = '<?php echo dol_buildpath("/custom/dolistream/ajax/run.php", 1); ?>';
                fetch(targetUrl, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (ring) { ring.style.visibility = 'hidden'; circle.style.animationPlayState = 'paused'; }
                    
                    if (data.created) {
                        data.created.forEach(obj => {
                            let container = document.getElementById('blocks_'+obj.type+'_'+obj.line_id+'_'+obj.col);
                            if (container) {
                                let a = document.createElement('div');
                                let color = obj.type === 'exp' ? '#e8f4f8' : '#fce8e8';
                                a.innerHTML = '<div style="font-size:0.8em; margin-top:2px; background:'+color+'; padding:3px; border-radius:2px; text-align:center;">' + obj.url + ' ('+obj.qty+')<br><span style="color:#666;"><i class="fa fa-calendar"></i> '+obj.date+'</span></div>';
                                container.appendChild(a);
                            }
                        });
                    }
                    if (data.logs) {
                        if (cb) {
                            data.logs.forEach(l => {
                                let line = document.createElement('div');
                                line.className = 'ds-log-line';
                                line.innerHTML = '<span class="ds-log-time">'+(l.time||'')+'</span> <span class="'+(l.cls||'ds-log-i')+'">'+(l.msg||'')+'</span>';
                                cb.appendChild(line);
                            });
                            cb.scrollTop = cb.scrollHeight;
                        }
                    }
                })
                .catch(e => {
                    if (ring) { ring.style.visibility = 'hidden'; circle.style.animationPlayState = 'paused'; }
                    alert("Erreur: " + e);
                });
            }

            function exportScenario() {
                let form = document.getElementById('ds-run-form');
                let fd = new FormData(form);
                let scenario = { exp: {}, ret: {} };
                for (let [key, value] of fd.entries()) {
                    let m = key.match(/grid_(exp|ret)\[(\d+)\]\[([^\]]+)\]\[(\d+)\]\[(pct|date|wh)\]/);
                    if (m) {
                        let type = m[1], lineId = m[2], month = m[3], idx = m[4], field = m[5];
                        if (!scenario[type][lineId]) scenario[type][lineId] = {};
                        if (!scenario[type][lineId][month]) scenario[type][lineId][month] = {};
                        if (!scenario[type][lineId][month][idx]) scenario[type][lineId][month][idx] = {};
                        scenario[type][lineId][month][idx][field] = value;
                    }
                }
                for (let type in scenario) {
                    for (let lineId in scenario[type]) {
                        for (let month in scenario[type][lineId]) {
                            let blocks = scenario[type][lineId][month];
                            for (let idx in blocks) {
                                if (!blocks[idx].pct || parseInt(blocks[idx].pct) === 0) {
                                    delete blocks[idx];
                                }
                            }
                            if (Object.keys(blocks).length === 0) delete scenario[type][lineId][month];
                        }
                        if (Object.keys(scenario[type][lineId]).length === 0) delete scenario[type][lineId];
                    }
                }
                let blob = new Blob([JSON.stringify(scenario, null, 2)], {type: 'application/json'});
                let url = URL.createObjectURL(blob);
                let a = document.createElement('a');
                a.href = url;
                a.download = 'scenario_location_' + new Date().getTime() + '.json';
                a.click();
            }

            function importScenario(event) {
                let file = event.target.files[0];
                if (!file) return;
                let reader = new FileReader();
                reader.onload = function(e) {
                    try {
                        let scenario = JSON.parse(e.target.result);
                        resetFlowGrid();
                        for (let type in scenario) {
                            for (let lineId in scenario[type]) {
                                for (let month in scenario[type][lineId]) {
                                    let blocks = scenario[type][lineId][month];
                                    let container = document.getElementById('blocks_'+type+'_'+lineId+'_'+month);
                                    if (!container) continue;
                                    container.innerHTML = '';
                                    let bKeys = Object.keys(blocks);
                                    for (let i=0; i<bKeys.length; i++) {
                                        let bData = blocks[bKeys[i]];
                                        addBlock(type, lineId, month, month+'-01', month+'-31');
                                        let newIdx = blockIndexCounter - 1;
                                        let pctInp = document.getElementsByName('grid_'+type+'['+lineId+']['+month+']['+newIdx+'][pct]')[0];
                                        let dateInp = document.getElementsByName('grid_'+type+'['+lineId+']['+month+']['+newIdx+'][date]')[0];
                                        let whInp = document.getElementsByName('grid_'+type+'['+lineId+']['+month+']['+newIdx+'][wh]')[0];
                                        if (pctInp) { pctInp.value = bData.pct; updateGridTot(pctInp, lineId, type); }
                                        if (dateInp && bData.date) dateInp.value = bData.date;
                                        if (whInp && bData.wh) whInp.value = bData.wh;
                                    }
                                    if (bKeys.length === 0) {
                                        addBlock(type, lineId, month, month+'-01', month+'-31');
                                    }
                                }
                            }
                        }
                    } catch(err) {
                        alert("Erreur JSON: " + err);
                    }
                    event.target.value = '';
                };
                reader.readAsText(file);
            }
                    </script>
          </div><div style="display:none"><label>
        <?php elseif ($field['type'] === 'multiselect_rental_product'): ?>
          <select name="<?php print $field['name']; ?>[]" id="ds-fld-<?php print $field['name']; ?>" class="flat" multiple="multiple" required style="min-width:300px; max-width:500px;">
          <?php
            global $db;
            $res = $db->query("SELECT p.rowid, p.ref, p.label, pe.rental_price FROM " . MAIN_DB_PREFIX . "product p LEFT JOIN " . MAIN_DB_PREFIX . "product_extrafields pe ON pe.fk_object = p.rowid WHERE p.tosell=1 AND pe.rental_product=1 ORDER BY p.ref DESC LIMIT 200");
            while ($res && $obj = $db->fetch_object($res)) {
              print '<option value="'.$obj->rowid.'">'.htmlspecialchars($obj->ref . ' - ' . $obj->label . ' (' . round((float)$obj->rental_price, 2) . ' €/j)').'</option>';
            }
          ?>
          </select>
          <script>
            document.addEventListener('DOMContentLoaded', function() {
              if (typeof jQuery !== 'undefined' && jQuery.fn.select2) {
                jQuery('#ds-fld-<?php print $field['name']; ?>').select2({
                  placeholder: "Sélectionnez des produits...",
                  width: '300px'
                });
              }
            });
          </script>
        <?php elseif ($field['type'] === 'checkbox'): ?>
          <input type="checkbox" name="<?php print $field['name']; ?>" id="ds-fld-<?php print $field['name']; ?>" value="1" <?php print (!empty($field['default']) ? 'checked' : ''); ?>>
        <?php elseif ($field['type'] === 'number'): ?>
          <input type="number" name="<?php print $field['name']; ?>" id="ds-fld-<?php print $field['name']; ?>" class="flat" style="width:70px;"
            value="<?php print (int)($field['default'] ?? 10); ?>"
            min="<?php print $field['min'] ?? 1; ?>" max="<?php print $field['max'] ?? 100000; ?>" required>
        <?php elseif ($field['type'] === 'date'): ?>
          <input type="date" name="<?php print $field['name']; ?>" id="ds-fld-<?php print $field['name']; ?>" class="flat"
            value="<?php print htmlspecialchars($field['default'] ?? ''); ?>" required>
        <?php else: ?>
          <input type="text" name="<?php print $field['name']; ?>" class="flat minwidth200"
            value="<?php print htmlspecialchars($field['default'] ?? ''); ?>"
            placeholder="<?php print htmlspecialchars($field['placeholder'] ?? ''); ?>">
        <?php endif; ?>
      </label>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
</form>

<?php if ($activeScript === 'generate-stock' || $activeScript === 'generate-product'): ?>
<script>
(function(){
  var sel = document.getElementById('ds-fld-batch_mode');
  var qty = document.getElementById('ds-fld-qty_max') || document.getElementById('ds-fld-stock_qty_max');
  if (!sel || !qty) return;
  function _update() {
    var isSerial = sel.value === 'serial';
    qty.disabled = isSerial;
    qty.value    = isSerial ? 1 : (qty._saved || qty.value);
    qty.style.background = isSerial ? '#e8e8e8' : '';
    qty.style.color      = isSerial ? '#999' : '';
    qty.title            = isSerial ? 'Quantité forcée à 1 (numéro de série unique par unité)' : '';
    if (!isSerial && qty._saved) { qty.value = qty._saved; }
  }
  sel.addEventListener('change', function() {
    if (sel.value !== 'serial') qty._saved = qty.value;
    _update();
  });
  _update(); // init au chargement
})();
</script>
<?php endif; ?>

<?php
// ── Tableau DB (style Dolibarr list) ─────────────────────────────────────────
$_okCount   = count(array_filter($scriptLog, fn($l) => $l['level'] === 'success'));
$_errCount  = count(array_filter($scriptLog, fn($l) => $l['level'] === 'error'));
$_warnCount = count(array_filter($scriptLog, fn($l) => $l['level'] === 'warn'));
?>

<?php if (!empty($dbResults)): ?>
<?php
$numToPass = ($dbNewCount > 0) ? $dbNewCount : $num_res;
print_barre_liste(
	'Éléments créés',
	$page, $_SERVER['PHP_SELF'], 'script=' . urlencode($activeScript),
	'', '', '',
	$numToPass,
	$numToPass,
	'', 0, '', '', $limit
);

?>
<form method="POST" action="<?php print $_SERVER['PHP_SELF']; ?>" id="mass-action-form">
<input type="hidden" name="token" value="<?php print newToken(); ?>">
<input type="hidden" name="action" value="mass_action_ship_orders">
<input type="hidden" name="script" value="<?php print htmlspecialchars($activeScript); ?>">
<?php if ($activeScript === 'generate-order'): ?>
<div style="margin-bottom: 10px;">
    <button type="submit" class="butAction" name="mass_action_btn" value="validate_ship"><?php print $langs->transnoentities('ValidateAndShip'); ?></button>
</div>
<?php endif; ?>
<table class="noborder centpercent">
<thead>
<tr class="liste_titre">
  <th class="notopandbottom" style="width:20px"><input type="checkbox" class="flat checkall" id="checkall"></th>
  <th style="width:40px">Id</th>
  <th>Réf.</th>
  <?php foreach ($dbHead as $_dh): ?><th><?php print htmlspecialchars($_dh); ?></th><?php endforeach; ?>
</tr>
</thead>
<tbody>
<?php foreach ($dbResults as $_dbRow):
	$_dvals  = array_values($_dbRow);
	$_drowid = (int)$_dvals[0];
	$_dref   = htmlspecialchars((string)$_dvals[1]);
	$_dlink  = $dbUrl ? '<a href="' . DOL_URL_ROOT . $dbUrl . $_drowid . '">' . $_dref . '</a>' : $_dref;
?>
<tr class="oddeven">
  <td><input type="checkbox" class="flat checkforselect" name="toselect[]" value="<?php print $_drowid; ?>"></td>
  <td style="color:#999;font-size:.85em"><?php print $_drowid; ?></td>
  <td><?php print $_dlink; ?></td>
  <?php for ($_di = 2; $_di < count($_dvals); $_di++): ?>
    <td><?php print htmlspecialchars((string)$_dvals[$_di]); ?></td>
  <?php endfor; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</form>

<?php if ($acId > 0): ?>
<div style="margin:8px 0 4px;font-size:0.85em;color:#555;">
	📌 <a href="<?php print DOL_URL_ROOT; ?>/comm/action/card.php?id=<?php print $acId; ?>">Voir l'ActionComm enregistrée</a>
	<span style="color:#999;margin-left:8px;"><?php print htmlspecialchars($acLabel); ?></span>
</div>
<?php endif; ?>

<?php else: ?>
<div class="info">Aucun élément retourné par la base (vérifiez les logs ci-dessous).</div>
<?php endif; ?>

<?php endif; /* end tab index */ ?>

<?php if ($activeTab === 'agenda'): ?>
<?php
// ── Tableau ActionComm ───────────────────────────────────────────────────────
print_barre_liste(
	'Événements générés (ActionComm)',
	$page, $_SERVER['PHP_SELF'], 'script=' . urlencode($activeScript),
	'', '', '',
	0, 0, '', 0, '', '', 25
);

$sql = "SELECT a.id as rowid, a.label, a.datep, a.note as note_private ";
$sql .= "FROM " . MAIN_DB_PREFIX . "actioncomm as a ";
$sql .= "WHERE a.label LIKE 'DoliStream %' ";
$sql .= "ORDER BY a.datep DESC LIMIT 50";
$resql = $db->query($sql);
if ($resql) {
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<th style="width:100px;">ID</th>';
	print '<th style="width:160px;">' . $langs->trans('Date') . '</th>';
	print '<th style="width:250px;">' . $langs->trans('Label') . '</th>';
	print '<th>Log / Description</th>';
	print '</tr>';
	$num = $db->num_rows($resql);
	if ($num) {
		while ($obj = $db->fetch_object($resql)) {
			print '<tr class="oddeven">';
			print '<td class="nowrap"><a href="' . DOL_URL_ROOT . '/comm/action/card.php?id=' . $obj->rowid . '">' . img_object('', 'action') . ' ' . $obj->rowid . '</a></td>';
			print '<td class="nowrap">' . dol_print_date($db->jdate($obj->datep), 'dayhour') . '</td>';
			print '<td><strong>' . htmlspecialchars($obj->label) . '</strong></td>';
			print '<td><div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; background: #fafafa; padding: 4px;">' . $obj->note_private . '</div></td>';
			print '</tr>';
		}
	} else {
		print '<tr><td colspan="4" class="opacitymedium" style="padding: 20px; text-align: center;">Aucun événement généré pour le moment.<br>Lancez un script pour voir les logs d\'exécution ici.</td></tr>';
	}
	print '</table>';
	$db->free($resql);
} else {
	print $db->error();
}
?>
<?php endif; /* end tab agenda */ ?>

<?php
// ── Console : charge dernier fichier log si pas de session courante ───────────
$_dsLogSource = !empty($scriptLog) ? 'live' : 'file';
$_dsLogLines  = array();  // {time, cls, msg}
$_dsLogLabel  = 'Aucun log';
$_dsAutoOpen  = false;

if ($_dsLogSource === 'live') {
	foreach ($scriptLog as $_cl) {
		$_dsLogLines[] = array(
			'time' => $_cl['time'] ?? date('H:i:s'),
			'cls'  => 'ds-log-' . (($_cl['level'] ?? 'i')[0]),
			'msg'  => $_cl['msg'],
		);
	}
	$_okN   = count(array_filter($scriptLog, fn($l) => $l['level'] === 'success'));
	$_errN  = count(array_filter($scriptLog, fn($l) => $l['level'] === 'error'));
	$_dsLogLabel = htmlspecialchars($script) . ' &nbsp;| &nbsp;<span style="color:#3fb950">' . $_okN . ' OK</span>';
	if ($_errN > 0) $_dsLogLabel .= ' &nbsp;| &nbsp;<span style="color:#f85149">' . $_errN . ' Err</span>';
	$_dsAutoOpen = true;
} else {
	$_logDir = DOL_DATA_ROOT . '/dolistream/';
	if (is_dir($_logDir)) {
		$_files = glob($_logDir . 'dolistream-*.txt');
		if (!empty($_files)) {
			usort($_files, fn($a,$b) => filemtime($b) - filemtime($a));
			$logFilePath = $_files[0];
			$_rawLines = @file($_files[0], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
			$_dsLogLabel = 'Dernier : <span style="color:#8b949e">' . htmlspecialchars(basename($_files[0])) . '</span>';
			if ($_rawLines) {
				foreach ($_rawLines as $_rl) {
					// Parse: HH:MM:SS [OK]/[ERR]/[WRN] message
					if (preg_match('/^(\d{2}:\d{2}:\d{2}) \[(OK|ERR|WRN)\]\s*(.*)$/', $_rl, $_m)) {
						$_clsMap = array('OK' => 'ds-log-s', 'ERR' => 'ds-log-e', 'WRN' => 'ds-log-w');
						$_dsLogLines[] = array('time' => $_m[1], 'cls' => ($_clsMap[$_m[2]] ?? 'ds-log-i'), 'msg' => $_m[3]);
					} elseif (preg_match('/^(Script|Param|Date)\s+: /i', $_rl)) {
						$_dsLogLines[] = array('time' => '     ', 'cls' => 'ds-log-i', 'msg' => $_rl);
					}
				}
			}
		}
	}
}
?>
<style>
.ds-console-popup{position:fixed;bottom:0;right:24px;width:660px;max-width:calc(100vw - 48px);background:#0d1117;border:1px solid #30363d;border-bottom:none;border-radius:8px 8px 0 0;font-family:'Consolas','Courier New',monospace;z-index:9999;box-shadow:0 -4px 20px rgba(0,0,0,.5);}
.ds-con-hd{display:flex;align-items:center;justify-content:space-between;padding:7px 14px;background:#161b22;border-bottom:1px solid #30363d;border-radius:8px 8px 0 0;cursor:pointer;user-select:none;}
.ds-con-title{color:#58a6ff;font-weight:700;font-size:.82em;letter-spacing:.5px;white-space:nowrap;}
.ds-con-sub{color:#6e7681;font-size:.76em;margin-left:10px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.ds-con-acts{display:flex;gap:10px;align-items:center;font-size:.76em;color:#8b949e;flex-shrink:0;}
.ds-con-acts button{background:none;border:none;color:#8b949e;cursor:pointer;padding:0;font-family:inherit;font-size:1em;}
.ds-con-acts button:hover{color:#c9d1d9;}
.ds-con-sep{color:#30363d;}
.ds-con-body{height:260px;overflow-y:auto;padding:8px 14px;scroll-behavior:smooth;}
.ds-log-line{display:flex;gap:8px;margin-bottom:2px;font-size:.76em;line-height:1.5;}
.ds-log-time{color:#484f58;min-width:56px;flex-shrink:0;}
.ds-log-pfx{color:#58a6ff;flex-shrink:0;}
.ds-log-s{color:#3fb950;}.ds-log-e{color:#f85149;}.ds-log-w{color:#d29922;}.ds-log-i{color:#c9d1d9;}
</style>
<div class="ds-console-popup" id="ds-cp">
  <div class="ds-con-hd" onclick="dsToggle()">
    <span style="display:flex;align-items:center;min-width:0;overflow:hidden;">
      <span class="ds-con-title">&gt;_ CONSOLE</span>
      <span class="ds-con-sub"><?php print $_dsLogLabel; ?></span>
    </span>
    <span class="ds-con-acts" onclick="event.stopPropagation()">
      <?php if (!empty($logFilePath)): ?>
      <button onclick="window.open('<?php print DOL_URL_ROOT; ?>/dolistream/view/download_log.php?f=<?php print urlencode(basename($logFilePath)); ?>','_blank')" title="Telecharger">&#11015;</button>
      <span class="ds-con-sep">|</span>
      <?php endif; ?>
      <button onclick="dsCopy()">Copier</button><span class="ds-con-sep">|</span>
      <button onclick="dsClear()">Vider</button><span class="ds-con-sep">|</span>
      <button id="ds-arr" onclick="dsToggle()" title="Ouvrir / Fermer">&#9650;</button>
    </span>
  </div>
  <div class="ds-con-body" id="ds-cb" style="display:none">
  <?php foreach ($_dsLogLines as $_cl): ?>
    <div class="ds-log-line">
      <span class="ds-log-time"><?php print htmlspecialchars($_cl['time']); ?></span>
      <span class="ds-log-pfx">&gt;_</span>
      <span class="<?php print $_cl['cls']; ?>"><?php print htmlspecialchars($_cl['msg']); ?></span>
    </div>
  <?php endforeach; ?>
  </div>
</div>
<script>
var _dsClosed=true;
function dsToggle(){var b=document.getElementById('ds-cb'),a=document.getElementById('ds-arr');_dsClosed=!_dsClosed;b.style.display=_dsClosed?'none':'block';a.textContent=_dsClosed?'▲':'▼';}
function dsClear(){document.getElementById('ds-cb').innerHTML='';}
function dsCopy(){
  var lines=document.querySelectorAll('#ds-cb .ds-log-line'),txt='';
  lines.forEach(function(l){var t=l.querySelector('.ds-log-time');var m=l.querySelector('[class^=ds-log-]');txt+=(t?t.textContent:'')+' >_ '+(m?m.textContent:'')+"\n";});
  if(navigator.clipboard){navigator.clipboard.writeText(txt).then(function(){var b=event.target;b.textContent='✓';setTimeout(function(){b.textContent='Copier';},2000);});}
}
<?php if ($_dsAutoOpen): ?>
(function(){dsToggle();var b=document.getElementById('ds-cb');if(b)setTimeout(function(){b.scrollTop=b.scrollHeight;},50);})();
<?php endif; ?>
</script>



<?php else: ?>
<div class="info">Sélectionnez un script dans le menu de gauche.</div>
<?php endif; ?>

</div><!-- /fiche -->

<script>
(function () {
  var ring   = document.getElementById('ds-ring');
  var circle = document.getElementById('ds-ring-circle');
  var form   = document.getElementById('ds-run-form');
  if (!ring || !circle || !form) return;

  // URL de l'endpoint AJAX (sans mainmenu/idmenu Dolibarr)
  var _ajaxUrl = '<?php echo DOL_URL_ROOT . "/custom/dolistream/ajax/run.php"; ?>';
  // URL de polling des logs
  var _pollBase = '<?php echo dol_escape_js($_SERVER["PHP_SELF"]); ?>?action=poll_log';

  var _pollTimer    = null;
  var _pollCount    = 0;
  var _prevMaxRowid = 0;
  var _abortCtrl    = null;
  var runBtn = document.getElementById('ds-run-btn');

  // Transform button to STOP (or back to EXÉCUTER)
  function _setRunning(yes) {
    if (!runBtn) return;
    if (yes) {
      runBtn.setAttribute('data-ot', runBtn.textContent);
      runBtn.setAttribute('data-oc', runBtn.className);
      runBtn.textContent = '\u25a0 ARR\u00caTER';
      runBtn.className   = 'butActionDelete';
      runBtn.type        = 'button';
      runBtn.onclick     = function () {
        if (_abortCtrl) _abortCtrl.abort();
      };
    } else {
      runBtn.textContent = runBtn.getAttribute('data-ot') || '\u25b6 EX\u00c9CUTER';
      runBtn.className   = runBtn.getAttribute('data-oc') || 'butAction';
      runBtn.type        = 'submit';
      runBtn.onclick     = null;
    }
  }

  function _reset(msg) {
    _stopPoll();
    ring.style.visibility           = 'hidden';
    circle.style.animationPlayState = 'paused';
    _setRunning(false);
    _abortCtrl = null;
    if (msg) _appendLog({ level: 'warn', time: new Date().toTimeString().slice(0,8), msg: msg });
  }

  // ── Helpers d'affichage console ─────────────────────────────────────────────
  function _levelClass(lv) {
    if (lv === 'success') return 'ds-log-s';
    if (lv === 'error')   return 'ds-log-e';
    if (lv === 'warn')    return 'ds-log-w';
    return 'ds-log-i';
  }

  function _appendLog(entry) {
    var body = document.getElementById('ds-cb');
    if (!body) return;
    var line = document.createElement('div');
    line.className = 'ds-log-line';
    line.innerHTML = '<span class="ds-log-time">' + (entry.time || '') + '</span> '
      + '<span class="' + _levelClass(entry.level) + '">'
      + String(entry.msg || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      + '</span>';
    body.appendChild(line);
    body.scrollTop = body.scrollHeight;
    var hd = document.querySelector('.ds-con-hd .ds-con-last');
    if (hd) hd.textContent = entry.msg;
  }

  function _ensureConsoleOpen() {
    var b   = document.getElementById('ds-cb');
    var arr = document.getElementById('ds-arr');
    if (!b) return;
    b.style.display = 'block';
    if (arr) arr.textContent = '▼';
    b.innerHTML = ''; // vide avant nouvelle exécution
  }

  // ── Polling du fichier live ──────────────────────────────────────────────────
  function _startPoll(scriptName) {
    _pollCount = 0;
    var url = _pollBase + '&script=' + encodeURIComponent(scriptName);
    _pollTimer = setInterval(function () {
      fetch(url + '&since=' + _pollCount, { credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) {
          if (!data) return;
          data.lines.forEach(function (raw) {
            try { _appendLog(JSON.parse(raw)); } catch (e) {}
          });
          _pollCount = data.total;
        })
        .catch(function () {});
    }, 300);
  }

  function _stopPoll() {
    if (_pollTimer) { clearInterval(_pollTimer); _pollTimer = null; }
  }

  // ── Soumission AJAX ──────────────────────────────────────────────────────────
  form.addEventListener('submit', function (e) {
    var nbInput = form.querySelector('[name="nb"]');
    var nb = nbInput ? parseInt(nbInput.value, 10) : Infinity;
    if (nb < 1) return;

    e.preventDefault();

    // Crée un AbortController pour permettre l'arrêt
    _abortCtrl = new AbortController();

    // Spinner + bouton STOP + console
    ring.style.visibility           = 'visible';
    circle.style.animationPlayState = 'running';
    _setRunning(true);
    _ensureConsoleOpen();

    var scriptInput = form.querySelector('[name="script"]');
    var scriptName  = scriptInput ? scriptInput.value : '';
    _startPoll(scriptName);

    // POST vers l'endpoint dédié
    var fd = new FormData(form);
    fetch(_ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin', signal: _abortCtrl.signal })
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function (data) {
        _prevMaxRowid = (data && data.prev_max_rowid) ? data.prev_max_rowid : 0;

        return new Promise(function (resolve) { setTimeout(resolve, 600); });
      })
      .then(function () {
        _reset();
        var _url = window.location.href.replace(/([?&])ds_prev=\d+/, '');
        _url += (_url.indexOf('?') >= 0 ? '&' : '?') + 'ds_prev=' + _prevMaxRowid;
        window.location.href = _url;
      })
      .catch(function (err) {
        if (err.name === 'AbortError') {
          _reset('Exécution interrompue par l\'utilisateur.');
          return;
        }
        _reset();
        console.error('[DoliStream] AJAX error:', err);
        window.location.reload();
      });
  });
})();
</script>


<?php
llxFooter();
$db->close();




