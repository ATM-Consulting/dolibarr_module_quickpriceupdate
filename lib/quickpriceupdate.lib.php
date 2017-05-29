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
	
	if (!class_exists('Categorie')) dol_include_once ('/categories/class/categorie.class.php');
	
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


function _updateTarif(&$db, &$conf, &$langs)
{
	dol_include_once('/core/lib/functions.lib.php');
	$langs->load('quickpriceupdate@quickpriceupdate');
	
	$file = $_FILES['tarif'];
	if (!empty($file['error']))
	{
		// error
		return;
	}
	
	$handler = fopen($file['tmp_name'], 'r');
	
	$line = fgets($handler, '4096'); // skip first line
	
	$TData = array();
	while ($line = fgets($handler, '4096'))
	{
		$tab = str_getcsv($line, ';');
		$TData[] = array(
			'product_ref' => $tab[0]
			,'date_fin_prev_tarif' => $tab[1]
			,'tarif_type' => $tab[2]
			,'fk_country' => (int) $tab[3]
			,'fk_soc' => (int) $tab[4]
			,'fk_categorie' => (int) $tab[5]
			,'date_deb' => $tab[6]
			,'date_fin' => $tab[7]
			,'tva' => (double) $tab[8]
			,'price_ht' => (double) price2num($tab[9])
			,'qty_p1' => (double) $tab[10]
			,'remise_p1' => (double) $tab[11]
			,'qty_p2' => (double) $tab[12]
			,'remise_p2' => (double) $tab[13]
			,'qty_p3' => (double) $tab[14]
			,'remise_p3' => (double) $tab[15]
			,'qty_p4' => (double) $tab[16]
			,'remise_p4' => (double) $tab[17]
			
		);
	}
	
	$currency_code = 'EUR';
	$now = date('Y-m-d H:i:s');
	$nb_update_date_prev_tarif = $nb_insert = $error = 0;
	
	
	$sql = 'SELECT MAX(rowid) as rowid FROM '.MAIN_DB_PREFIX.'tarif_conditionnement';
	$resql = $db->query($sql);
	if ($resql)
	{
		$d = $db->fetch_object($resql);
		$max_rowid = $d->rowid +1;
	}
	
	
	$db->begin();
	
	foreach ($TData as &$data)
	{
		if (!empty($data['date_fin_prev_tarif']))
		{
			$res = _updateDateTarif($db, $data['product_ref'], $data['date_fin_prev_tarif'], $data['fk_country']);
			$nb_update_date_prev_tarif += $res;
		}
		
		$res = _insertTarif($db, $data, $max_rowid);
		if ($res >= 0)
		{
			$nb_insert += $res;
		}
		else
		{
			$error++;
			break;
		}
	}

	if ($error)
	{
		$db->rollback();
		setEventMessage($langs->trans('quickpriceupdate_tarif_error', $db->lastqueryerror), 'errors');
	}
	else
	{
		$db->commit();
		setEventMessage($langs->trans('quickpriceupdate_tarif_success', count($TData), $nb_update_date_prev_tarif, $nb_insert));
	}
	
	header('Location: '. dol_buildpath('/quickpriceupdate/admin/quickpriceupdate_setup.php', 1));
	exit;
}

function _updateDateTarif(&$db, $product_ref, $date_ymdhis, $fk_country)
{
	$sql = 'UPDATE '.MAIN_DB_PREFIX.'tarif_conditionnement SET date_fin = \''.$date_ymdhis.'\' WHERE fk_country = '.((int) $fk_country).' AND fk_product = (SELECT rowid FROM '.MAIN_DB_PREFIX.'product WHERE ref = "'.$db->escape($product_ref).'");';
	$resql = $db->query($sql);
	
	if ($resql) return 1;
	else return 0;
}

