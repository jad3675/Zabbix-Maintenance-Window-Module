<?php declare(strict_types = 1);

namespace Modules\MaintLoader\Actions;


class Create extends Base {

	protected function checkInput(): bool {
		$ret = $this->validateInput(self::scheduleRules() + [
			'hostids' => 'required|array_id',
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

		// Never trust the hostids the browser hands back. Re-resolve them as
		// the logged-in user; anything they cannot read is dropped on the floor.
		$submitted = (array) $this->getInput('hostids');
		$hostids = $this->visibleHostids($submitted);

		if (!$hostids) {
			$this->jsonError(_('None of those hosts resolved. Verify the list again.'));
			return;
		}

		$dropped = count(array_unique($submitted)) - count($hostids);

		$max = (int) $this->config('max_hosts', 2000);

		if (count($hostids) > $max) {
			$this->jsonError(sprintf(_('%d hosts exceeds the configured limit of %d.'), count($hostids), $max));
			return;
		}

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
				'hosts' => count($hostids),
				'schedule' => $this->describeTimeperiod($schedule['timeperiod']),
				'ticket' => trim((string) $this->getInput('ticket', '')),
				'note' => trim((string) $this->getInput('note', ''))
			]),
			'maintenance_type' => $collect === 1 ? MAINTENANCE_TYPE_NORMAL : MAINTENANCE_TYPE_NODATA,
			'timeperiods' => [$schedule['timeperiod']]
		];

		$params += $this->api()->hostsParam($hostids);

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
			'message' => sprintf(
				_('%1$d hosts placed. %2$s'),
				count($hostids),
				$this->describeTimeperiod($schedule['timeperiod'])
			)
		]);
	}
}
