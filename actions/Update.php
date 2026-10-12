<?php declare(strict_types = 1);

namespace Modules\MaintWin\Actions;

use CWebUser;

/**
 * Three operations on an existing window:
 *
 *   load    read it back in the shape the form posts
 *   extend  push the end out by a delta (one-time windows only)
 *   edit    full rewrite of name, schedule, collection mode and host list
 */
class Update extends Base {

	protected function checkInput(): bool {
		// buildSchedule() needs sched and duration, but only op=edit calls it.
		// load and extend post neither, so the required flag scheduleRules()
		// sets has to come back off. Assign over the keys rather than using
		// array union: `+` keeps the LEFT operand on a key collision, so
		// scheduleRules() would win and every load would fail validation.
		$rules = self::scheduleRules();
		$rules['sched'] = 'in onetime,daily,weekly,monthly';
		$rules['duration'] = 'string';

		$rules += [
			'op' => 'required|in load,extend,edit',
			'maintenanceid' => 'required|id',

			// extend
			'delta' => 'string',

			// edit
			'hostids' => 'array_id',
			'groupids' => 'array_id',
			'target' => 'in hosts,group',
			'window_name' => 'string',
			'ticket' => 'string',
			'note' => 'string'
		];

		$ret = $this->validateInput($rules);

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

		$maintenanceid = (string) $this->getInput('maintenanceid');

		try {
			$maintenance = $this->loadOwn($maintenanceid);
		}
		catch (\Exception $e) {
			$this->jsonError($e->getMessage());
			return;
		}

		switch ($this->getInput('op')) {
			case 'load':
				$this->opLoad($maintenance);
				break;

			case 'extend':
				$this->opExtend($maintenance);
				break;

			case 'edit':
				$this->opEdit($maintenance);
				break;
		}
	}

	/**
	 * Fetch a window and prove the caller is entitled to touch it.
	 *
	 * Same three guards as End: it has to be one of ours by name prefix, the
	 * caller has to be able to see at least one host in it, and optionally it
	 * has to be theirs. The service token would happily rewrite any window in
	 * the system, so this is the only thing standing between a guessable
	 * maintenanceid and somebody else's change window.
	 *
	 * @throws \Exception
	 */
	private function loadOwn(string $maintenanceid): array {
		$found = $this->fetchWindows([
			'output' => ['maintenanceid', 'name', 'description', 'maintenance_type',
				'active_since', 'active_till'
			],
			'selectTimeperiods' => 'extend',
			'maintenanceids' => [$maintenanceid]
		]);

		if (!$found) {
			throw new \Exception(_('That maintenance window no longer exists.'));
		}

		$maintenance = $found[0];

		if (!$this->isOwnWindow((string) $maintenance['name'])) {
			throw new \Exception(_('That window was not created here, so this page will not change it.'));
		}

		if (!$this->canSeeWindow($maintenance)) {
			throw new \Exception(_('You do not have permission to any of the hosts or host groups in that window.'));
		}

		if ($this->config('own_windows_only', false) && $this->getUserType() != USER_TYPE_SUPER_ADMIN) {
			$username = (string) (CWebUser::$data['username'] ?? CWebUser::$data['alias'] ?? '');

			if ($username === '' || strpos((string) $maintenance['description'], '('.$username.')') === false) {
				throw new \Exception(_('That window was created by someone else.'));
			}
		}

		return $maintenance;
	}

	/*
	 * ----------------------------------------------------------------------
	 */

	private function opLoad(array $maintenance): void {
		$timeperiods = (array) $maintenance['timeperiods'];

		if (count($timeperiods) !== 1) {
			// Only windows this module built are reachable here, and it always
			// writes exactly one period. A window with several has been edited
			// by hand in the native UI, and rewriting it from this form would
			// silently throw the other periods away.
			$this->jsonError(_('That window has more than one time period. Edit it in Data collection -> Maintenance instead.'));
			return;
		}

		$form = $this->scheduleToForm(
			$timeperiods[0],
			(int) $maintenance['active_since'],
			(int) $maintenance['active_till']
		);

		$hostids = array_map('strval', array_column($maintenance['hosts'], 'hostid'));
		$groupids = array_map('strval', array_column($maintenance['groups'], 'groupid'));

		// This module writes either a host list or a group target, never both.
		// A window carrying both was edited in the native UI, and this form
		// has no way to show it without flattening one half into the other.
		if ($hostids && $groupids) {
			$this->jsonError(_('That window targets both hosts and host groups. Edit it in Data collection -> Maintenance instead.'));
			return;
		}

		$visible = $this->visibleHostids($hostids);
		$visible_groups = $this->visibleGroupids($groupids);

		$hosts = [];

		foreach ($this->hostNames($visible) as $host) {
			$hosts[] = $host['host'];
		}

		sort($hosts, SORT_NATURAL | SORT_FLAG_CASE);

		$groups = [];

		foreach ($this->groupNames($visible_groups) as $group) {
			$groups[] = ['id' => (string) $group['groupid'], 'name' => (string) $group['name']];
		}

		$this->json([
			'ok' => true,
			'maintenanceid' => (string) $maintenance['maintenanceid'],
			'name' => (string) $maintenance['name'],
			'label' => $this->stripName((string) $maintenance['name']),
			'collect' => ((int) $maintenance['maintenance_type'] === MAINTENANCE_TYPE_NORMAL) ? 1 : 0,
			'ticket' => $this->descriptionField((string) $maintenance['description'], 'Ticket'),
			'note' => $this->descriptionNote((string) $maintenance['description']),
			'form' => $form,
			'target' => $groupids ? 'group' : 'hosts',
			'hosts' => $hosts,
			'hidden_count' => count($hostids) - count($visible),
			// Shaped for Zabbix's multiselect addData(): {id, name}.
			'groups' => $groups,
			'hidden_groups' => count($groupids) - count($visible_groups)
		]);
	}

