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
 */

/**
 * \file       ajax/get_products_by_warehouse.php
 * \ingroup    bpverbruik
 * \brief      AJAX endpoint to get products available in a specific warehouse
 *             Returns JSON array of products with stock quantities
 */

// Load Dolibarr environment
if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}

$res = 0;
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

// Get parameters
$warehouse_id = GETPOSTINT('warehouse_id');

// Check security - user must be logged in
if (empty($user) || empty($user->id)) {
	http_response_code(403);
	echo json_encode(array('error' => 'Access denied - not logged in'));
	exit;
}

// Check module permission
if (!isModEnabled('bpverbruik')) {
	http_response_code(403);
	echo json_encode(array('error' => 'Module not enabled'));
	exit;
}

if (empty($warehouse_id)) {
	http_response_code(400);
	echo json_encode(array('error' => 'Missing warehouse_id'));
	exit;
}

// Query products with stock in this warehouse
// Use INNER JOIN to only get products that have a stock record in this warehouse
$sql = "SELECT p.rowid, p.ref, p.label, ps.reel as stock";
$sql .= " FROM ".MAIN_DB_PREFIX."product as p";
$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_stock as ps ON ps.fk_product = p.rowid";
$sql .= " WHERE ps.fk_entrepot = ".(int)$warehouse_id;
$sql .= " AND p.entity IN (".getEntity('product').")";
$sql .= " AND p.tosell = 1";  // Only products marked for sale
$sql .= " AND p.fk_product_type = 0";  // Only physical products (not services)
$sql .= " ORDER BY p.label ASC";

$resql = $db->query($sql);

$products = array();

if ($resql) {
	$num = $db->num_rows($resql);
	$i = 0;

	while ($i < $num) {
		$obj = $db->fetch_object($resql);

		$products[] = array(
			'id' => $obj->rowid,
			'ref' => $obj->ref,
			'label' => $obj->ref.' - '.$obj->label,
			'stock' => ($obj->stock !== null) ? (float)$obj->stock : 0
		);

		$i++;
	}

	$db->free($resql);
}

// Return JSON
header('Content-Type: application/json');
echo json_encode($products);
