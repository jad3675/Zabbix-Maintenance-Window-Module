<?php declare(strict_types = 1);

namespace Modules\MaintWin\Actions;

/**
 * Minimal JSON-RPC client for api_jsonrpc.php, authenticated with a service
 * token rather than the logged-in user's session.
 *
 * This exists for exactly one reason: maintenance.create, maintenance.get and
 * maintenance.delete are Admin/Super admin only, and this module's whole job
 * is to let a plain User schedule maintenance. Everything that decides WHICH
 * hosts are in play is done through the in-process API as the logged-in user,
 * so this client never widens what a user can reach, only what they can do to
 * the hosts they already have.
 */
class ZbxApi {

	/** @var string */
	private $url;

	/** @var string */
	private $token;

	/** @var bool */
	private $verify_tls;

	/** @var int */
	private $timeout;

	/** @var string|null Cached apiinfo.version result. */
	private $version = null;

	/** @var int */
	private $request_id = 1;

	public function __construct(string $url, string $token, bool $verify_tls = true, int $timeout = 15) {
		$this->url = $url;
		$this->token = $token;
		$this->verify_tls = $verify_tls;
		$this->timeout = $timeout;
	}

	/**
	 * @throws \Exception on transport failure or a JSON-RPC error response.
	 */
	public function call(string $method, $params = []) {
		$payload = json_encode([
			'jsonrpc' => '2.0',
			'method' => $method,
			'params' => $params,
			'id' => $this->request_id++
		]);

		$headers = ['Content-Type: application/json-rpc'];

		// apiinfo.version must be called unauthenticated.
		if ($method !== 'apiinfo.version') {
			$headers[] = 'Authorization: Bearer '.$this->token;
		}

		$ch = curl_init($this->url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $payload,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_TIMEOUT => $this->timeout,
			CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
			CURLOPT_SSL_VERIFYPEER => $this->verify_tls,
			CURLOPT_SSL_VERIFYHOST => $this->verify_tls ? 2 : 0,
			CURLOPT_FOLLOWLOCATION => false
		]);

		$body = curl_exec($ch);
		$errno = curl_errno($ch);
		$err = curl_error($ch);
		$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

		// No curl_close(). Since PHP 8.0 the handle is an object freed by the
		// garbage collector, and the call was deprecated outright in 8.5.
		unset($ch);

		if ($errno !== 0) {
			throw new \Exception(sprintf('Cannot reach the Zabbix API at %s: %s', $this->url, $err));
		}

		if ($code !== 200) {
			throw new \Exception(sprintf('Zabbix API returned HTTP %d from %s.', $code, $this->url));
		}

		$decoded = json_decode((string) $body, true);

		if (!is_array($decoded)) {
			throw new \Exception('Zabbix API returned a response that is not JSON. Check that api_url points at api_jsonrpc.php.');
		}

		if (array_key_exists('error', $decoded)) {
			$e = $decoded['error'];
			throw new \Exception(trim(
				($e['message'] ?? 'API error').' '.($e['data'] ?? '')
			));
		}

		return $decoded['result'] ?? null;
	}

	/**
	 * Frontend version string, e.g. "7.4.2".
	 */
	public function version(): string {
		if ($this->version === null) {
			try {
				$this->version = (string) $this->call('apiinfo.version');
			}
			catch (\Exception $e) {
				// Assume modern if we cannot ask.
				$this->version = '7.0.0';
			}
		}

		return $this->version;
	}

	/**
	 * Both target lists for maintenance.create/update, in whichever shape the
	 * server speaks. Always sends BOTH keys, even when one is empty: on update,
	 * omitting a key leaves the old list in place, so switching a window from
	 * hosts to a group (or back) would otherwise keep the previous targets too.
	 */
	public function targetsParam(array $hostids, array $groupids): array {
		[$major] = array_pad(array_map('intval', explode('.', $this->version())), 1, 0);

		if ($major >= 7) {
			return [
				'hosts' => array_values(array_map(static function ($id) {
					return ['hostid' => (string) $id];
				}, $hostids)),
				'groups' => array_values(array_map(static function ($id) {
					return ['groupid' => (string) $id];
				}, $groupids))
			];
		}

		return [
			'hostids' => array_values(array_map('strval', $hostids)),
			'groupids' => array_values(array_map('strval', $groupids))
		];
	}

	/**
	 * maintenance.get option and result key for a window's host groups.
	 * selectHostGroups / hostgroups since 6.2; the old selectGroups was
	 * removed outright in 7.2.
	 *
	 * @return array [option name, result property]
	 */
	public function groupSelector(): array {
		[$major, $minor] = array_pad(array_map('intval', explode('.', $this->version())), 2, 0);

		return ($major > 6 || ($major === 6 && $minor >= 2))
			? ['selectHostGroups', 'hostgroups']
			: ['selectGroups', 'groups'];
	}

	/**
	 * Quick reachability/permission check for the settings banner.
	 */
	public function selfTest(): array {
		$version = $this->version();

		// Cheapest call that still proves the token has maintenance rights.
		$this->call('maintenance.get', ['output' => ['maintenanceid'], 'limit' => 1]);

		return ['ok' => true, 'version' => $version];
	}
}
