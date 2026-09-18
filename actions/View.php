<?php declare(strict_types = 1);

namespace Modules\MaintWin\Actions;

use CControllerResponseData;
use CControllerResponseFatal;

/**
 * Serves both pages. The manifest points maintwin.view and maintwin.windows
 * at this one class; getAction() says which was asked for.
 */
class View extends Base {

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			// Set when arriving from the Edit button on the In flight page.
			'edit' => 'id'
		]);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	protected function doAction(): void {
		$page = ($this->getAction() === 'maintwin.windows') ? 'windows' : 'schedule';

		$config_error = $this->configError();
		$api_error = null;

		if ($config_error === null) {
			try {
				$this->api()->selfTest();
			}
			catch (\Exception $e) {
				$api_error = $e->getMessage();
			}
		}

		$data = [
			'page' => $page,
			'edit' => $this->hasInput('edit') ? (string) $this->getInput('edit') : '',
			'config_error' => $config_error,
			'api_error' => $api_error,
			'name_prefix' => $this->namePrefix(),
			'max_hosts' => (int) $this->config('max_hosts', 2000),
			'max_duration' => (int) $this->config('max_duration', 604800),
			'default_collect_data' => (int) $this->config('default_collect_data', 1),
			'user' => $this->userName()
		];

		$response = new CControllerResponseData($data);
		$response->setTitle($page === 'windows'
			? _('Maintenance windows in flight')
			: _('Schedule maintenance')
		);

		$this->setResponse($response);
	}
}
