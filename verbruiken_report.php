<?php
/* Copyright (C) 2024 Wildopvang de Bonte Piet <info@debontepiet.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file       verbruiken_report.php
 * \ingroup    bpverbruik
 * \brief      Consumption Report - Rapportage voor verbruikregistratie
 *             This page is only accessible to specific users
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME']; $tmp2 = realpath(__FILE__); $i = strlen($tmp) - 1; $j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
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

require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
dol_include_once('/bpverbruik/class/verbruiken.class.php');

// Load translations
$langs->loadLangs(array("bpverbruik@bpverbruik", "other", "products"));

// Get parameters
$action = GETPOST('action', 'aZ09');

// Date filters - default to current month
$search_date_start_day = GETPOSTINT('search_date_start_day');
$search_date_start_month = GETPOSTINT('search_date_start_month');
$search_date_start_year = GETPOSTINT('search_date_start_year');
$search_date_end_day = GETPOSTINT('search_date_end_day');
$search_date_end_month = GETPOSTINT('search_date_end_month');
$search_date_end_year = GETPOSTINT('search_date_end_year');

if (empty($search_date_start_day) || empty($search_date_start_month) || empty($search_date_start_year)) {
	$search_date_start = dol_mktime(0, 0, 0, date('m'), 1, date('Y'));
} else {
	$search_date_start = dol_mktime(0, 0, 0, $search_date_start_month, $search_date_start_day, $search_date_start_year);
}

if (empty($search_date_end_day) || empty($search_date_end_month) || empty($search_date_end_year)) {
	$search_date_end = dol_mktime(23, 59, 59, date('m'), date('t'), date('Y'));
} else {
	$search_date_end = dol_mktime(23, 59, 59, $search_date_end_month, $search_date_end_day, $search_date_end_year);
}

// Initialize technical objects
$form = new Form($db);
$object = new Verbruiken($db);
$hookmanager->initHooks(array('verbruikenreport'));

// SECURITY: Restrict access to specific users only
$allowed_users = getDolGlobalString('BPVERBRUIK_REPORT_ALLOWED_USERS');
if (empty($allowed_users)) {
	// Default to admin-only if not configured
	if (!$user->admin) {
		accessforbidden($langs->trans('AccessToReportsRestrictedToSpecificUsersOnly'));
	}
} else {
	// Check if current user is in allowed list
	$allowed_user_ids = array_map('trim', explode(',', $allowed_users));
	if (!in_array($user->id, $allowed_user_ids) && !$user->admin) {
		accessforbidden($langs->trans('YouAreNotAuthorizedToViewConsumptionReports'));
	}
}

// Build SQL query with date filters
$sql = "SELECT v.rowid, v.ref, v.qty, v.date_creation, ";
$sql .= "p.ref as product_ref, p.label as product_name, p.pmp, ";
$sql .= "e.ref as warehouse_ref, e.label as warehouse_name, ";
$sql .= "u.firstname, u.lastname, ";
$sql .= "(v.qty * p.pmp) as total_value ";
$sql .= "FROM ".MAIN_DB_PREFIX."bpverbruik_verbruiken as v ";
$sql .= "LEFT JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = v.fk_product ";
$sql .= "LEFT JOIN ".MAIN_DB_PREFIX."entrepot as e ON e.rowid = v.fk_warehouse ";
$sql .= "LEFT JOIN ".MAIN_DB_PREFIX."user as u ON u.rowid = v.fk_user_creat ";
$sql .= "WHERE v.entity IN (".getEntity('bpverbruik').")";
$sql .= " AND v.date_creation >= '".$db->idate($search_date_start)."'";
$sql .= " AND v.date_creation <= '".$db->idate($search_date_end)."'";
$sql .= " ORDER BY v.date_creation DESC";

// Execute query
$resql = $db->query($sql);

// Handle export actions
if ($action == 'export_pdf') {
	// Re-execute query to get data
	$resql_export = $db->query($sql);

	require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
	require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';

	$pdf = pdf_getInstance();
	$pdf->SetTitle($langs->trans('ConsumptionReport'));
	$pdf->AddPage();

	// Title
	$pdf->SetFont('', 'B', 16);
	$pdf->Cell(0, 10, $langs->trans('ConsumptionReport'), 0, 1, 'C');

	// Date range
	$pdf->SetFont('', '', 10);
	$pdf->Cell(0, 5, $langs->trans('Period').': '.dol_print_date($search_date_start, 'day').' - '.dol_print_date($search_date_end, 'day'), 0, 1);
	$pdf->Ln(5);

	// Table headers
	$pdf->SetFont('', 'B', 9);
	$pdf->Cell(30, 7, $langs->trans('Ref'), 1, 0, 'L');
	$pdf->Cell(50, 7, $langs->trans('Product'), 1, 0, 'L');
	$pdf->Cell(20, 7, $langs->trans('Qty'), 1, 0, 'R');
	$pdf->Cell(40, 7, $langs->trans('Warehouse'), 1, 0, 'L');
	$pdf->Cell(30, 7, $langs->trans('Date'), 1, 0, 'L');
	$pdf->Cell(20, 7, $langs->trans('TotalValue'), 1, 1, 'R');

	// Data rows
	$pdf->SetFont('', '', 8);
	$total_qty = 0;
	$total_value = 0;

	if ($resql_export) {
		while ($obj = $db->fetch_object($resql_export)) {
			$pdf->Cell(30, 6, $obj->ref, 1, 0, 'L');
			$pdf->Cell(50, 6, dol_trunc($obj->product_name, 25), 1, 0, 'L');
			$pdf->Cell(20, 6, $obj->qty, 1, 0, 'R');
			$pdf->Cell(40, 6, dol_trunc($obj->warehouse_name, 20), 1, 0, 'L');
			$pdf->Cell(30, 6, dol_print_date($db->jdate($obj->date_creation), 'day'), 1, 0, 'L');
			$pdf->Cell(20, 6, price($obj->total_value, 0, '', 1, 2), 1, 1, 'R');

			$total_qty += $obj->qty;
			$total_value += $obj->total_value;
		}
		$db->free($resql_export);
	}

	// Totals
	$pdf->SetFont('', 'B', 9);
	$pdf->Cell(80, 7, $langs->trans('Total'), 1, 0, 'L');
	$pdf->Cell(20, 7, $total_qty, 1, 0, 'R');
	$pdf->Cell(70, 7, '', 1, 0, 'L');
	$pdf->Cell(20, 7, price($total_value, 0, '', 1, 2), 1, 1, 'R');

	// Output
	$pdf->Output('consumption_report_'.dol_print_date($search_date_start, '%Y%m%d').'_'.dol_print_date($search_date_end, '%Y%m%d').'.pdf', 'D');
	exit;
}

if ($action == 'export_excel') {
	// Re-execute query to get data
	$resql_export = $db->query($sql);

	require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
	require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

	// Prepare data
	$data = array();
	$total_qty = 0;
	$total_value = 0;

	if ($resql_export) {
		while ($obj = $db->fetch_object($resql_export)) {
			$data[] = array(
				'ref' => $obj->ref,
				'product' => $obj->product_ref.' - '.$obj->product_name,
				'qty' => $obj->qty,
				'warehouse' => $obj->warehouse_ref.' - '.$obj->warehouse_name,
				'date' => dol_print_date($db->jdate($obj->date_creation), 'dayhour'),
				'user' => $obj->firstname.' '.$obj->lastname,
				'value' => $obj->total_value
			);
			$total_qty += $obj->qty;
			$total_value += $obj->total_value;
		}
		$db->free($resql_export);
	}

	// Generate CSV (simple Excel-compatible format)
	header('Content-Type: text/csv; charset=utf-8');
	header('Content-Disposition: attachment; filename=consumption_report_'.dol_print_date($search_date_start, '%Y%m%d').'_'.dol_print_date($search_date_end, '%Y%m%d').'.csv');

	$output = fopen('php://output', 'w');

	// Header row
	fputcsv($output, array(
		$langs->trans('Ref'),
		$langs->trans('Product'),
		$langs->trans('Qty'),
		$langs->trans('Warehouse'),
		$langs->trans('Date'),
		$langs->trans('User'),
		$langs->trans('TotalValue')
	));

	// Data rows
	foreach ($data as $row) {
		fputcsv($output, $row);
	}

	// Total row
	fputcsv($output, array(
		$langs->trans('Total'),
		'',
		$total_qty,
		'',
		'',
		'',
		$total_value
	));

	fclose($output);
	exit;
}

/*
 * View
 */

