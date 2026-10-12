<?php declare(strict_types = 1);

namespace Modules\MaintWin\Actions;


class Create extends Base {

	protected function checkInput(): bool {
		$ret = $this->validateInput(self::scheduleRules() + [
			// Not required any more: a group target carries no hostids.
			'hostids' => 'array_id',
			'groupids' => 'array_id',
			'target' => 'in hosts,group',
			'window_name' => 'string',
			'ticket' => 'string',
			'note' => 'string'
		]);

		if (!$ret) {
			$this->jsonInvalidInput();
		}

		return $ret;
	}

	protected function doAction(): void {
		$error = $this->configError();

		if ($error !== null) {
			$this->jsonError($error);
			return;
		}

		$targets = $this->resolveTargets();

		if (isset($targets['error'])) {
			$this->jsonError($targets['error']);
			return;
		}

		$hostids = $targets['hostids'];
		$groupids = $targets['groupids'];
		$dropped = $targets['dropped'];

		try {
			$schedule = $this->buildSchedule();
		}
		catch (\Exception $e) {
			$this->jsonError($e->getMessage());
			return;
		}

		$label = trim((string) $this->getInput('window_name', ''));

		if ($label === '') {
			$label = $this->getInput('sched') === 'onetime'
				? _('Ad-hoc maintenance')
				: _('Recurring maintenance');
		}

		$collect = (int) $this->getInput('collect', $this->config('default_collect_data', 1));

		// Maintenance names must be unique. Stamp the start and a short random
		// suffix so two people scheduling "Patching" at the same moment do not
		// collide on the constraint.
		$name = mb_substr(sprintf(
			'%s %s %s %s',
			$this->namePrefix(),
			$label,
			date('Y-m-d H:i', $schedule['active_since']),
			strtoupper(substr(bin2hex(random_bytes(3)), 0, 4))
		), 0, 128);

		$params = [
			'name' => $name,
			'active_since' => $schedule['active_since'],
			'active_till' => $schedule['active_till'],
			'description' => $this->buildDescription([
				'hosts' => $groupids ? $this->memberCount($groupids) : count($hostids),
				'groups' => $groupids ? $this->groupLabel($groupids) : '',
				'schedule' => $this->describeTimeperiod($schedule['timeperiod']),
				'ticket' => trim((string) $this->getInput('ticket', '')),
				'note' => trim((string) $this->getInput('note', ''))
			]),
			'maintenance_type' => $collect === 1 ? MAINTENANCE_TYPE_NORMAL : MAINTENANCE_TYPE_NODATA,
			'timeperiods' => [$schedule['timeperiod']]
		];

		$params += $this->api()->targetsParam($hostids, $groupids);

		try {
			$result = $this->api()->call('maintenance.create', $params);
		}
		catch (\Exception $e) {
			$this->jsonError(_('Zabbix rejected the maintenance: ').$e->getMessage());
			return;
		}

		$this->json([
			'ok' => true,
			'maintenanceid' => (string) (($result['maintenanceids'][0]) ?? ''),
			'name' => $name,
			'hosts' => count($hostids),
			'dropped' => max(0, $dropped),
			'message' => $groupids
				? sprintf(_('Placed on %1$s, which stays current as the group changes. %2$s'),
					$this->groupLabel($groupids), $this->describeTimeperiod($schedule['timeperiod']))
				: sprintf(_('%1$d hosts placed. %2$s'),
					count($hostids), $this->describeTimeperiod($schedule['timeperiod']))
		]);
	}
}
