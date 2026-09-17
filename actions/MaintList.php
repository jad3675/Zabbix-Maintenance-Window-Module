<?php declare(strict_types = 1);

namespace Modules\MaintLoader\Actions;

use CControllerResponseFatal;

class MaintList extends Base {

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'show_expired' => 'in 0,1'
		]);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	protected function doAction(): void {
		$error = $this->configError();

		if ($error !== null) {
			$this->jsonError($error);
			return;
		}

		$show_expired = (int) $this->getInput('show_expired', 0) === 1;

		try {
			$maintenances = $this->api()->call('maintenance.get', [
				'output' => ['maintenanceid', 'name', 'maintenance_type', 'description',
					'active_since', 'active_till'
				],
				'selectHosts' => ['hostid', 'host', 'name'],
				'selectTimeperiods' => 'extend',
				'search' => ['name' => $this->namePrefix()],
				'startSearch' => true
			]);
		}
		catch (\Exception $e) {
			$this->jsonError(_('Could not read maintenance windows: ').$e->getMessage());
			return;
		}

		// The service token sees everything. Trim each window down to the
		// hosts this user can actually read, and hide windows where that
		// leaves nothing. A user should not learn about hosts through this
		// page that they cannot see anywhere else in the frontend.
		$all_hostids = [];

		foreach ((array) $maintenances as $maintenance) {
			foreach ((array) $maintenance['hosts'] as $host) {
				$all_hostids[$host['hostid']] = true;
			}
		}

		$visible = array_flip($this->visibleHostids(array_keys($all_hostids)));

		$now = time();
		$rows = [];

		foreach ((array) $maintenances as $maintenance) {
			$mine = [];
			$hidden = 0;

			foreach ((array) $maintenance['hosts'] as $host) {
				if (array_key_exists($host['hostid'], $visible)) {
					$mine[] = $host['name'] !== '' ? $host['name'] : $host['host'];
				}
				else {
					$hidden++;
				}
			}

			if (!$mine) {
				continue;
			}

			$since = (int) $maintenance['active_since'];
			$till = (int) $maintenance['active_till'];

			if ($now < $since) {
				$state = 'scheduled';
			}
			elseif ($now < $till) {
				$state = 'active';
			}
			else {
				$state = 'expired';
			}

			if ($state === 'expired' && !$show_expired) {
				continue;
			}

			sort($mine, SORT_NATURAL | SORT_FLAG_CASE);

			$timeperiods = (array) $maintenance['timeperiods'];
			$single = (count($timeperiods) === 1);
			$onetime = $single
				&& (int) $timeperiods[0]['timeperiod_type'] === TIMEPERIOD_TYPE_ONETIME;

			$rows[] = [
				'maintenanceid' => (string) $maintenance['maintenanceid'],
				'name' => (string) $maintenance['name'],
				'state' => $state,
				// A window with several periods was edited by hand in the
				// native UI. Show it, but do not offer to rewrite it from a
				// form that can only express one period.
				'editable' => $single,
				'onetime' => $onetime,
				'schedule' => $single
					? $this->describeTimeperiod($timeperiods[0])
					: sprintf(_('%d time periods (edit in Data collection)'), count($timeperiods)),
				'collect' => ((int) $maintenance['maintenance_type'] === MAINTENANCE_TYPE_NORMAL),
				'active_since' => $since,
				'active_till' => $till,
				'since_text' => date('Y-m-d H:i', $since),
				'till_text' => date('Y-m-d H:i', $till),
				'host_count' => count($mine) + $hidden,
				'hidden_count' => $hidden,
				'hosts' => array_slice($mine, 0, 200),
				'truncated' => count($mine) > 200,
				'created_by' => $this->creator((string) $maintenance['description']),
				'ticket' => $this->descriptionField((string) $maintenance['description'], 'Ticket'),
				'description' => (string) $maintenance['description']
			];
		}

		usort($rows, static function (array $a, array $b): int {
			$order = ['active' => 0, 'scheduled' => 1, 'expired' => 2];

			if ($order[$a['state']] !== $order[$b['state']]) {
				return $order[$a['state']] <=> $order[$b['state']];
			}

			return $b['active_since'] <=> $a['active_since'];
		});

		$this->json(['rows' => $rows, 'now' => $now]);
	}

	private function creator(string $description): string {
		return $this->descriptionField($description, 'Requested by');
	}

}