$title = $langs->trans('ConsumptionReport');
$help_url = '';

llxHeader('', $title, $help_url, '', 0, 0, '', '', '', 'mod-bpverbruik page-verbruiken_report');

print load_fiche_titre($title, '', 'bpverbruik@bpverbruik');

// Display form with date filters
print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="search">';

print '<div class="fichecenter">';
print '<div class="fichehalfleft">';
print '<table class="border centpercent">';

// Date range filter - Start date
print '<tr><td class="titlefield fieldrequired">'.$langs->trans("DateStart").'</td><td>';
print $form->selectDate($search_date_start, 'search_date_start_', 0, 0, 0, '', 1, 1);
print '</td></tr>';

// Date range filter - End date
print '<tr><td class="fieldrequired">'.$langs->trans("DateEnd").'</td><td>';
print $form->selectDate($search_date_end, 'search_date_end_', 0, 0, 0, '', 1, 1);
print '</td></tr>';

print '</table>';
print '</div>';
print '</div>';

print '<div class="clearboth"></div>';

// Search button
print '<div class="center" style="margin-top: 10px;">';
print '<input type="submit" class="button" value="'.$langs->trans("Search").'">';
print '</div>';

print '</form>';

print '<br>';

// Display results table
print '<div class="div-table-responsive">';
print '<table class="tagtable nobottomiftotal liste">'."\n";