	private function opExtend(array $maintenance): void {
		$timeperiods = (array) $maintenance['timeperiods'];

		if (count($timeperiods) !== 1
				|| (int) $timeperiods[0]['timeperiod_type'] !== TIMEPERIOD_TYPE_ONETIME) {
			$this->jsonError(_('Only one-time windows can be extended. Edit a recurring one to change its range.'));
			return;
		}

		$delta = $this->parseDuration((string) $this->getInput('delta', ''));

		if ($delta === null || $delta < 60) {
			$this->jsonError(_('Could not read how long to extend by.'));
			return;
		}

		$period = (int) $timeperiods[0]['period'] + $delta;
		$max_duration = (int) $this->config('max_duration', 31622400);

		if ($period > $max_duration) {
			$this->jsonError(sprintf(
				_('That would make the window %s, past the configured maximum of %s.'),
				$this->humanDuration($period),
				$this->humanDuration($max_duration)
			));
			return;
		}

		$timeperiod = [
			'timeperiod_type' => TIMEPERIOD_TYPE_ONETIME,
			'start_date' => (int) $timeperiods[0]['start_date'],
			'period' => $period
		];

		// active_till has to move with the period or the envelope cuts the
		// window short and the extension does nothing.
		$active_till = (int) $maintenance['active_till'] + $delta;

		try {
			$this->api()->call('maintenance.update', [
				'maintenanceid' => (string) $maintenance['maintenanceid'],
				'active_till' => $active_till,
				'timeperiods' => [$timeperiod],
				'description' => $this->appendHistory(
					(string) $maintenance['description'],
					sprintf('extended by %s by %s on %s',
						$this->humanDuration($delta), $this->userName(), date('Y-m-d H:i')
					)
				)
			]);
		}
		catch (\Exception $e) {
			$this->jsonError(_('Zabbix refused the change: ').$e->getMessage());
			return;
		}

		$this->json([
			'ok' => true,
			'message' => sprintf(_('Extended by %s. Now runs until %s.'),
				$this->humanDuration($delta), date('Y-m-d H:i', $active_till)
			)
		]);
	}