function _insertTarif(&$db, &$data, &$max_rowid)
{
	$nb_insert = 0;
	
	if (!empty($data['qty_p1']))
	{
		$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'tarif_conditionnement (rowid, date_cre, date_maj, unite, unite_value, price_base_type, type_price, currency_code,tva_tx, fk_user_author,fk_country, fk_categorie_client, fk_soc, fk_project, date_debut, date_fin,fk_product,quantite,remise_percent,prix) '
		. 'VALUES ('.$max_rowid.', \''.$now.'\',\''.$now.'\',"U",0,"HT","'.$data['tarif_type'].'","'.$currency_code.'", '.$data['tva'].',1,'.$data['fk_country'].','.$data['fk_categorie'].','.$data['fk_soc'].',0,\''.$data['date_deb'].'\',\''.$data['date_fin'].'\',(SELECT rowid FROM '.MAIN_DB_PREFIX.'product WHERE ref = "'.$data['product_ref'].'"), '.$data['qty_p1'].', '.$data['remise_p1'].', '.$data['price_ht'].');';

		$resql = $db->query($sql);
		if ($resql) 
		{
			$nb_insert++;
			$max_rowid++;
		}
		else return -1;
	}

	if (!empty($data['qty_p2']))
	{
		$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'tarif_conditionnement (rowid, date_cre, date_maj, unite, unite_value, price_base_type, type_price, currency_code,tva_tx, fk_user_author,fk_country, fk_categorie_client, fk_soc, fk_project, date_debut, date_fin,fk_product,quantite,remise_percent,prix) '
		. 'VALUES ('.$max_rowid.', \''.$now.'\',\''.$now.'\',"U",0,"HT","'.$data['tarif_type'].'","'.$currency_code.'", '.$data['tva'].',1,'.$data['fk_country'].','.$data['fk_categorie'].','.$data['fk_soc'].',0,\''.$data['date_deb'].'\',\''.$data['date_fin'].'\',(SELECT rowid FROM '.MAIN_DB_PREFIX.'product WHERE ref = "'.$data['product_ref'].'"), '.$data['qty_p2'].', '.$data['remise_p2'].', '.$data['price_ht'].');';

		$resql = $db->query($sql);
		if ($resql)
		{
			$nb_insert++;
			$max_rowid++;
		}
		else return -2;
	}

	if (!empty($data['qty_p3']))
	{
		$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'tarif_conditionnement (rowid, date_cre, date_maj, unite, unite_value, price_base_type, type_price, currency_code,tva_tx, fk_user_author,fk_country, fk_categorie_client, fk_soc, fk_project, date_debut, date_fin,fk_product,quantite,remise_percent,prix) '
		. 'VALUES ('.$max_rowid.', \''.$now.'\',\''.$now.'\',"U",0,"HT","'.$data['tarif_type'].'","'.$currency_code.'", '.$data['tva'].',1,'.$data['fk_country'].','.$data['fk_categorie'].','.$data['fk_soc'].',0,\''.$data['date_deb'].'\',\''.$data['date_fin'].'\',(SELECT rowid FROM '.MAIN_DB_PREFIX.'product WHERE ref = "'.$data['product_ref'].'"), '.$data['qty_p3'].', '.$data['remise_p3'].', '.$data['price_ht'].');';
		
		$resql = $db->query($sql);
		if ($resql)
		{
			$nb_insert++;
			$max_rowid++;
		}
		else return -3;
	}

	if (!empty($data['qty_p4']))
	{
		$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'tarif_conditionnement (rowid, date_cre, date_maj, unite, unite_value, price_base_type, type_price, currency_code,tva_tx, fk_user_author,fk_country, fk_categorie_client, fk_soc, fk_project, date_debut, date_fin,fk_product,quantite,remise_percent,prix) '
		. 'VALUES ('.$max_rowid.', \''.$now.'\',\''.$now.'\',"U",0,"HT","'.$data['tarif_type'].'","'.$currency_code.'", '.$data['tva'].',1,'.$data['fk_country'].','.$data['fk_categorie'].','.$data['fk_soc'].',0,\''.$data['date_deb'].'\',\''.$data['date_fin'].'\',(SELECT rowid FROM '.MAIN_DB_PREFIX.'product WHERE ref = "'.$data['product_ref'].'"), '.$data['qty_p4'].', '.$data['remise_p4'].', '.$data['price_ht'].');';
		
		$resql = $db->query($sql);
		if ($resql)
		{
			$nb_insert++;
			$max_rowid++;
		}
		else return -4;
	}
	
	return $nb_insert;
}