// Table headers
print '<tr class="liste_titre">';
print '<th>'.$langs->trans('Ref').'</th>';
print '<th>'.$langs->trans('Product').'</th>';
print '<th class="right">'.$langs->trans('Qty').'</th>';
print '<th>'.$langs->trans('Warehouse').'</th>';
print '<th>'.$langs->trans('Date').'</th>';
print '<th>'.$langs->trans('User').'</th>';
print '<th class="right">'.$langs->trans('TotalValue').'</th>';
print '</tr>'."\n";

// Display data rows
$total_qty = 0;
$total_value = 0;

if ($resql) {
	$num = $db->num_rows($resql);
	$i = 0;

	if ($num > 0) {
		while ($i < $num) {
			$obj = $db->fetch_object($resql);

			print '<tr class="oddeven">';
			print '<td>'.$obj->ref.'</td>';
			print '<td>'.$obj->product_ref.' - '.$obj->product_name.'</td>';
			print '<td class="right">'.$obj->qty.'</td>';
			print '<td>'.$obj->warehouse_ref.($obj->warehouse_name ? ' - '.$obj->warehouse_name : '').'</td>';
			print '<td>'.dol_print_date($db->jdate($obj->date_creation), 'dayhour').'</td>';
			print '<td>'.$obj->firstname.' '.$obj->lastname.'</td>';
			print '<td class="right">'.price($obj->total_value, 0, $langs, 1, -1, -1, $conf->currency).'</td>';
			print '</tr>'."\n";

			$total_qty += $obj->qty;
			$total_value += $obj->total_value;

			$i++;
		}

		// Display totals
		print '<tr class="liste_total">';
		print '<td colspan="2"><strong>'.$langs->trans('Total').'</strong></td>';
		print '<td class="right"><strong>'.$total_qty.'</strong></td>';
		print '<td colspan="3"></td>';
		print '<td class="right"><strong>'.price($total_value, 0, $langs, 1, -1, -1, $conf->currency).'</strong></td>';
		print '</tr>'."\n";
	} else {
		print '<tr><td colspan="7" class="opacitymedium">'.$langs->trans("NoRecordFound").'</td></tr>';
	}

	$db->free($resql);
} else {
	print '<tr><td colspan="7" class="error">'.$db->lasterror().'</td></tr>';
}

print '</table>'."\n";
print '</div>';

// Export buttons
if ($num > 0) {
	print '<div class="tabsAction">';
	print '<a class="butAction" href="'.$_SERVER["PHP_SELF"].'?action=export_pdf&search_date_start_day='.dol_print_date($search_date_start, '%d').'&search_date_start_month='.dol_print_date($search_date_start, '%m').'&search_date_start_year='.dol_print_date($search_date_start, '%Y').'&search_date_end_day='.dol_print_date($search_date_end, '%d').'&search_date_end_month='.dol_print_date($search_date_end, '%m').'&search_date_end_year='.dol_print_date($search_date_end, '%Y').'&token='.newToken().'">'.$langs->trans('ExportToPDF').'</a>';
	print '<a class="butAction" href="'.$_SERVER["PHP_SELF"].'?action=export_excel&search_date_start_day='.dol_print_date($search_date_start, '%d').'&search_date_start_month='.dol_print_date($search_date_start, '%m').'&search_date_start_year='.dol_print_date($search_date_start, '%Y').'&search_date_end_day='.dol_print_date($search_date_end, '%d').'&search_date_end_month='.dol_print_date($search_date_end, '%m').'&search_date_end_year='.dol_print_date($search_date_end, '%Y').'&token='.newToken().'">'.$langs->trans('ExportToExcel').'</a>';
	print '</div>';
}

// End of page
llxFooter();
$db->close();
