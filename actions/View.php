<?php declare(strict_types = 1);

namespace Modules\MaintLoader\Actions;

use CControllerResponseData;
use CControllerResponseFatal;

class View extends Base {

	protected function checkInput(): bool {
		$ret = $this->validateInput([]);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	protected function doAction(): void {
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
			'config_error' => $config_error,
			'api_error' => $api_error,
			'name_prefix' => $this->namePrefix(),
			'max_hosts' => (int) $this->config('max_hosts', 2000),
			'max_duration' => (int) $this->config('max_duration', 604800),
			'default_collect_data' => (int) $this->config('default_collect_data', 1),
			'user' => $this->userName()
		];

		$response = new CControllerResponseData($data);
		$response->setTitle(_('Maintenance loader'));

		$this->setResponse($response);
	}
}