function _updateSupplierPrice(&$db, &$langs, $action)
{
	global $user;
	
	$errors = array();
	
	$db->begin();
	
	include_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.class.php';
	
	$fk_supplier = GETPOST('fk_supplier');
	$supplier = new Fournisseur($db);
	if ($supplier->fetch($fk_supplier) <= 0) $errors[] = $langs->trans('quickpriceupdate_error_no_supplier_selected');
	
	$file = $_FILES['filesupplierprice'];
	if (empty($file) || $file['error'] > 0) $errors[] = $langs->trans('quickpriceupdate_error_file');
	
	$handle = fopen($file['tmp_name'], 'r');
	if (GETPOST('filewithheader') == 1) $header = fgets($handle);
	
	$TData = array();
	while ($line = fgets($handle))
	{
		$line = str_getcsv($line, ';', '"');
		
		$TData[] = array(
			'fk_product' => trim($line[0])
			,'ref' => trim($line[1])
			,'ref_price' => trim($line[4])
			,'old_price' => preg_replace('/[^0-9\.]/', '', price2num($line[5]))
			,'new_price' => preg_replace('/[^0-9\.]/', '', price2num($line[6]))
		);
	}
	
	fclose($handle);
	
	include_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.product.class.php';
	
	$TRes = array();
	$nb_update = $nb_not_found = $nb_error = 0;
	foreach ($TData as &$line)
	{
		$object = new ProductFournisseur($db);
		if ($line['fk_product'] > 0 || $line['ref'])
		{
			$res = $object->fetch($line['fk_product'], $line['ref']);
		
			if ($res < 0) $nb_error++;
			elseif ($res == 0) $nb_not_found++;
			
			$sql = 'SELECT pfp.rowid, pfp.quantity, pfp.fk_availability, pfp.tva_tx, pfp.charges, pfp.remise_percent, pfp.remise, pfp.info_bits, pfp.delivery_time_days';
			if ((float) DOL_VERSION >= 4.0) $sql.= ', pfp.supplier_reputation';
			else ', \'\' as supplier_reputation';
			$sql.= ' FROM '.MAIN_DB_PREFIX.'product_fournisseur_price pfp WHERE fk_soc = '.$supplier->id.' AND ref_fourn = \''.$line['ref_price'].'\' AND unitprice = '.$line['old_price'];
			
			$resql = $db->query($sql);
			if ($resql)
			{
				$num = $db->num_rows($resql);
				if ($num == 1)
				{
					$r = $db->fetch_object($resql);
					$object->product_fourn_price_id = $r->rowid;
					$res = $object->update_buyprice($r->quantity, $line['new_price']*$r->quantity, $user, 'HT', $supplier, $r->fk_availability, $line['ref_price'], $r->tva_tx, $r->charges, $r->remise_percent, $r->remise, $r->info_bits, $r->delivery_time_days, $r->supplier_reputation);
					
					if ($res >= 0) $nb_update++;
					else 
					{
						$nb_error++;
						$TRes['errors'][] = $langs->trans('quickpriceupdate_line_error_on_update', implode(';', $line), $db->lasterror());
					}
				}
				else
				{
					$nb_not_found++;
					$TRes['warnings'][] = $langs->trans('quickpriceupdate_line_notfound', implode(';', $line), $sql);
				}
			}
			else
			{
				$nb_error++;
				$TRes['errors'][] = $langs->trans('quickpriceupdate_line_error_on_select', implode(';', $line), $sql);
			}
		}
		else
		{
			$nb_error++;
			$TRes['errors'][] = $langs->trans('quickpriceupdate_line_error_data', implode(';', $line));
		}
	}
	
	if ($action == 'simulateupdatesupplierprice' || $nb_error > 0) $db->rollback();
	else $db->commit();
	
	if ($nb_error > 0)
	{
		setEventMessages($langs->trans('quickpriceupdate_errors_found', $nb_error), '', 'errors');
	}
	else
	{
		if ($nb_update > 0) setEventMessages($langs->trans('quickpriceupdate_updated_price', $nb_update), '');
		if ($nb_not_found > 0) setEventMessages($langs->trans('quickpriceupdate_not_found_price', $nb_not_found), '');
	}
	
	return $TRes;
}