<?php
/* <one line to give the program's name and a brief idea of what it does.>
 * Copyright (C) 2015 ATM Consulting <support@atm-consulting.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 *	\file		lib/quickpriceupdate.lib.php
 *	\ingroup	quickpriceupdate
 *	\brief		This file is an example module library
 *				Put some comments here
 */

function quickpriceupdateAdminPrepareHead()
{
    global $langs, $conf;

    $langs->load("quickpriceupdate@quickpriceupdate");

    $h = 0;
    $head = array();

    $head[$h][0] = dol_buildpath("/quickpriceupdate/admin/quickpriceupdate_setup.php", 1);
    $head[$h][1] = $langs->trans("Parameters");
    $head[$h][2] = 'settings';
    $h++;
    $head[$h][0] = dol_buildpath("/quickpriceupdate/admin/quickpriceupdate_about.php", 1);
    $head[$h][1] = $langs->trans("About");
    $head[$h][2] = 'about';
    $h++;

    // Show more tabs from modules
    // Entries must be declared in modules descriptor with line
    //$this->tabs = array(
    //	'entity:+tabname:Title:@quickpriceupdate:/quickpriceupdate/mypage.php?id=__ID__'
    //); // to add new tab
    //$this->tabs = array(
    //	'entity:-tabname:Title:@quickpriceupdate:/quickpriceupdate/mypage.php?id=__ID__'
    //); // to remove a tab
    complete_head_from_modules($conf, $langs, $object, $head, $h, 'quickpriceupdate');

    return $head;
}

function select_all_categories(&$form)
{
	global $langs;
	$Tab = array(-1 => $langs->transnoentitiesnoconv('quickpriceupdate_selectCategory'), 0 => $langs->transnoentitiesnoconv('quickpriceupdate_selectAll'));
	$Tab += $form->select_all_categories(0,'', 'fk_category', 64, 0, 1);
	
	return $form->selectarray('fk_category', $Tab);
}


function _priceUpdateDolibarr(&$db, &$conf, &$langs)
{
	dol_include_once('/product/class/product.class.php');
	$error = 0;
	
	$fk_category = GETPOST('fk_category', 'int');
	$tms = dol_mktime(GETPOST('tmshour', 'int'), GETPOST('tmsmin', 'int'), 0, GETPOST('tmsmonth', 'int'), GETPOST('tmsday', 'int'), GETPOST('tmsyear', 'int'));
	$percentage = (float) GETPOST('percentage');
	
	if ($fk_category <= -1) 
	{
		setEventMessages($langs->trans('ErrorFieldRequired', $langs->transnoentities('quickpriceupdate_category_required')), null, 'errors');
		$error++;
	}
	
	if ($tms == '') 
	{
		setEventMessages($langs->trans('ErrorFieldRequired', $langs->transnoentities('quickpriceupdate_date_required')), null, 'errors');
		$error++;
	}
	
	if (!$error && $percentage != 0)
	{
		$tms = date('Y-m-d H:i:00', $tms);
		_priceUpdateDolibarrAction($db, $conf, $langs, $fk_category, $tms, $percentage);
	}
}

function _priceUpdateDolibarrAction(&$db, &$conf, &$langs, $fk_category, $tms, $percentage)
{
	global $user;
	
	$sql = 'SELECT pp.rowid, pp.fk_product, pp.price_level FROM '.MAIN_DB_PREFIX.'product_price pp';
	if ($fk_category > 0) $sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'categorie_product cp ON (cp.fk_product = pp.fk_product AND cp.fk_categorie = '.$fk_category.')';
	$sql .= ' WHERE pp.tms >= ALL (SELECT pp2.tms FROM '.MAIN_DB_PREFIX.'product_price pp2 WHERE pp2.fk_product = pp.fk_product AND pp2.price_level = pp.price_level) AND pp.tms < "'.$tms.'"';
	
	$resql = $db->query($sql);
	if ($resql)
	{
		$i=0;
		while ($row = $db->fetch_object($resql))
		{
			$i += _updatePrice($db, $conf, $row, $user, $percentage);
		}
		
		setEventMessages($langs->trans('quickpriceupdate_nb_priceupdate', $i), null);
	}
	else 
	{
		setEventMessages($langs->trans('quickpriceupdate_sql_error', $sql, $db->lasterror), null, 'errors');
	}
}

/**
 * return 1 if OK, 0 if KO
 */
function _updatePrice(&$db, &$conf, &$row, &$user, $percentage)
{
	$coef = 1 + ($percentage / 100);
	
	$product = new Product($db);
	if ($product->fetch($row->fk_product) > 0)
	{
		if ($product->type == 0 || !empty($conf->global->QUICKPRICEUPDATE_ALLOW_SERVICE))
		{
			if (!empty($conf->global->PRODUCT_PRICE_UNIQ)) return _updatePriceByLevel($user, $product, $product->price, $product->price_min, $coef, $row->price_level);
			elseif (!empty($conf->global->PRODUIT_MULTIPRICES)) return _updatePriceByLevel($user, $product, $product->multiprices[$row->price_level], $product->multiprices_min[$row->price_level], $coef, $row->price_level);
			
		}
	}
	
	return 0;
}

function _updatePriceByLevel(&$user, &$product, $price, $price_min, $coef, $level)
{
	$r = $product->updatePrice($price * $coef, 'HT', $user, '', $price_min * $coef, $level);
	if ($r <= 0) return 0;
	else return 1;
}
