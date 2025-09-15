<?php
if (!defined('_PS_VERSION_')) {
	exit;
}

class Mbmaxlimit extends Module
{
	public function __construct()
	{
		$this->name = 'mbmaxlimit';
		$this->tab = 'checkout';
		$this->version = '1.0.0';
		$this->author = 'Mohammad Babaei';
		$this->need_instance = 0;
		$this->ps_versions_compliancy = ['min' => '1.7.8.0', 'max' => _PS_VERSION_];
		$this->bootstrap = true;

		parent::__construct();

		$this->displayName = $this->l('Maximum per-product cart limit');
		$this->description = $this->l('Limit maximum quantity of specific products per cart with admin product field and listing.');
	}

	public function install()
	{
		return parent::install()
			&& $this->installDb()
			&& $this->installTabs()
			&& $this->registerHook('actionProductFormBuilderModifier')
			&& $this->registerHook('actionProductFormDataProvider')
			&& $this->registerHook('actionAfterUpdateProductFormHandler')
			&& $this->registerHook('actionAfterCreateProductFormHandler')
			&& $this->registerHook('actionCartUpdateQuantityBefore');
	}

	public function uninstall()
	{
		return $this->uninstallTabs() && $this->uninstallDb() && parent::uninstall();
	}
	protected function installTabs()
	{
		$tab = new Tab();
		$tab->active = 1;
		$tab->class_name = 'AdminMbmaxlimitAjax';
		$tab->name = [];
		foreach (Language::getLanguages(false) as $lang) {
			$tab->name[(int)$lang['id_lang']] = 'MB MaxLimit Ajax';
		}
		$tab->id_parent = (int) Tab::getIdFromClassName('AdminParentModulesSf');
		$tab->module = $this->name;
		return (bool) $tab->add();
	}

	protected function uninstallTabs()
	{
		$idTab = (int) Tab::getIdFromClassName('AdminMbmaxlimitAjax');
		if ($idTab) {
			$tab = new Tab($idTab);
			return (bool) $tab->delete();
		}
		return true;
	}

