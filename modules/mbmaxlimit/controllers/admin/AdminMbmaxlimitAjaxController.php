<?php

class AdminMbmaxlimitAjaxController extends ModuleAdminController
{
	public function __construct()
	{
		parent::__construct();
		$this->bootstrap = true;
	}

	public function initContent()
	{
		parent::initContent();
		if (Tools::getValue('ajax')) {
			$this->ajaxProcessSearch();
			exit;
		}
	}

	public function ajaxProcessSearch()
	{
		$type = Tools::getValue('type');
		$term = trim(Tools::getValue('term'));
		$results = [];
		if (!$type || $term === '') {
			die(Tools::jsonEncode($results));
		}
		switch ($type) {
			case 'category':
				$rows = Db::getInstance()->executeS('SELECT c.id_category as id, cl.name as name FROM `'._DB_PREFIX_.'category` c INNER JOIN `'._DB_PREFIX_.'category_lang` cl ON (cl.id_category=c.id_category AND cl.id_lang='.(int)$this->context->language->id.') WHERE cl.name LIKE \'%'.pSQL($term).'%\' ORDER BY cl.name LIMIT 20');
				break;
			case 'brand':
				$rows = Db::getInstance()->executeS('SELECT m.id_manufacturer as id, m.name as name FROM `'._DB_PREFIX_.'manufacturer` m WHERE m.name LIKE \'%'.pSQL($term).'%\' ORDER BY m.name LIMIT 20');
				break;
			case 'country':
				$rows = Db::getInstance()->executeS('SELECT c.id_country as id, cl.name as name FROM `'._DB_PREFIX_.'country` c INNER JOIN `'._DB_PREFIX_.'country_lang` cl ON (cl.id_country=c.id_country AND cl.id_lang='.(int)$this->context->language->id.') WHERE cl.name LIKE \'%'.pSQL($term).'%\' ORDER BY cl.name LIMIT 20');
				break;
			case 'customer_group':
				$rows = Db::getInstance()->executeS('SELECT g.id_group as id, gl.name as name FROM `'._DB_PREFIX_.'group` g INNER JOIN `'._DB_PREFIX_.'group_lang` gl ON (gl.id_group=g.id_group AND gl.id_lang='.(int)$this->context->language->id.') WHERE gl.name LIKE \'%'.pSQL($term).'%\' ORDER BY gl.name LIMIT 20');
				break;
			default:
				$rows = [];
		}
		foreach ($rows as $r) {
			$results[] = ['id' => (int)$r['id'], 'name' => $r['name']];
		}
		die(Tools::jsonEncode($results));
	}
}





