<?php declare(strict_types = 1);

namespace Modules\MaintWin\Actions;

use API;

/**
 * Every host currently in maintenance, regardless of who put it there.
 *
 * Deliberately NOT built from maintenance.get. That would only show windows
 * this module created, and the whole point of this page is to catch the ones
 * it did not: an admin scheduling by hand in Data collection, a window with a
 * host group target that swept in hosts nobody listed individually, a
 * recurring window somebody set up two years ago and forgot.
 *
 * So the query starts from hosts, filtered on maintenance_status, which Zabbix
 * maintains itself. Anything suppressed shows up here. It also means the list
 * is permission-scoped for free: host.get runs as the logged-in user.
 */
class InMaint extends Base {

	protected function checkInput(): bool {
		$ret = $this->validateInput([]);

		if (!$ret) {
			$this->jsonInvalidInput();
		}

		return $ret;
	}

	protected function doAction(): void {
		try {
			$hosts = API::Host()->get([
				'output' => ['hostid', 'host', 'name', 'status', 'maintenanceid',
					'maintenance_type', 'maintenance_from'
				],
				'selectInterfaces' => ['ip', 'dns', 'type', 'main'],
				'selectTags' => ['tag', 'value'],
				'filter' => ['maintenance_status' => HOST_MAINTENANCE_STATUS_ON]
			]);
		}
		catch (\Exception $e) {
			$this->jsonError(_('Could not read hosts: ').$e->getMessage());
			return;
		}

		$windows = $this->windowDetails($hosts);
		$prefix = $this->namePrefix();
		$rows = [];

		foreach ($hosts as $host) {
			$maintenanceid = (string) $host['maintenanceid'];
			$window = $windows[$maintenanceid] ?? null;

			$tags = [];

			foreach ((array) $host['tags'] as $tag) {
				$tags[] = ($tag['value'] !== '')
					? $tag['tag'].': '.$tag['value']
					: $tag['tag'];
			}

			sort($tags, SORT_NATURAL | SORT_FLAG_CASE);

			$name = (string) ($window['name'] ?? '');

			$rows[] = [
				'hostid' => (string) $host['hostid'],
				'name' => (string) $host['name'],
				'host' => (string) $host['host'],
				'ip' => $this->firstIp($host),
				'tags' => $tags,
				// maintenance_type on the HOST is the mode of the window
				// currently suppressing it, which is what an operator wants to
				// know: is this box still being polled or has it gone dark?
				'collect' => ((int) $host['maintenance_type'] === MAINTENANCE_TYPE_NORMAL),
				'enabled' => ((int) $host['status'] === HOST_STATUS_MONITORED),
				'since' => (int) $host['maintenance_from'],
				'since_text' => $host['maintenance_from']
					? date('Y-m-d H:i', (int) $host['maintenance_from'])
					: '',
				'maintenanceid' => $maintenanceid,
				'window' => $name !== '' ? $name : _('(not visible)'),
				'until' => (int) ($window['active_till'] ?? 0),
				'until_text' => isset($window['active_till'])
					? date('Y-m-d H:i', (int) $window['active_till'])
					: '',
				'source' => ($name !== '' && strpos($name, $prefix) === 0) ? 'module' : 'external'
			];
		}

		usort($rows, static function (array $a, array $b): int {
			return strnatcasecmp($a['name'], $b['name']);
		});

		$this->json([
			'rows' => $rows,
			'prefix' => $prefix,
			'now' => time()
		]);
	}

	/**
	 * Names and end times for the windows involved.
	 *
	 * maintenance.get is Admin-only, so this goes through the service token.
	 * That is safe here: the ids come from hosts the user could already see,
	 * and nothing is returned for a window they have no host in. If the token
	 * is broken the page still renders, just without window names, which beats
	 * failing outright on a read-only screen.
	 */
	private function windowDetails(array $hosts): array {
		$ids = [];

		foreach ($hosts as $host) {
			if ($host['maintenanceid'] > 0) {
				$ids[(string) $host['maintenanceid']] = true;
			}
		}

		if (!$ids || $this->configError() !== null) {
			return [];
		}

		try {
			$found = $this->api()->call('maintenance.get', [
				'output' => ['maintenanceid', 'name', 'active_till'],
				'maintenanceids' => array_keys($ids)
			]);
		}
		catch (\Exception $e) {
			return [];
		}

		$out = [];

		foreach ((array) $found as $window) {
			$out[(string) $window['maintenanceid']] = $window;
		}

		return $out;
	}
}