	protected function installDb()
	{
		$sql = [];
		$sql[] = 'CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'mbmaxlimit_product` (
			`id_product` INT UNSIGNED NOT NULL,
			`max_qty` INT UNSIGNED NOT NULL DEFAULT 0,
			`active` TINYINT(1) NOT NULL DEFAULT 1,
			PRIMARY KEY (`id_product`)
		) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8;';
		$sql[] = 'CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'mbmaxlimit_rule` (
			`id_rule` INT UNSIGNED NOT NULL AUTO_INCREMENT,
			`scope` VARCHAR(32) NOT NULL,
			`id_target` INT UNSIGNED NOT NULL,
			`max_qty` INT UNSIGNED NOT NULL DEFAULT 0,
			`active` TINYINT(1) NOT NULL DEFAULT 1,
			`id_shop` INT UNSIGNED NOT NULL DEFAULT 0,
			`date_from` DATETIME NULL,
			`date_to` DATETIME NULL,
			`dow_mask` TINYINT UNSIGNED NOT NULL DEFAULT 127,
			`lifetime_max_qty` INT UNSIGNED NOT NULL DEFAULT 0,
			`message_en` VARCHAR(255) NULL,
			`message_fa` VARCHAR(255) NULL,
			PRIMARY KEY (`id_rule`),
			KEY `scope_target` (`scope`, `id_target`),
			KEY `id_shop` (`id_shop`)
		) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8;';
		$sql[] = 'CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'mbmaxlimit_rule_excl` (
			`id_rule` INT UNSIGNED NOT NULL,
			`scope` VARCHAR(32) NOT NULL,
			`id_target` INT UNSIGNED NOT NULL,
			PRIMARY KEY (`id_rule`, `scope`, `id_target`)
		) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8;';

		foreach ($sql as $query) {
			if (!Db::getInstance()->execute($query)) {
				return false;
			}
		}

		return true;
	}

	protected function uninstallDb()
	{
		$ok = Db::getInstance()->execute('DROP TABLE IF EXISTS `'._DB_PREFIX_.'mbmaxlimit_rule_excl`');
		$ok = $ok && Db::getInstance()->execute('DROP TABLE IF EXISTS `'._DB_PREFIX_.'mbmaxlimit_rule`');
		$ok = $ok && Db::getInstance()->execute('DROP TABLE IF EXISTS `'._DB_PREFIX_.'mbmaxlimit_product`');
		return $ok;
	}

	public function getContent()
	{
		$output = '';
		if (Tools::isSubmit('submit_mbmaxlimit_clean')) {
			if (!$this->isValidAdminToken() || !$this->employeeCan('delete')) {
				return $this->displayError($this->l('Invalid token or insufficient permissions.'));
			}
			Db::getInstance()->delete('mbmaxlimit_product', '`max_qty` = 0');
			$output .= $this->displayConfirmation($this->l('Cleaned entries with zero max quantity.'));
		}

		$output .= $this->renderAuthorBlock();
		$output .= $this->renderList();
		$output .= $this->handleAndRenderRules();
		$output .= $this->renderActions();
		return $output;
	}

	protected function renderAuthorBlock()
	{
		$tpl = $this->context->smarty->createTemplate(_PS_MODULE_DIR_.$this->name.'/views/templates/admin/author.tpl');
		$tpl->assign([
			'module_display_name' => $this->displayName,
			'author_name' => 'Mohammad Babaei',
			'author_site' => 'https://adschi.com',
		]);
		return $tpl->fetch();
	}

	protected function renderActions()
	{
		$helper = new HelperForm();
		$helper->module = $this;
		$helper->identifier = $this->identifier;
		$helper->token = Tools::getAdminTokenLite('AdminModules');
		$helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;
		$helper->submit_action = 'submit_mbmaxlimit_clean';
		$helper->show_toolbar = false;
		$helper->fields_value = [];

		$fieldsForm = [
			'form' => [
				'legend' => [
					'title' => $this->l('Maintenance'),
				],
				'input' => [],
				'submit' => [
					'title' => $this->l('Remove zero-max entries'),
					'class' => 'btn btn-danger',
				],
			],
		];

		return $helper->generateForm([$fieldsForm]);
	}

	protected function renderList()
	{
		$products = $this->getLimitedProducts();
		$fieldsList = [
			'id_product' => [
				'title' => $this->l('ID'),
				'type' => 'text',
			],
			'name' => [
				'title' => $this->l('Product'),
			],
			'reference' => [
				'title' => $this->l('Reference'),
			],
			'max_qty' => [
				'title' => $this->l('Max per cart'),
			],
			'active' => [
				'title' => $this->l('Active'),
				'type' => 'bool',
			],
		];

		$helper = new HelperList();
		$helper->shopLinkType = '';
		$helper->simple_header = true;
		$helper->listTotal = count($products);
		$helper->identifier = 'id_product';
		$helper->actions = ['edit'];
		$helper->show_toolbar = false;
		$helper->module = $this;
		$helper->title = $this->l('Products with maximum quantity limit');
		$helper->table = 'mbmaxlimit_product';
		$helper->token = Tools::getAdminTokenLite('AdminProducts');
		$helper->currentIndex = $this->context->link->getAdminLink('AdminProducts');

		return $helper->generateList($products, $fieldsList);
	}

	protected function getLimitedProducts()
	{
		$sql = 'SELECT p.id_product, pl.name, p.reference, ml.max_qty, ml.active
			FROM `'._DB_PREFIX_.'mbmaxlimit_product` ml
			INNER JOIN `'._DB_PREFIX_.'product` p ON (p.id_product = ml.id_product)
			LEFT JOIN `'._DB_PREFIX_.'product_lang` pl ON (pl.id_product = p.id_product AND pl.id_lang='.(int)$this->context->language->id.' AND pl.id_shop='.(int)$this->context->shop->id.')
			ORDER BY p.id_product DESC';
		return Db::getInstance()->executeS($sql) ?: [];
	}

	protected function handleAndRenderRules()
	{
		// Handle add/delete/toggle actions
		if (Tools::isSubmit('submit_mbmaxlimit_addrule')) {
			if (!$this->isValidAdminToken() || !$this->employeeCan('add')) {
				return $this->displayError($this->l('Invalid token or insufficient permissions.'));
			}
			$scope = Tools::getValue('mb_scope');
			$allowedScopes = ['category','brand','country','customer_group'];
			if (!in_array($scope, $allowedScopes, true)) {
				return $this->displayError($this->l('Invalid scope.'));
			}
			$idTarget = (int) Tools::getValue('mb_id_target');
			$max = (int) Tools::getValue('mb_max_qty');
			$active = Tools::getValue('mb_active') ? 1 : 0;
			$idShop = (int) Tools::getValue('mb_id_shop');
			$dateFrom = Tools::getValue('mb_date_from');
			$dateTo = Tools::getValue('mb_date_to');
			$dow = Tools::getValue('mb_dow'); // array of 0..6
			$dowMask = 0;
			if (is_array($dow)) {
				foreach ($dow as $d) { $d = (int)$d; if ($d >= 0 && $d <= 6) { $dowMask |= (1 << $d); } }
			}
			$lifetimeMax = (int) Tools::getValue('mb_lifetime_max');
			$msgEn = Tools::getValue('mb_message_en');
			$msgFa = Tools::getValue('mb_message_fa');
			if ($idTarget <= 0 || $max < 0) {
				return $this->displayError($this->l('Invalid target or max quantity.'));
			}
			Db::getInstance()->insert('mbmaxlimit_rule', [
				'scope' => pSQL($scope),
				'id_target' => (int)$idTarget,
				'max_qty' => (int)$max,
				'active' => (int)$active,
				'id_shop' => (int)$idShop,
				'date_from' => $dateFrom ? pSQL($dateFrom) : null,
				'date_to' => $dateTo ? pSQL($dateTo) : null,
				'dow_mask' => (int)$dowMask,
				'lifetime_max_qty' => (int)$lifetimeMax,
				'message_en' => $msgEn ? pSQL($msgEn) : null,
				'message_fa' => $msgFa ? pSQL($msgFa) : null,
			]);
			$idRule = (int) Db::getInstance()->Insert_ID();
			// exclusions CSV inputs
			$exProducts = Tools::getValue('mb_exclude_products');
			$exCategories = Tools::getValue('mb_exclude_categories');
			$this->saveExclusionsCsv($idRule, 'product', $exProducts);
			$this->saveExclusionsCsv($idRule, 'category', $exCategories);
		}
		if (Tools::isSubmit('deletembmaxlimit_rule')) {
			if (!$this->isValidAdminToken() || !$this->employeeCan('delete')) {
				return $this->displayError($this->l('Invalid token or insufficient permissions.'));
			}
			$idRule = (int) Tools::getValue('id_rule');
			Db::getInstance()->delete('mbmaxlimit_rule', 'id_rule='.(int)$idRule);
			Db::getInstance()->delete('mbmaxlimit_rule_excl', 'id_rule='.(int)$idRule);
		}
		if (Tools::isSubmit('togglemb_rule')) {
			if (!$this->isValidAdminToken() || !$this->employeeCan('edit')) {
				return $this->displayError($this->l('Invalid token or insufficient permissions.'));
			}
			$idRule = (int) Tools::getValue('id_rule');
			$current = (int) Db::getInstance()->getValue('SELECT `active` FROM `'._DB_PREFIX_.'mbmaxlimit_rule` WHERE id_rule='.(int)$idRule);
			Db::getInstance()->update('mbmaxlimit_rule', ['active' => (int)(!$current)], 'id_rule='.(int)$idRule);
		}

		// Render form
		$helper = new HelperForm();
		$helper->module = $this;
		$helper->identifier = $this->identifier;
		$helper->token = Tools::getAdminTokenLite('AdminModules');
		$helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;
		$helper->submit_action = 'submit_mbmaxlimit_addrule';
		$helper->show_toolbar = false;
		$helper->fields_value = [];

		$shops = Shop::getShops(false);
		$shopOptions = [['id' => 0, 'name' => $this->l('All shops')]];
		foreach ($shops as $s) { $shopOptions[] = ['id' => (int)$s['id_shop'], 'name' => $s['name']]; }

		$fieldsForm = [
			'form' => [
				'legend' => [
					'title' => $this->l('Add advanced rule'),
				],
				'input' => [
					[
						'type' => 'select',
						'label' => $this->l('Scope'),
						'name' => 'mb_scope',
						'options' => [
							'query' => [
								['id' => 'category', 'name' => $this->l('Category')],
								['id' => 'brand', 'name' => $this->l('Brand')],
								['id' => 'country', 'name' => $this->l('Country')],
								['id' => 'customer_group', 'name' => $this->l('Customer group')],
							],
							'id' => 'id',
							'name' => 'name',
						],
					],
					[
						'type' => 'text',
						'label' => $this->l('Target ID'),
						'name' => 'mb_id_target',
						'required' => true,
						'desc' => $this->l('Category ID, Brand(ID manufacturer), Country ID, or Customer Group ID'),
					],
					[
						'type' => 'select',
						'label' => $this->l('Shop'),
						'name' => 'mb_id_shop',
						'options' => [ 'query' => $shopOptions, 'id' => 'id', 'name' => 'name' ],
					],
					[
						'type' => 'text',
						'label' => $this->l('Max per cart'),
						'name' => 'mb_max_qty',
						'required' => true,
					],
					[
						'type' => 'text',
						'label' => $this->l('Lifetime max per customer'),
						'name' => 'mb_lifetime_max',
						'desc' => $this->l('0 = no lifetime limit'),
					],
					[
						'type' => 'date',
						'label' => $this->l('Active from'),
						'name' => 'mb_date_from',
					],
					[
						'type' => 'date',
						'label' => $this->l('Active to'),
						'name' => 'mb_date_to',
					],
					[
						'type' => 'checkbox',
						'label' => $this->l('Active days of week'),
						'name' => 'mb_dow',
						'values' => [
							'query' => [
								['id' => 0, 'name' => $this->l('Sun')],
								['id' => 1, 'name' => $this->l('Mon')],
								['id' => 2, 'name' => $this->l('Tue')],
								['id' => 3, 'name' => $this->l('Wed')],
								['id' => 4, 'name' => $this->l('Thu')],
								['id' => 5, 'name' => $this->l('Fri')],
								['id' => 6, 'name' => $this->l('Sat')],
							],
							'id' => 'id',
							'name' => 'name',
						],
					],
					[
						'type' => 'textarea',
						'label' => $this->l('Exclude product IDs (CSV)'),
						'name' => 'mb_exclude_products',
						'autoload_rte' => false,
					],
					[
						'type' => 'textarea',
						'label' => $this->l('Exclude category IDs (CSV)'),
						'name' => 'mb_exclude_categories',
						'autoload_rte' => false,
					],
					[
						'type' => 'text',
						'label' => $this->l('Custom message (English)'),
						'name' => 'mb_message_en',
					],
					[
						'type' => 'text',
						'label' => $this->l('Custom message (Persian)'),
						'name' => 'mb_message_fa',
					],
					[
						'type' => 'switch',
						'label' => $this->l('Active'),
						'name' => 'mb_active',
						'is_bool' => true,
						'values' => [
							['id' => 'active_on', 'value' => 1, 'label' => $this->l('Enabled')],
							['id' => 'active_off', 'value' => 0, 'label' => $this->l('Disabled')],
						],
					],
				],
				'submit' => [
					'title' => $this->l('Add rule'),
				],
			],
		];

		$list = $this->getRulesList();
		$hl = new HelperList();
		$hl->shopLinkType = '';
		$hl->simple_header = true;
		$hl->listTotal = count($list);
		$hl->identifier = 'id_rule';
		$hl->actions = ['delete'];
		$hl->show_toolbar = false;
		$hl->module = $this;
		$hl->title = $this->l('Advanced rules');
		$hl->table = 'mbmaxlimit_rule';
		$hl->token = Tools::getAdminTokenLite('AdminModules');
		$hl->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;
		$fieldsList = [
			'id_rule' => ['title' => $this->l('ID')],
			'scope' => ['title' => $this->l('Scope')],
			'id_target' => ['title' => $this->l('Target ID')],
			'max_qty' => ['title' => $this->l('Max per cart')],
			'lifetime_max_qty' => ['title' => $this->l('Lifetime max')],
			'id_shop' => ['title' => $this->l('Shop')],
			'date_from' => ['title' => $this->l('From')],
			'date_to' => ['title' => $this->l('To')],
			'active' => ['title' => $this->l('Active'), 'type' => 'bool'],
		];

		Media::addJsDef([
			'mbmaxlimit_ajax_url' => $this->context->link->getAdminLink('AdminMbmaxlimitAjax')
		]);
		$this->context->controller->addJS($this->_path.'views/js/admin.js');
		return $helper->generateForm([$fieldsForm]) . $hl->generateList($list, $fieldsList);
	}

	protected function isValidAdminToken()
	{
		$token = Tools::getAdminTokenLite('AdminModules');
		return Tools::getIsset('token') ? Tools::getValue('token') === $token : true;
	}

	protected function employeeCan($right)
	{
		$employee = $this->context->employee;
		if (!Validate::isLoadedObject($employee)) {
			return false;
		}
		$access = Profile::getProfileAccess($employee->id_profile, (int) Tab::getIdFromClassName('AdminModules'));
		if (!is_array($access)) {
			return false;
		}
		switch ($right) {
			case 'add': return !empty($access['add']);
			case 'edit': return !empty($access['edit']);
			case 'delete': return !empty($access['delete']);
			default: return false;
		}
	}

	protected function getRulesList()
	{
		$sql = 'SELECT * FROM `'._DB_PREFIX_.'mbmaxlimit_rule` ORDER BY id_rule DESC';
		return Db::getInstance()->executeS($sql) ?: [];
	}

	public function hookActionProductFormBuilderModifier(array $params)
	{
		$formBuilder = $params['form_builder'];
		$data = $params['data'];

		$idProduct = (int) (isset($data['id_product']) ? $data['id_product'] : 0);
		$maxQty = $this->getProductMaxQty($idProduct);
		$active = $this->isProductLimitActive($idProduct);

		// Use generic fields when Symfony types unavailable in context
		$formBuilder->add('mbmaxlimit_max_qty', 'Symfony\Component\Form\Extension\Core\Type\IntegerType', [
			'label' => $this->l('Maximum per cart'),
			'help' => $this->l('Customer cannot add more than this quantity of the product to a cart (0 means no limit).'),
			'required' => false,
			'data' => $maxQty,
			'empty_data' => 0,
			'scale' => 0,
			'attr' => ['min' => 0],
		]);

		$formBuilder->add('mbmaxlimit_active', 'Symfony\Component\Form\Extension\Core\Type\CheckboxType', [
			'label' => $this->l('Enable max limit for this product'),
			'required' => false,
			'data' => $active,
		]);
	}

	public function hookActionProductFormDataProvider(array $params)
	{
		$idProduct = (int) (isset($params['id']) ? $params['id'] : 0);
		return [
			'mbmaxlimit_max_qty' => $this->getProductMaxQty($idProduct),
			'mbmaxlimit_active' => $this->isProductLimitActive($idProduct),
		];
	}

	public function hookActionAfterUpdateProductFormHandler(array $params)
	{
		$idProduct = (int) $params['id'];
		$formData = $params['form_data'];
		$maxQty = isset($formData['mbmaxlimit_max_qty']) ? (int)$formData['mbmaxlimit_max_qty'] : 0;
		$active = !empty($formData['mbmaxlimit_active']) ? 1 : 0;
		$this->saveProductLimit($idProduct, $maxQty, $active);
	}

	public function hookActionAfterCreateProductFormHandler(array $params)
	{
		$idProduct = (int) $params['id'];
		$formData = $params['form_data'];
		$maxQty = isset($formData['mbmaxlimit_max_qty']) ? (int)$formData['mbmaxlimit_max_qty'] : 0;
		$active = !empty($formData['mbmaxlimit_active']) ? 1 : 0;
		$this->saveProductLimit($idProduct, $maxQty, $active);
	}

	public function hookActionCartUpdateQuantityBefore(array &$params)
	{
		$idProduct = (int) $params['id_product'];
		$idProductAttribute = isset($params['id_product_attribute']) ? (int)$params['id_product_attribute'] : 0;
		$op = isset($params['operator']) ? $params['operator'] : 'up';
		$delta = (int) $params['quantity'];

		$limit = $this->computeEffectiveLimit($idProduct);
		$max = (int) $limit['max'];
		if ($max <= 0) {
			return;
		}

		$cart = $this->context->cart;
		if (!Validate::isLoadedObject($cart)) {
			return;
		}

		$qtyInfo = $cart->getProductQuantity($idProduct, $idProductAttribute);
		$currentQty = 0;
		if (is_array($qtyInfo) && isset($qtyInfo['quantity'])) {
			$currentQty = (int) $qtyInfo['quantity'];
		} elseif (is_numeric($qtyInfo)) {
			$currentQty = (int) $qtyInfo;
		}
		$newQty = $currentQty;
		if ($op === 'up') {
			$newQty += $delta;
		} elseif ($op === 'down') {
			$newQty -= $delta;
		} else {
			$newQty = $delta; // set
		}

		if ($newQty > $max) {
			$params['quantity'] = max(0, $max - $currentQty);
			$message = $this->buildLimitMessage($limit, $max);
			$this->context->controller->errors[] = $message;
			// For AJAX add-to-cart, provide translated message via front controller if available
		}
	}

	protected function _getExecutionContext($idProduct)
	{
		$context = $this->context;
		$idShop = (int) $context->shop->id;
		$idCustomer = (int) $context->customer->id;
		$idAddressDelivery = (int) $context->cart ? (int)$context->cart->id_address_delivery : 0;

		$manufacturerId = (int) Db::getInstance()->getValue('SELECT `id_manufacturer` FROM `'._DB_PREFIX_.'product` WHERE id_product='.(int)$idProduct);
		$categoryIds = array_map('intval', Product::getProductCategories((int)$idProduct));

		$countryId = 0;
		if ($idAddressDelivery) {
			$address = new Address($idAddressDelivery);
			if (Validate::isLoadedObject($address)) {
				$countryId = (int) $address->id_country;
			}
		}

		$groupIds = [];
		if ($idCustomer) {
			$groupIds = Customer::getGroupsStatic($idCustomer);
		}

		return [
			'idShop' => $idShop,
			'idCustomer' => $idCustomer,
			'manufacturerId' => $manufacturerId,
			'categoryIds' => $categoryIds,
			'countryId' => $countryId,
			'groupIds' => $groupIds,
		];
	}

	protected function computeEffectiveMaxQty($idProduct)
	{
		// Start from per-product limit if active
		$productMax = 0;
		if ($this->isProductLimitActive($idProduct)) {
			$productMax = (int) $this->getProductMaxQty($idProduct);
		}

		$ruleMaxes = [];
		extract($this->_getExecutionContext($idProduct));

		// Fetch active rules
		$rules = Db::getInstance()->executeS('SELECT * FROM `'._DB_PREFIX_.'mbmaxlimit_rule` WHERE active=1');
		foreach ($rules as $rule) {
			if (!$this->ruleAppliesToContext($rule, $idShop)) { continue; }
			if ($this->isProductExcludedByRule($rule, $idProduct, $categoryIds)) { continue; }
			switch ($rule['scope']) {
				case 'brand':
					if ($manufacturerId && (int)$rule['id_target'] === $manufacturerId) { $ruleMaxes[] = (int)$rule['max_qty']; }
					break;
				case 'category':
					if (!empty($categoryIds) && in_array((int)$rule['id_target'], $categoryIds, true)) { $ruleMaxes[] = (int)$rule['max_qty']; }
					break;
				case 'country':
					if ($countryId && (int)$rule['id_target'] === $countryId) { $ruleMaxes[] = (int)$rule['max_qty']; }
					break;
				case 'customer_group':
					if (!empty($groupIds) && in_array((int)$rule['id_target'], array_map('intval', $groupIds), true)) { $ruleMaxes[] = (int)$rule['max_qty']; }
					break;
			}
		}

		$all = array_filter(array_merge([$productMax], $ruleMaxes), function($v){ return (int)$v > 0; });
		if (empty($all)) {
			return 0;
		}
		return (int) min($all);
	}

	protected function computeEffectiveLimit($idProduct)
	{
		// Get all contextual information (customer, shop, product groups, etc.)
		extract($this->_getExecutionContext($idProduct));

		// Initialize with default values. bestMax=0 means no limit.
		$bestMax = 0;
		$bestRule = null;

		// First, check if there is a specific limit set on the product page itself.
		// This acts as a base limit.
		if ($this->isProductLimitActive($idProduct)) {
			$bestMax = (int) $this->getProductMaxQty($idProduct);
		}

		// Get all active advanced rules to find a potentially more restrictive limit.
		$rules = Db::getInstance()->executeS('SELECT * FROM `'._DB_PREFIX_.'mbmaxlimit_rule` WHERE active=1');
		foreach ($rules as $rule) {
			// Check if the rule is active based on date, day of week, and shop context.
			if (!$this->ruleAppliesToContext($rule, $idShop)) {
				continue;
			}
			// Check if the current product or its category is explicitly excluded by this rule.
			if ($this->isProductExcludedByRule($rule, $idProduct, $categoryIds)) {
				continue;
			}

			// Check if the rule's scope (category, brand, etc.) matches the current product and context.
			$matches = false;
			switch ($rule['scope']) {
				case 'brand':
					$matches = ($manufacturerId && (int)$rule['id_target'] === $manufacturerId);
					break;
				case 'category':
					$matches = (!empty($categoryIds) && in_array((int)$rule['id_target'], $categoryIds, true));
					break;
				case 'country':
					$matches = ($countryId && (int)$rule['id_target'] === $countryId);
					break;
				case 'customer_group':
					$matches = (!empty($groupIds) && in_array((int)$rule['id_target'], array_map('intval', $groupIds), true));
					break;
			}
			if (!$matches) {
				continue;
			}

			// The rule matches. Now, let's determine its effective max quantity.
			$ruleMax = (int) $rule['max_qty'];

			// If a lifetime limit is set for this rule and the customer is logged in,
			// calculate the remaining allowed quantity.
			if ($idCustomer && (int)$rule['lifetime_max_qty'] > 0) {
				$purchased = $this->getLifetimePurchasedQty($idCustomer, (int)$idProduct, (int)$rule['id_shop']);
				$remaining = max(0, (int)$rule['lifetime_max_qty'] - (int)$purchased);
				// The effective limit is the minimum of the rule's cart limit and the remaining lifetime limit.
				if ($remaining >= 0) {
					$ruleMax = ($ruleMax > 0) ? min($ruleMax, $remaining) : $remaining;
				}
			}

			// We are looking for the *most restrictive* (lowest) limit.
			// If the current rule's limit is lower than the best one we've found so far,
			// this rule becomes the new best one. A limit of 0 is ignored.
			if ($ruleMax > 0) {
				if ($bestMax === 0 || $ruleMax < $bestMax) {
					$bestMax = $ruleMax;
					$bestRule = $rule;
				}
			}
		}

		// Return the final calculated limit and the rule that caused it.
		return [
			'max' => (int) $bestMax,
			'rule' => $bestRule,
		];
	}

	protected function getLifetimePurchasedQty($idCustomer, $idProduct, $idShopFilter = 0)
	{
		$sql = 'SELECT SUM(od.product_quantity) FROM `'._DB_PREFIX_.'order_detail` od
			INNER JOIN `'._DB_PREFIX_.'orders` o ON (o.id_order = od.id_order)
			INNER JOIN `'._DB_PREFIX_.'order_state` os ON (os.id_order_state = o.current_state)
			WHERE o.id_customer='.(int)$idCustomer.' AND od.product_id='.(int)$idProduct.' AND os.logable=1';
		if ($idShopFilter) {
			$sql .= ' AND o.id_shop='.(int)$idShopFilter;
		}
		$sum = Db::getInstance()->getValue($sql);
		return (int) $sum;
	}

	protected function buildLimitMessage(array $limit, $max)
	{
		$langIso = Language::getIsoById((int)$this->context->language->id);
		$rule = isset($limit['rule']) ? $limit['rule'] : null;
		if ($rule) {
			$msg = null;
			if ($langIso === 'fa' && !empty($rule['message_fa'])) {
				$msg = $rule['message_fa'];
			} elseif (!empty($rule['message_en'])) {
				$msg = $rule['message_en'];
			}
			if ($msg) {
				return sprintf($msg, (int)$max);
			}
		}
		return sprintf($this->l('You cannot add more than %d units of this product to your cart.'), (int)$max);
	}

	protected function ruleAppliesToContext(array $rule, $idShop)
	{
		if (!empty($rule['id_shop']) && (int)$rule['id_shop'] !== (int)$idShop) { return false; }
		$now = new DateTime();
		$fmt = 'Y-m-d H:i:s';
		if (!empty($rule['date_from'])) {
			$from = DateTime::createFromFormat($fmt, $rule['date_from']);
			if ($from && $now < $from) { return false; }
		}
		if (!empty($rule['date_to'])) {
			$to = DateTime::createFromFormat($fmt, $rule['date_to']);
			if ($to && $now > $to) { return false; }
		}
		$mask = (int) $rule['dow_mask'];
		if ($mask > 0) {
			$weekday = (int) $now->format('w'); // 0=Sun .. 6=Sat
			if (((1 << $weekday) & $mask) === 0) { return false; }
		}
		return true;
	}

	protected function isProductExcludedByRule(array $rule, $idProduct, array $categoryIds)
	{
		$ex = Db::getInstance()->executeS('SELECT scope, id_target FROM `'._DB_PREFIX_.'mbmaxlimit_rule_excl` WHERE id_rule='.(int)$rule['id_rule']);
		foreach ($ex as $row) {
			if ($row['scope'] === 'product' && (int)$row['id_target'] === (int)$idProduct) { return true; }
			if ($row['scope'] === 'category' && in_array((int)$row['id_target'], $categoryIds, true)) { return true; }
		}
		return false;
	}

	protected function saveExclusionsCsv($idRule, $scope, $csv)
	{
		Db::getInstance()->delete('mbmaxlimit_rule_excl', 'id_rule='.(int)$idRule.' AND scope=\''.(pSQL($scope)).'\'');
		if (!$csv) { return; }
		$ids = array_filter(array_map('intval', array_map('trim', explode(',', $csv))));
		foreach ($ids as $id) {
			Db::getInstance()->insert('mbmaxlimit_rule_excl', [
				'id_rule' => (int)$idRule,
				'scope' => pSQL($scope),
				'id_target' => (int)$id,
			]);
		}
	}

	protected function getProductMaxQty($idProduct)
	{
		if (!$idProduct) {
			return 0;
		}
		$sql = 'SELECT `max_qty` FROM `'._DB_PREFIX_.'mbmaxlimit_product` WHERE `id_product`='.(int)$idProduct.' AND `active`=1';
		$value = Db::getInstance()->getValue($sql);
		return (int) $value;
	}

	protected function isProductLimitActive($idProduct)
	{
		if (!$idProduct) {
			return false;
		}
		$sql = 'SELECT `active` FROM `'._DB_PREFIX_.'mbmaxlimit_product` WHERE `id_product`='.(int)$idProduct;
		$value = Db::getInstance()->getValue($sql);
		return (bool) $value;
	}

	protected function saveProductLimit($idProduct, $maxQty, $active)
	{
		$idProduct = (int) $idProduct;
		$maxQty = (int) max(0, (int)$maxQty);
		$active = (int) ($active ? 1 : 0);

		$data = [
			'max_qty' => $maxQty,
			'active' => $active,
		];

		$exists = (bool) Db::getInstance()->getValue('SELECT `id_product` FROM `'._DB_PREFIX_.'mbmaxlimit_product` WHERE `id_product`='.(int)$idProduct);
		if ($exists) {
			return Db::getInstance()->update('mbmaxlimit_product', $data, 'id_product = '.(int)$idProduct);
		} else {
			$data['id_product'] = $idProduct;
			return Db::getInstance()->insert('mbmaxlimit_product', $data);
		}
	}
}


