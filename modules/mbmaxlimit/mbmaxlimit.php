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
		$this->version = '2.1.0';
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
			&& $this->registerHook('actionCartUpdateQuantityBefore')
			// Hooks for combinations
			&& $this->registerHook('actionProductCombinationFormBuilderModifier')
			&& $this->registerHook('actionProductCombinationDataProvider')
			&& $this->registerHook('actionAfterCreateCombination')
			&& $this->registerHook('actionAfterUpdateCombination')
			// Hooks for frontend display
			&& $this->registerHook('displayProductAdditionalInfo')
			&& $this->registerHook('actionFrontControllerSetMedia')
			&& $this->registerHook('actionProductRefresh');
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
			`id_product_attribute` INT UNSIGNED NOT NULL DEFAULT 0,
			`max_qty` INT UNSIGNED NOT NULL DEFAULT 0,
			`active` TINYINT(1) NOT NULL DEFAULT 1,
			PRIMARY KEY (`id_product`, `id_product_attribute`)
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
			`time_frame` VARCHAR(32) NOT NULL DEFAULT 'all_time',
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
		// Handle form submissions first
		if (Tools::isSubmit('submit_mbmaxlimit_global')) {
			if (!$this->isValidAdminToken() || !$this->employeeCan('edit')) {
				return $this->displayError($this->l('Invalid token or insufficient permissions.'));
			}
			$globalMax = Tools::getValue('MBMAXLIMIT_GLOBAL_MAX_QTY');
			$showRemaining = Tools::getValue('MBMAXLIMIT_SHOW_REMAINING');
			$useModal = Tools::getValue('MBMAXLIMIT_USE_MODAL');

			if (!Validate::isUnsignedInt($globalMax)) {
				$output .= $this->displayError($this->l('Invalid value for default maximum quantity.'));
			} else {
				Configuration::updateValue('MBMAXLIMIT_GLOBAL_MAX_QTY', (int)$globalMax);
				Configuration::updateValue('MBMAXLIMIT_SHOW_REMAINING', (bool)$showRemaining);
				Configuration::updateValue('MBMAXLIMIT_USE_MODAL', (bool)$useModal);
				$output .= $this->displayConfirmation($this->l('Global settings updated.'));
			}
		} elseif (Tools::isSubmit('submit_mbmaxlimit_clean')) {
			if (!$this->isValidAdminToken() || !$this->employeeCan('delete')) {
				return $this->displayError($this->l('Invalid token or insufficient permissions.'));
			}
			Db::getInstance()->delete('mbmaxlimit_product', '`max_qty` = 0');
			$output .= $this->displayConfirmation($this->l('Cleaned entries with zero max quantity.'));
		} elseif (Tools::isSubmit('submit_mbmaxlimit_bulk')) {
			if (!$this->isValidAdminToken() || !$this->employeeCan('edit')) {
				return $this->displayError($this->l('Invalid token or insufficient permissions.'));
			}
			$categories = Tools::getValue('category_box');
			if (empty($categories)) {
				$output .= $this->displayError($this->l('You must select at least one category.'));
			} else {
				$action = Tools::getValue('bulk_action');
				$maxQty = (int)Tools::getValue('bulk_max_qty');

				if ($action === 'add_update' && $maxQty <= 0) {
					$output .= $this->displayError($this->l('Max quantity must be a positive number for the add/update action.'));
				} else {
					$productIds = $this->getProductsInCategories($categories);
					$count = 0;
					foreach ($productIds as $id_product) {
						if ($action === 'add_update') {
							$this->saveProductLimit($id_product, 0, $maxQty, true);
							$count++;
						} elseif ($action === 'remove') {
							$this->removeProductLimit($id_product, 0);
							$count++;
						}
					}
					$output .= $this->displayConfirmation(sprintf($this->l('Bulk action completed. %d products affected.'), $count));
				}
			}
		}

		// Assign rendered forms and lists to Smarty
		$this->context->smarty->assign([
			'global_settings_form' => $this->renderGlobalLimitForm(),
			'bulk_actions_form' => $this->renderBulkActionsForm(),
			'advanced_rules_form' => $this->handleAndRenderRules(),
			'limited_products_list' => $this->renderList(),
			'maintenance_form' => $this->renderActions(),
		]);

		// Fetch the custom template
		return $output . $this->display(__FILE__, 'views/templates/admin/configure.tpl');
	}

	protected function renderGlobalLimitForm()
	{
		$helper = new HelperForm();
		$helper->module = $this;
		$helper->token = Tools::getAdminTokenLite('AdminModules');
		$helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;
		$helper->submit_action = 'submit_mbmaxlimit_global';
		$helper->show_toolbar = false;

		$helper->fields_value['MBMAXLIMIT_GLOBAL_MAX_QTY'] = Configuration::get('MBMAXLIMIT_GLOBAL_MAX_QTY');
		$helper->fields_value['MBMAXLIMIT_SHOW_REMAINING'] = Configuration::get('MBMAXLIMIT_SHOW_REMAINING');
		$helper->fields_value['MBMAXLIMIT_USE_MODAL'] = Configuration::get('MBMAXLIMIT_USE_MODAL');

		$fieldsForm = [
			'form' => [
				'legend' => [
					'title' => $this->l('Global Settings'),
				],
				'input' => [
					[
						'type' => 'text',
						'label' => $this->l('Default maximum quantity'),
						'name' => 'MBMAXLIMIT_GLOBAL_MAX_QTY',
						'desc' => $this->l('This limit applies to all products unless a more specific limit (on product, combination, or by rule) is set. 0 means no global limit.'),
						'class' => 'input fixed-width-sm',
					],
					[
						'type' => 'switch',
						'label' => $this->l('Display remaining quantity'),
						'name' => 'MBMAXLIMIT_SHOW_REMAINING',
						'is_bool' => true,
						'values' => [
							['id' => 'active_on', 'value' => 1, 'label' => $this->l('Enabled')],
							['id' => 'active_off', 'value' => 0, 'label' => $this->l('Disabled')],
						],
						'desc' => $this->l('Show a message on the product page indicating how many more units the customer can buy.'),
					],
					[
						'type' => 'switch',
						'label' => $this->l('Use modal popups for errors'),
						'name' => 'MBMAXLIMIT_USE_MODAL',
						'is_bool' => true,
						'values' => [
							['id' => 'active_on', 'value' => 1, 'label' => $this->l('Enabled')],
							['id' => 'active_off', 'value' => 0, 'label' => $this->l('Disabled')],
						],
						'desc' => $this->l('When a limit is reached, show a modal popup instead of the default top-page notification.'),
					],
				],
				'submit' => [
					'title' => $this->l('Save'),
				],
			],
		];

		return $helper->generateForm([$fieldsForm]);
	}

	protected function renderBulkActionsForm()
	{
		$helper = new HelperForm();
		$helper->module = $this;
		$helper->token = Tools::getAdminTokenLite('AdminModules');
		$helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;
		$helper->submit_action = 'submit_mbmaxlimit_bulk';

		$root = Category::getRootCategory();
		$tree = new HelperTreeCategories('bulk-categories-tree', $this->l('Categories'));
		$tree->setUseCheckBox(true)->setFullTree(true);

		$fieldsForm = [
			'form' => [
				'legend' => [
					'title' => $this->l('Bulk Actions'),
					'icon' => 'icon-tasks',
				],
				'input' => [
					[
						'type' => 'categories',
						'label' => $this->l('Categories'),
						'name' => 'category_box',
						'tree' => [
							'id' => 'bulk-categories-tree',
							'selected_categories' => Tools::getValue('category_box', []),
							'root_category' => $root->id,
							'use_search' => true,
							'use_checkbox' => true,
						],
						'desc' => $this->l('Apply action to all products in the selected categories.'),
					],
					[
						'type' => 'select',
						'label' => $this->l('Action'),
						'name' => 'bulk_action',
						'options' => [
							'query' => [
								['id' => 'add_update', 'name' => $this->l('Add / Update Limit')],
								['id' => 'remove', 'name' => $this->l('Remove Limit')],
							],
							'id' => 'id',
							'name' => 'name',
						],
					],
					[
						'type' => 'text',
						'label' => $this->l('Max Quantity'),
						'name' => 'bulk_max_qty',
						'class' => 'input fixed-width-sm',
						'desc' => $this->l('Set the quantity for the "Add/Update" action. Ignored for "Remove".'),
					],
				],
				'submit' => [
					'title' => $this->l('Apply Bulk Action'),
					'class' => 'btn btn-default pull-right',
				],
			],
		];

		return $helper->generateForm([$fieldsForm]);
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
		$id_lang = $this->context->language->id;

		foreach ($products as &$product) {
			if (!empty($product['id_product_attribute'])) {
				$product['name'] = Product::getProductName($product['id_product'], $product['id_product_attribute'], $id_lang);
			}
			// Create a unique ID for the list helper since it doesn't support composite keys
			$product['unique_id'] = $product['id_product'] . '-' . $product['id_product_attribute'];
		}

		$fieldsList = [
			'id_product' => [
				'title' => $this->l('ID'),
				'type' => 'text',
			],
			'name' => [
				'title' => $this->l('Product / Combination'),
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
				'callback' => 'printBool', // HelperList needs a callback for bool type
			],
		];

		$helper = new HelperList();
		$helper->shopLinkType = '';
		$helper->simple_header = true;
		$helper->listTotal = count($products);
		$helper->identifier = 'unique_id'; // Use the unique ID
		$helper->actions = ['edit'];
		$helper->show_toolbar = false;
		$helper->module = $this;
		$helper->title = $this->l('Products with maximum quantity limit');
		$helper->table = 'mbmaxlimit_product'; // Table name for actions
		$helper->token = Tools::getAdminTokenLite('AdminProducts');
		// Link to the product page. The #tab-step3 anchor jumps to combinations tab.
		$helper->currentIndex = $this->context->link->getAdminLink('AdminProducts') . '#tab-step3';

		return $helper->generateList($products, $fieldsList);
	}

	// Helper function for the HelperList 'active' column
	public function printBool($value, $row)
	{
		return $value ? $this->l('Yes') : $this->l('No');
	}

	protected function getLimitedProducts()
	{
		$id_lang = (int)$this->context->language->id;
		$id_shop = (int)$this->context->shop->id;
		$sql = 'SELECT p.id_product, ml.id_product_attribute, pl.name, p.reference, ml.max_qty, ml.active
			FROM `'._DB_PREFIX_.'mbmaxlimit_product` ml
			INNER JOIN `'._DB_PREFIX_.'product` p ON (p.id_product = ml.id_product)
			LEFT JOIN `'._DB_PREFIX_.'product_lang` pl ON (pl.id_product = p.id_product AND pl.id_lang='.$id_lang.' AND pl.id_shop='.$id_shop.')
			WHERE ml.max_qty > 0
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
			$allowedScopes = ['category','brand','country','customer_group', 'feature'];
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
			$timeFrame = Tools::getValue('mb_time_frame', 'all_time');
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
				'time_frame' => pSQL($timeFrame),
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
								['id' => 'feature', 'name' => $this->l('Product Feature')],
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
						'label' => $this->l('Max purchases per time frame'),
						'name' => 'mb_lifetime_max',
						'desc' => $this->l('Set the maximum number of times a customer can purchase products matching this rule. 0 = no limit.'),
						'col' => 4,
					],
					[
						'type' => 'select',
						'label' => $this->l('Time Frame'),
						'name' => 'mb_time_frame',
						'options' => [
							'query' => [
								['id' => 'all_time', 'name' => $this->l('All Time')],
								['id' => 'daily', 'name' => $this->l('Per Day (last 24 hours)')],
								['id' => 'weekly', 'name' => $this->l('Per Week (last 7 days)')],
								['id' => 'monthly', 'name' => $this->l('Per Month (last 30 days)')],
							],
							'id' => 'id',
							'name' => 'name',
						],
						'desc' => $this->l('The time period over which the purchase limit is enforced.'),
						'col' => 4,
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
			'time_frame' => ['title' => $this->l('Time Frame')],
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
		$idProduct = (int) $params['id'];
		$limitData = $this->getProductLimitData($idProduct, 0);

		$formBuilder->add('mbmaxlimit_max_qty', 'Symfony\Component\Form\Extension\Core\Type\IntegerType', [
			'label' => $this->l('Maximum per cart'),
			'help' => $this->l('Customer cannot add more than this quantity of the product to a cart (0 means no limit). This limit applies to the product as a whole if no combination-specific limit is set.'),
			'required' => false,
			'data' => $limitData['max_qty'],
			'empty_data' => 0,
			'attr' => ['min' => 0],
			'form_tab' => 'Quantities',
		]);

		$formBuilder->add('mbmaxlimit_active', 'Symfony\Component\Form\Extension\Core\Type\CheckboxType', [
			'label' => $this->l('Enable max limit for this product'),
			'required' => false,
			'data' => (bool)$limitData['active'],
			'form_tab' => 'Quantities',
		]);
	}

	public function hookActionProductFormDataProvider(array $params)
	{
		$idProduct = (int) $params['id'];
		$limitData = $this->getProductLimitData($idProduct, 0);
		return [
			'mbmaxlimit_max_qty' => $limitData['max_qty'],
			'mbmaxlimit_active' => (bool)$limitData['active'],
		];
	}

	public function hookActionAfterUpdateProductFormHandler(array $params)
	{
		$idProduct = (int) $params['id'];
		$formData = $params['form_data'];
		$maxQty = isset($formData['mbmaxlimit_max_qty']) ? (int)$formData['mbmaxlimit_max_qty'] : 0;
		$active = !empty($formData['mbmaxlimit_active']);
		$this->saveProductLimit($idProduct, 0, $maxQty, $active);
	}

	public function hookActionAfterCreateProductFormHandler(array $params)
	{
		$this->hookActionAfterUpdateProductFormHandler($params);
	}

	public function hookActionProductCombinationFormBuilderModifier(array $params)
	{
		$formBuilder = $params['form_builder'];
		$idProduct = (int) Tools::getValue('id_product');
		$idProductAttribute = (int) $params['id'];
		$limitData = $this->getProductLimitData($idProduct, $idProductAttribute);

		$formBuilder->add('mbmaxlimit_max_qty_kombi', 'Symfony\Component\Form\Extension\Core\Type\IntegerType', [
			'label' => $this->l('Maximum per cart (this combination)'),
			'help' => $this->l('Set a specific limit for this combination. Overrides the main product limit. 0 = no limit.'),
			'required' => false,
			'data' => $limitData['max_qty'],
			'empty_data' => 0,
			'attr' => ['min' => 0],
		]);
		$formBuilder->add('mbmaxlimit_active_kombi', 'Symfony\Component\Form\Extension\Core\Type\CheckboxType', [
			'label' => $this->l('Enable max limit for this combination'),
			'required' => false,
			'data' => (bool)$limitData['active'],
		]);
	}

	public function hookActionProductCombinationDataProvider(array $params)
	{
		$idProduct = (int) Tools::getValue('id_product');
		$idProductAttribute = (int) $params['id'];
		$limitData = $this->getProductLimitData($idProduct, $idProductAttribute);
		return [
			'mbmaxlimit_max_qty_kombi' => $limitData['max_qty'],
			'mbmaxlimit_active_kombi' => (bool)$limitData['active'],
		];
	}

	public function hookActionAfterUpdateCombination(array $params)
	{
		$idProduct = (int) Tools::getValue('id_product');
		$idProductAttribute = (int) $params['id_product_attribute'];
		$formData = $params['form_data'];
		$maxQty = isset($formData['mbmaxlimit_max_qty_kombi']) ? (int)$formData['mbmaxlimit_max_qty_kombi'] : 0;
		$active = !empty($formData['mbmaxlimit_active_kombi']);
		$this->saveProductLimit($idProduct, $idProductAttribute, $maxQty, $active);
	}

	public function hookActionAfterCreateCombination(array $params)
	{
		// id_product_attribute is not available in create context, need to get it from the newly created combination
		$idProductAttribute = (int) $params['id_product_attribute'];
		// Also need to get id_product from the request
		$idProduct = (int) Tools::getValue('id_product');
		$params['id_product_attribute'] = $idProductAttribute; // ensure it's in params for the handler
		$this->hookActionAfterUpdateCombination($params);
	}

	public function hookActionCartUpdateQuantityBefore(array &$params)
	{
		$idProduct = (int) $params['id_product'];
		$idProductAttribute = isset($params['id_product_attribute']) ? (int)$params['id_product_attribute'] : 0;
		$op = isset($params['operator']) ? $params['operator'] : 'up';
		$delta = (int) $params['quantity'];

		$limit = $this->computeEffectiveLimit($idProduct, $idProductAttribute);
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
		$productFeatures = Product::getFeaturesStatic((int)$idProduct);

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
			'productFeatures' => $productFeatures,
		];
	}

	protected function computeEffectiveLimit($idProduct, $idProductAttribute)
	{
		extract($this->_getExecutionContext($idProduct));

		$bestMax = 0;
		$bestRule = null;

		// 1. Check for a specific, active limit on the combination itself.
		if ($idProductAttribute > 0) {
			$limitData = $this->getProductLimitData($idProduct, $idProductAttribute);
			if ($limitData['active'] && $limitData['max_qty'] > 0) {
				$bestMax = $limitData['max_qty'];
			}
		}

		// 2. If no combination-specific limit is found, check for a limit on the main product.
		if ($bestMax === 0) {
			$limitData = $this->getProductLimitData($idProduct, 0);
			if ($limitData['active'] && $limitData['max_qty'] > 0) {
				$bestMax = $limitData['max_qty'];
			}
		}

		$rules = Db::getInstance()->executeS('SELECT * FROM `'._DB_PREFIX_.'mbmaxlimit_rule` WHERE active=1');
		foreach ($rules as $rule) {
			if (!$this->ruleAppliesToContext($rule, $idShop)) {
				continue;
			}
			if ($this->isProductExcludedByRule($rule, $idProduct, $categoryIds)) {
				continue;
			}

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
				case 'feature':
					foreach ($productFeatures as $productFeature) {
						if ((int)$productFeature['id_feature'] === (int)$rule['id_target']) {
							$matches = true;
							break;
						}
					}
					break;
			}
			if (!$matches) {
				continue;
			}

			$ruleMax = (int) $rule['max_qty'];
			if ($idCustomer && (int)$rule['lifetime_max_qty'] > 0) {
				$purchased = $this->getCustomerPurchasedQty($idCustomer, (int)$idProduct, $rule['time_frame'], (int)$rule['id_shop']);
				$remaining = max(0, (int)$rule['lifetime_max_qty'] - (int)$purchased);
				if ($remaining >= 0) {
					$ruleMax = ($ruleMax > 0) ? min($ruleMax, $remaining) : $remaining;
				}
			}

			if ($ruleMax > 0) {
				if ($bestMax === 0 || $ruleMax < $bestMax) {
					$bestMax = $ruleMax;
					$bestRule = $rule;
				}
			}
		}

		// 4. If no other limit was found, apply the global limit as a fallback.
		if ($bestMax === 0) {
			$globalMax = (int)Configuration::get('MBMAXLIMIT_GLOBAL_MAX_QTY');
			if ($globalMax > 0) {
				$bestMax = $globalMax;
			}
		}

		return [
			'max' => (int) $bestMax,
			'rule' => $bestRule,
		];
	}

	protected function getCustomerPurchasedQty($idCustomer, $idProduct, $timeFrame, $idShopFilter = 0)
	{
		// This check works for both registered and guest customers, as PrestaShop creates
		// a guest account (with an id_customer) once they proceed in the checkout.
		$sql = 'SELECT SUM(od.product_quantity) FROM `'._DB_PREFIX_.'order_detail` od
			INNER JOIN `'._DB_PREFIX_.'orders` o ON (o.id_order = od.id_order)
			INNER JOIN `'._DB_PREFIX_.'order_state` os ON (os.id_order_state = o.current_state)
			WHERE o.id_customer='.(int)$idCustomer.' AND od.product_id='.(int)$idProduct.' AND os.logable=1';

		switch ($timeFrame) {
			case 'daily':
				$sql .= ' AND o.date_add >= DATE_SUB(NOW(), INTERVAL 1 DAY)';
				break;
			case 'weekly':
				$sql .= ' AND o.date_add >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
				break;
			case 'monthly':
				$sql .= ' AND o.date_add >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
				break;
			case 'all_time':
			default:
				// No date constraint
				break;
		}

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

	protected function getProductLimitData($idProduct, $idProductAttribute)
	{
		$row = Db::getInstance()->getRow(
			'SELECT `max_qty`, `active` FROM `'._DB_PREFIX_.'mbmaxlimit_product`
			WHERE `id_product`='.(int)$idProduct.' AND `id_product_attribute`='.(int)$idProductAttribute
		);
		if (!$row) {
			return ['max_qty' => 0, 'active' => false];
		}
		return ['max_qty' => (int)$row['max_qty'], 'active' => (bool)$row['active']];
	}

	protected function saveProductLimit($idProduct, $idProductAttribute, $maxQty, $active)
	{
		$data = [
			'id_product' => (int)$idProduct,
			'id_product_attribute' => (int)$idProductAttribute,
			'max_qty' => (int)max(0, $maxQty),
			'active' => (int)$active,
		];

		return Db::getInstance()->insert('mbmaxlimit_product', $data, false, true, Db::REPLACE);
	}

	protected function removeProductLimit($idProduct, $idProductAttribute)
	{
		return Db::getInstance()->delete(
			'mbmaxlimit_product',
			'`id_product` = '.(int)$idProduct.' AND `id_product_attribute` = '.(int)$idProductAttribute
		);
	}

	protected function getProductsInCategories(array $categoryIds)
	{
		if (empty($categoryIds)) {
			return [];
		}

		$sql = 'SELECT DISTINCT cp.id_product FROM `'._DB_PREFIX_.'category_product` cp WHERE cp.id_category IN ('.implode(',', array_map('intval', $categoryIds)).')';

		$rows = Db::getInstance()->executeS($sql);

		if (!$rows) {
			return [];
		}

		return array_column($rows, 'id_product');
	}

	public function hookActionFrontControllerSetMedia()
	{
		if ($this->context->controller->php_self == 'product') {
			$this->context->controller->registerJavascript(
				'module-mbmaxlimit-front',
				$this->_path.'views/js/front.js',
				['position' => 'bottom', 'priority' => 150]
			);

			Media::addJsDef([
				'mbmaxlimit_use_modal' => (bool)Configuration::get('MBMAXLIMIT_USE_MODAL'),
				'mbmaxlimit_error_pattern' => $this->l('You can add %d more of this item to your cart.'),
				'mbmaxlimit_error_pattern2' => $this->l('You have reached the purchase limit for this item.'),
			]);
		}
	}

	public function hookDisplayProductAdditionalInfo($params)
	{
		if (!Configuration::get('MBMAXLIMIT_SHOW_REMAINING')) {
			return;
		}

		$message = $this->getRemainingQuantityMessage($params['product']);

		Media::addJsDef(['mbmaxlimit_init_data' => ['message' => $message]]);

		$this->context->smarty->assign(['mbmaxlimit_message' => $message]);
		return $this->display(__FILE__, 'views/templates/hook/product_info.tpl');
	}

	public function hookActionProductRefresh($params)
    {
		if (!Configuration::get('MBMAXLIMIT_SHOW_REMAINING')) {
			return;
		}

        $product = $params['product'];
        $message = $this->getRemainingQuantityMessage($product);

		// PrestaShop 1.7.3+ way to add extra content to the refresh result
        $params['extra_content']['mbmaxlimit_remaining_message'] = $message;

		// PrestaShop 1.7.7+ way is to modify the product array directly
		if (isset($params['product_details'])) {
			$params['product_details']['mbmaxlimit_remaining_message'] = $message;
		}
    }

	protected function getRemainingQuantityMessage($product)
	{
		$id_product = (int)$product['id_product'];
		$id_product_attribute = (int)$product['id_product_attribute'];

		$limit = $this->computeEffectiveLimit($id_product, $id_product_attribute);
		$max = (int)$limit['max'];

		if ($max <= 0) {
			return '';
		}

		$cart_qty = 0;
		if ($this->context->cart) {
			$cart_products = $this->context->cart->getProducts();
			foreach ($cart_products as $p) {
				if ($p['id_product'] == $id_product && $p['id_product_attribute'] == $id_product_attribute) {
					$cart_qty = $p['cart_quantity'];
					break;
				}
			}
		}

		$remaining = $max - $cart_qty;
		if ($remaining <= 0) {
			return $this->l('You have reached the purchase limit for this item.');
		}

		return sprintf($this->l('You can add %d more of this item to your cart.'), $remaining);
	}
}


