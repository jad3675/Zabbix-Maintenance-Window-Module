<?php declare(strict_types = 1);

namespace Modules\MaintLoader;

use APP;
use CMenuItem;
use Zabbix\Core\CModule;

/**
 * Maintenance Loader.
 *
 * Adds "Maintenance loader" under the Monitoring menu.
 */
class Module extends CModule {

	public function init(): void {
		$menu = APP::Component()->get('menu.main');

		if ($menu === null) {
			return;
		}

		$monitoring = $menu->findOrAdd(_('Monitoring'));

		if ($monitoring === null) {
			return;
		}

		$monitoring
			->getSubmenu()
			->add((new CMenuItem(_('Maintenance loader')))
				->setAction('maintloader.view')
			);
	}
}
