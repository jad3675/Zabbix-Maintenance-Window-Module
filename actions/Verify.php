<?php declare(strict_types = 1);

namespace Modules\MaintLoader\Actions;

use CControllerResponseFatal;

class Verify extends Base {

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'hosts' => 'required|string'
		]);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	protected function doAction(): void {
		$rows = $this->parseRows($this->getInput('hosts'));

		if (!$rows) {
			$this->jsonError(_('Nothing to verify. Paste a host list or upload a CSV.'));
			return;
		}

		$max = (int) $this->config('max_hosts', 2000);

		if (count($rows) > $max) {
			$this->jsonError(sprintf(
				_('That is %d rows. The limit is %d. Split it into smaller batches.'),
				count($rows), $max
			));
			return;
		}

		try {
			$resolved = $this->resolveRows($rows);
		}
		catch (\Exception $e) {
			$this->jsonError(_('Host lookup failed: ').$e->getMessage());
			return;
		}

		$rows = $resolved['rows'];

		// Collapse rows that landed on the same host.
		$seen_hostids = [];

		foreach ($rows as &$row) {
			if ($row['status'] !== 'ok') {
				continue;
			}

			if (array_key_exists($row['hostid'], $seen_hostids)) {
				$row['status'] = 'duplicate';
				$row['duplicate_of'] = $seen_hostids[$row['hostid']];
			}
			else {
				$seen_hostids[$row['hostid']] = $row['token'];
			}
		}
		unset($row);

		$counts = [
			'ok' => 0,
			'notfound' => 0,
			'ambiguous' => 0,
			'duplicate' => 0,
			'header' => 0,
			'disabled' => 0,
			'already' => 0,
			'conflict' => 0
		];

		$hostids = [];

		foreach ($rows as $row) {
			$counts[$row['status']] = ($counts[$row['status']] ?? 0) + 1;

			if ($row['status'] === 'ok') {
				$hostids[] = $row['hostid'];

				if (!$row['enabled']) {
					$counts['disabled']++;
				}

				if ($row['in_maintenance']) {
					$counts['already']++;
				}

				if (!empty($row['conflicts'])) {
					$counts['conflict']++;
				}
			}
		}

		$this->json([
			'rows' => $rows,
			'counts' => $counts,
			'hostids' => $hostids
		]);
	}
}
