<?php declare(strict_types = 1);

namespace Modules\MaintLoader\Actions;

use CControllerResponseFatal;
use CWebUser;

class End extends Base {

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'maintenanceid' => 'required|id'
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

		$maintenanceid = (string) $this->getInput('maintenanceid');

		try {
			$found = $this->api()->call('maintenance.get', [
				'output' => ['maintenanceid', 'name', 'description'],
				'selectHosts' => ['hostid'],
				'maintenanceids' => [$maintenanceid]
			]);
		}
		catch (\Exception $e) {
			$this->jsonError(_('Could not read that maintenance window: ').$e->getMessage());
			return;
		}

		if (!$found) {
			$this->jsonError(_('That maintenance window no longer exists.'));
			return;
		}

		$maintenance = $found[0];

		// Guard 1: the module only ever deletes its own windows. Without this
		// check, an elevated token plus a guessable ID lets any user with page
		// access wipe out a scheduled change window somebody set up by hand.
		if (strpos((string) $maintenance['name'], $this->namePrefix()) !== 0) {
			$this->jsonError(_('That maintenance window was not created here, so this page will not remove it.'));
			return;
		}

		// Guard 2: the user must be able to see at least one host in it.
		$hostids = [];

		foreach ((array) $maintenance['hosts'] as $host) {
			$hostids[] = $host['hostid'];
		}

		if (!$this->visibleHostids($hostids)) {
			$this->jsonError(_('You do not have permission to any of the hosts in that window.'));
			return;
		}

		// Guard 3: optional "only your own windows".
		if ($this->config('own_windows_only', false) && $this->getUserType() != USER_TYPE_SUPER_ADMIN) {
			$username = (string) (CWebUser::$data['username'] ?? CWebUser::$data['alias'] ?? '');

			if ($username === '' || strpos((string) $maintenance['description'], '('.$username.')') === false) {
				$this->jsonError(_('That window was created by someone else.'));
				return;
			}
		}

		try {
			$this->api()->call('maintenance.delete', [$maintenanceid]);
		}
		catch (\Exception $e) {
			$this->jsonError(_('Zabbix refused the delete: ').$e->getMessage());
			return;
		}

		$this->json([
			'ok' => true,
			'message' => sprintf(_('Ended "%s". Hosts leave maintenance within a minute.'), $maintenance['name'])
		]);
	}
}
