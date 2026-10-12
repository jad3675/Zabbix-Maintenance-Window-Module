<?php declare(strict_types = 1);

namespace Modules\MaintWin\Actions;

use API;

/**
 * The second way in. Instead of pasting identifiers, pick hosts the way the
 * rest of Zabbix does: host groups, hosts, tags. Returns exactly the shape
 * Verify returns, so step 2, step 3, create and edit need no idea which path
 * produced the list.
 *
 * Runs host.get as the logged-in user, like Verify. A group the user cannot
 * see yields nothing; same permission boundary, no special casing.
 */
class Select extends Base {

	private const VALID_OPERATORS = [
		TAG_OPERATOR_LIKE, TAG_OPERATOR_EQUAL, TAG_OPERATOR_NOT_LIKE,
		TAG_OPERATOR_NOT_EQUAL, TAG_OPERATOR_EXISTS, TAG_OPERATOR_NOT_EXISTS
	];

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'groupids' => 'array_id',
			'hostids' => 'array_id',
			// Tag rows come in as one JSON string rather than nested arrays.
			// One fewer validator to trust, and the rows are checked by hand
			// below anyway.
			'tags_json' => 'string',
			'evaltype' => 'in '.TAG_EVAL_TYPE_AND_OR.','.TAG_EVAL_TYPE_OR,
			'include_disabled' => 'in 0,1'
		]);

		if (!$ret) {
			$this->jsonInvalidInput();
		}

		return $ret;
	}

	protected function doAction(): void {
		$groupids = array_values(array_unique(array_map('strval', (array) $this->getInput('groupids', []))));
		$hostids = array_values(array_unique(array_map('strval', (array) $this->getInput('hostids', []))));
		$tags = $this->parseTags((string) $this->getInput('tags_json', ''));

		if ($tags === null) {
			$this->jsonError(_('One of the tag rows is not valid.'));
			return;
		}

		if (!$groupids && !$hostids && !$tags) {
			$this->jsonError(_('Pick at least one host group, host or tag.'));
			return;
		}

		$max = (int) $this->config('max_hosts', 2000);

		$options = [
			'output' => ['hostid', 'host', 'name', 'status', 'maintenance_status', 'maintenanceid'],
			'selectInterfaces' => ['ip', 'dns', 'main'],
			'sortfield' => 'name',
			// One past the cap, so "too many" is a definite answer rather
			// than a list that happens to end at the limit.
			'limit' => $max + 1
		];

		if ($groupids) {
			$options['groupids'] = $groupids;
		}

		if ($hostids) {
			$options['hostids'] = $hostids;
		}

		if ($tags) {
			$options['tags'] = $tags;
			$options['evaltype'] = (int) $this->getInput('evaltype', TAG_EVAL_TYPE_AND_OR);
		}

		// A pasted list is explicit, so Verify keeps disabled hosts and flags
		// them. A group selection is a sweep, and sweeping in every disabled
		// box in a group is almost never what anyone meant. Opt in instead.
		if ((int) $this->getInput('include_disabled', 0) !== 1) {
			$options['filter'] = ['status' => HOST_STATUS_MONITORED];
		}

		try {
			$hosts = API::Host()->get($options);
		}
		catch (\Exception $e) {
			$this->jsonError(_('Host lookup failed: ').$e->getMessage());
			return;
		}

		if (count($hosts) > $max) {
			$this->jsonError(sprintf(
				_('That selection matches more than %d hosts, which is the configured limit. Narrow it down.'),
				$max
			));
			return;
		}

		$rows = [];
		$resolved = [];
		$counts = ['ok' => 0, 'notfound' => 0, 'ambiguous' => 0, 'duplicate' => 0,
			'header' => 0, 'disabled' => 0, 'already' => 0, 'conflict' => 0
		];

		foreach ($hosts as $host) {
			$row = $this->describeHost($host);
			$row['token'] = $row['name'] !== '' ? $row['name'] : $row['host'];
			$row['status'] = 'ok';
			$row['matched_on'] = 'selected';
			$row['column'] = 1;
			$row['columns'] = 1;
			$row['matched_value'] = $row['token'];
			$row['conflicts'] = [];

			$rows[] = $row;
			$resolved[] = $row['hostid'];
			$counts['ok']++;

			if (!$row['enabled']) {
				$counts['disabled']++;
			}

			if ($row['in_maintenance']) {
				$counts['already']++;
			}
		}

		// Groups and nothing else is the one selection a maintenance window can
		// target directly, and then the server keeps it current: a host added
		// to the group later is covered, a host removed drops out. Narrowed by
		// hosts or tags it becomes an intersection, which a maintenance object
		// cannot express (its hosts and groups are a union), so those stay a
		// snapshot.
		$dynamic_ok = $groupids && !$hostids && !$tags;

		$groups = [];

		if ($dynamic_ok) {
			foreach ($this->groupNames($groupids) as $group) {
				$groups[] = ['groupid' => (string) $group['groupid'], 'name' => (string) $group['name']];
			}

			// A group the user cannot read came back as nothing. Do not offer
			// to target what they cannot see.
			$dynamic_ok = count($groups) === count($groupids);
		}

		$this->json([
			'rows' => $rows,
			'counts' => $counts,
			'hostids' => $resolved,
			'source' => 'select',
			'dynamic_ok' => $dynamic_ok,
			'groups' => $groups,
			'snapshot_reason' => $dynamic_ok ? '' : ($groupids
				? _('Narrowed by hosts or tags, so these hosts are fixed as they are now. Only a selection of host groups alone can stay current.')
				: _('A selection of hosts or tags is fixed as it is now. Only host groups can stay current automatically.'))
		]);
	}

	/**
	 * @return array|null  host.get-ready tag rows, [] for none, null if a row is bad
	 */
	private function parseTags(string $json): ?array {
		$json = trim($json);

		if ($json === '' || $json === '[]') {
			return [];
		}

		$decoded = json_decode($json, true);

		if (!is_array($decoded)) {
			return null;
		}

		$out = [];

		foreach ($decoded as $row) {
			if (!is_array($row)) {
				return null;
			}

			$tag = trim((string) ($row['tag'] ?? ''));
			$operator = (int) ($row['operator'] ?? TAG_OPERATOR_LIKE);
			$value = (string) ($row['value'] ?? '');

			// Blank rows are the ones the operator added and never filled in.
			if ($tag === '') {
				continue;
			}

			if (!in_array($operator, self::VALID_OPERATORS, true)) {
				return null;
			}

			$out[] = ['tag' => $tag, 'operator' => $operator, 'value' => $value];
		}

		return $out;
	}
}
