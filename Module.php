<?php declare(strict_types = 1);

namespace Modules\MaintWin;

use APP;
use CMenu;
use CMenuItem;
use Zabbix\Core\CModule;

/**
 * Maintenance Windows.
 *
 * Adds a "Maintenance Windows" entry under Monitoring with two children:
 * the scheduling form and the list of windows already placed. Two real pages
 * rather than tabs inside one, so each has its own URL, its own menu
 * highlight, and can be bookmarked or sent to a colleague.
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
			->add((new CMenuItem(_('Maintenance Windows')))
				->setSubMenu(new CMenu([
					(new CMenuItem(_('Schedule')))->setAction('maintwin.view'),
					(new CMenuItem(_('In flight')))->setAction('maintwin.windows'),
					(new CMenuItem(_('Devices in Maintenance')))->setAction('maintwin.devices')
				]))
			);
	}
}