	private function opEdit(array $maintenance): void {
		if (!$this->hasInput('sched') || !$this->hasInput('duration')) {
			$this->jsonError(_('The form did not send a schedule. Reload the page and try again.'));
			return;
		}

		try {
			$schedule = $this->buildSchedule();
		}
		catch (\Exception $e) {
			$this->jsonError($e->getMessage());
			return;
		}

		// Targets. One rule for both lists: whatever this user cannot see is
		// kept exactly as it was, and the visible half is replaced with what
		// they submitted. Filtering the existing lists through the visibility
		// check would quietly evict hosts and groups that belong to somebody
		// else's permission scope.
		//
		// The submitted side is a host list OR a group target, never both, so
		// switching a window from one to the other on edit clears the visible
		// part of whichever it was before.
		$existing_hosts = array_map('strval', array_column($maintenance['hosts'], 'hostid'));
		$existing_groups = array_map('strval', array_column($maintenance['groups'], 'groupid'));

		$locked_hosts = array_values(array_diff($existing_hosts, $this->visibleHostids($existing_hosts)));
		$locked_groups = array_values(array_diff($existing_groups, $this->visibleGroupids($existing_groups)));

		$new_hosts = [];
		$new_groups = [];
		$dropped = 0;

		if ($this->getInput('target', 'hosts') === 'group') {
			$posted = (array) $this->getInput('groupids', []);
			$new_groups = $this->visibleGroupids($posted);
			$dropped = count(array_unique($posted)) - count($new_groups);
		}
		else {
			$posted = (array) $this->getInput('hostids', []);
			$new_hosts = $this->visibleHostids($posted);
			$dropped = count(array_unique($posted)) - count($new_hosts);
		}

		$hostids = array_values(array_unique(array_merge($locked_hosts, $new_hosts)));
		$groupids = array_values(array_unique(array_merge($locked_groups, $new_groups)));

		if (!$hostids && !$groupids) {
			$this->jsonError(_('A maintenance window needs at least one host or host group. End it instead.'));
			return;
		}

		$max = (int) $this->config('max_hosts', 2000);

		if (count($hostids) > $max) {
			$this->jsonError(sprintf(_('%d hosts exceeds the configured limit of %d.'), count($hostids), $max));
			return;
		}

		$label = trim((string) $this->getInput('window_name', ''));

		if ($label === '') {
			$label = $this->stripName((string) $maintenance['name']);
		}

		$collect = (int) $this->getInput('collect', 1);

		// Rebuild the name so the prefix and the start stay in step with the
		// schedule, but keep the original random suffix so the window is still
		// recognisable in the audit log across edits.
		$suffix = preg_match('/\s([0-9A-F]{4})$/', (string) $maintenance['name'], $m)
			? $m[1]
			: strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));

		$name = mb_substr(sprintf(
			'%s %s %s %s',
			$this->namePrefix(),
			$label,
			date('Y-m-d H:i', $schedule['active_since']),
			$suffix
		), 0, 128);

		$description = $this->buildDescription([
			'hosts' => count($hostids) + $this->memberCount($groupids),
			'groups' => $groupids ? $this->groupLabel($groupids) : '',
			'schedule' => $this->describeTimeperiod($schedule['timeperiod']),
			'ticket' => trim((string) $this->getInput('ticket', '')),
			'note' => trim((string) $this->getInput('note', '')),
			'history' => $this->carryHistory((string) $maintenance['description'])
		]);

		$description = $this->appendHistory($description, sprintf(
			'edited by %s on %s', $this->userName(), date('Y-m-d H:i')
		));

		$params = [
			'maintenanceid' => (string) $maintenance['maintenanceid'],
			'name' => $name,
			'active_since' => $schedule['active_since'],
			'active_till' => $schedule['active_till'],
			'description' => $description,
			'maintenance_type' => $collect === 1 ? MAINTENANCE_TYPE_NORMAL : MAINTENANCE_TYPE_NODATA,
			'timeperiods' => [$schedule['timeperiod']]
		];

		$params += $this->api()->targetsParam($hostids, $groupids);

		try {
			$this->api()->call('maintenance.update', $params);
		}
		catch (\Exception $e) {
			$this->jsonError(_('Zabbix refused the change: ').$e->getMessage());
			return;
		}

		$this->json([
			'ok' => true,
			'maintenanceid' => (string) $maintenance['maintenanceid'],
			'name' => $name,
			'hosts' => count($hostids),
			'dropped' => max(0, $dropped),
			'message' => $groupids
				? sprintf(_('Saved. Targets %1$s, which stays current as the group changes. %2$s'),
					$this->groupLabel($groupids), $this->describeTimeperiod($schedule['timeperiod']))
				: sprintf(_('Saved. %1$d hosts, %2$s'),
					count($hostids), $this->describeTimeperiod($schedule['timeperiod']))
		]);
	}

	/*
	 * ----------------------------------------------------------------------
	 */

	/** Strip the prefix and the trailing " YYYY-MM-DD HH:MM ABCD" back off. */
	private function stripName(string $name): string {
		$prefix = $this->namePrefix();

		if (strpos($name, $prefix) === 0) {
			$name = substr($name, strlen($prefix));
		}

		return trim(preg_replace('/\s+\d{4}-\d{2}-\d{2} \d{2}:\d{2}(\s+[0-9A-F]{4})?$/', '', trim($name)));
	}

	/** Everything after the blank line that separates the header from the note. */
	private function descriptionNote(string $description): string {
		$parts = explode("\n\n", $description, 2);

		return count($parts) === 2 ? trim($parts[1]) : '';
	}

	private function carryHistory(string $description): string {
		return $this->descriptionField($description, 'History');
	}

	private function appendHistory(string $description, string $entry): string {
		$lines = explode("\n", $description);
		$done = false;

		foreach ($lines as &$line) {
			if (stripos($line, 'History:') === 0) {
				$line = rtrim($line).'; '.$entry;
				$done = true;
				break;
			}
		}
		unset($line);

		if (!$done) {
			array_splice($lines, 1, 0, ['History:      '.$entry]);
		}

		return mb_substr(implode("\n", $lines), 0, 2000);
	}
}
