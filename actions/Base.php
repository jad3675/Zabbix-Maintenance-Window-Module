<?php declare(strict_types = 1);

namespace Modules\MaintLoader\Actions;

use API;
use CController;
use CControllerResponseData;
use CWebUser;

require_once __DIR__.'/ZbxApi.php';
require_once __DIR__.'/Schedule.php';

abstract class Base extends CController {

	use Schedule;

	/** Words that look like a CSV header rather than a host. */
	private const HEADER_WORDS = [
		'host', 'hosts', 'hostname', 'host_name', 'name', 'visible_name', 'visiblename',
		'device', 'devices', 'ip', 'ipaddress', 'ip_address', 'address', 'dns', 'fqdn'
	];

	/** @var array|null */
	private static $config = null;

	/** @var ZbxApi|null */
	private static $api = null;

	/** @var array|null */
	private static $user_groups = null;

	protected function init(): void {
		// These actions are fetched by the page's own JS, not posted from a
		// rendered Zabbix form, so there is no framework-issued CSRF token to
		// present. Guarded because the method only exists on 6.4+.
		if (method_exists($this, 'disableCsrfValidation')) {
			$this->disableCsrfValidation();
		}
	}

	/*
	 * ----------------------------------------------------------------------
	 * Configuration
	 * ----------------------------------------------------------------------
	 */

	protected function config(string $key = null, $default = null) {
		if (self::$config === null) {
			$file = dirname(__DIR__).'/config.php';

			self::$config = is_readable($file) ? (array) include $file : [];
		}

		if ($key === null) {
			return self::$config;
		}

		return array_key_exists($key, self::$config) ? self::$config[$key] : $default;
	}

	protected function configError(): ?string {
		if (self::$config === null) {
			$this->config();
		}

		if (!self::$config) {
			return _('config.php is missing or unreadable. Copy config.php.example to config.php and fill it in.');
		}

		if (!$this->config('api_url')) {
			return _('config.php does not set api_url.');
		}

		if ($this->apiToken() === '') {
			return _('config.php does not set api_token (or token_file).');
		}

		return null;
	}

	private function apiToken(): string {
		$file = (string) $this->config('token_file', '');

		if ($file !== '' && is_readable($file)) {
			return trim((string) file_get_contents($file));
		}

		return trim((string) $this->config('api_token', ''));
	}

	protected function api(): ZbxApi {
		if (self::$api === null) {
			self::$api = new ZbxApi(
				(string) $this->config('api_url'),
				$this->apiToken(),
				(bool) $this->config('verify_tls', true),
				(int) $this->config('api_timeout', 15)
			);
		}

		return self::$api;
	}

	protected function namePrefix(): string {
		$prefix = trim((string) $this->config('name_prefix', '[ML]'));

		return $prefix === '' ? '[ML]' : $prefix;
	}

	/*
	 * ----------------------------------------------------------------------
	 * Permissions
	 * ----------------------------------------------------------------------
	 */

	protected function checkPermissions(): bool {
		if ($this->getUserType() < USER_TYPE_ZABBIX_USER) {
			return false;
		}

		$allowed = (array) $this->config('allowed_user_groups', []);

		if (!$allowed || $this->getUserType() == USER_TYPE_SUPER_ADMIN) {
			return true;
		}

		$mine = array_map('mb_strtolower', $this->userGroupNames());

		foreach ($allowed as $name) {
			if (in_array(mb_strtolower(trim((string) $name)), $mine, true)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * User group names for the logged-in user. Read through the service token
	 * because usergroup.get is not reliably available to a plain User.
	 */
	private function userGroupNames(): array {
		if (self::$user_groups !== null) {
			return self::$user_groups;
		}

		self::$user_groups = [];

		if ($this->configError() !== null) {
			return self::$user_groups;
		}

		try {
			$groups = $this->api()->call('usergroup.get', [
				'output' => ['name'],
				'userids' => [CWebUser::$data['userid']]
			]);

			foreach ((array) $groups as $group) {
				self::$user_groups[] = (string) $group['name'];
			}
		}
		catch (\Exception $e) {
			// Fail closed: no groups resolved means no allow-list match.
		}

		return self::$user_groups;
	}

	protected function userName(): string {
		$parts = array_filter([
			CWebUser::$data['name'] ?? '',
			CWebUser::$data['surname'] ?? ''
		]);

		$full = trim(implode(' ', $parts));
		$username = (string) (CWebUser::$data['username'] ?? CWebUser::$data['alias'] ?? 'unknown');

		return $full !== '' ? sprintf('%s (%s)', $full, $username) : $username;
	}

	/**
	 * Provenance block written into the maintenance description.
	 *
	 * Zabbix attributes the audit-log entry to the service account, not to the
	 * person who clicked, so this is the only trail back to a human. Keep the
	 * "Label: value" shape: MaintList parses it back out.
	 */
	protected function buildDescription(array $parts): string {
		$lines = [
			'Created via Maintenance Loader.',
			'Requested by: '.$this->userName(),
			'Created at:   '.date('Y-m-d H:i:s T'),
			'Hosts:        '.(int) ($parts['hosts'] ?? 0)
		];

		if (!empty($parts['schedule'])) {
			$lines[] = 'Schedule:     '.$parts['schedule'];
		}

		if (!empty($parts['ticket'])) {
			$lines[] = 'Ticket:       '.$parts['ticket'];
		}

		if (!empty($parts['history'])) {
			$lines[] = 'History:      '.$parts['history'];
		}

		if (!empty($parts['note'])) {
			$lines[] = '';
			$lines[] = $parts['note'];
		}

		return mb_substr(implode("\n", $lines), 0, 2000);
	}

	/*
	 * ----------------------------------------------------------------------
	 * Responses
	 * ----------------------------------------------------------------------
	 */

	protected function json(array $payload): void {
		$this->setResponse(new CControllerResponseData([
			'main_block' => json_encode($payload)
		]));
	}

	protected function jsonError(string $message): void {
		$this->json(['error' => $message]);
	}

	/*
	 * ----------------------------------------------------------------------
	 * Input parsing
	 * ----------------------------------------------------------------------
	 */

	/**
	 * Break a pasted blob or CSV into candidate tokens.
	 *
	 * Splits on newline, comma, semicolon and tab, which means a CSV row of
	 * "core-sw-01,10.20.30.1" simply yields two tokens that resolve to the
	 * same host. Deduplication happens on hostid later, so that is harmless
	 * and saves the user having to tell us which column is which.
	 *
	 * @return array [['raw' => string, 'header' => bool], ...]
	 */
	protected function parseTokens(string $blob): array {
		$parts = preg_split('/[\r\n,;\t]+/', $blob);
		$out = [];
		$seen = [];

		foreach ((array) $parts as $part) {
			$token = trim((string) $part);
			$token = trim($token, "\"'");
			$token = trim($token);

			if ($token === '') {
				continue;
			}

			$key = mb_strtolower($token);

			if (array_key_exists($key, $seen)) {
				continue;
			}

			$seen[$key] = true;

			$out[] = [
				'raw' => $token,
				'header' => in_array(preg_replace('/\s+/', '', $key), self::HEADER_WORDS, true)
			];
		}

		return $out;
	}

	/**
	 * Zabbix-style duration: 45s, 30m, 2h, 1d, 1w, or combinations (1h30m).
	 * A bare number is seconds.
	 *
	 * @return int|null seconds, or null if unparseable
	 */
	protected function parseDuration(string $text): ?int {
		$text = strtolower(trim($text));

		if ($text === '') {
			return null;
		}

		if (ctype_digit($text)) {
			return (int) $text;
		}

		if (!preg_match('/^(\d+[smhdw])+$/', $text)) {
			return null;
		}

		preg_match_all('/(\d+)([smhdw])/', $text, $matches, PREG_SET_ORDER);

		$units = ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800];
		$total = 0;

		foreach ($matches as $match) {
			$total += (int) $match[1] * $units[$match[2]];
		}

		return $total;
	}

	/*
	 * ----------------------------------------------------------------------
	 * Host resolution (always as the logged-in user)
	 * ----------------------------------------------------------------------
	 */

	/**
	 * Resolve tokens to hosts, matching technical name, then visible name,
	 * then interface IP, then interface DNS.
	 *
	 * Every lookup goes through the in-process API as the logged-in user, so
	 * a host the user cannot read simply does not resolve. This is the
	 * permission boundary for the whole module.
	 *
	 * @return array ['rows' => [...], 'hosts' => hostid => host]
	 */
	protected function resolveTokens(array $tokens): array {
		$lookup = [];

		foreach ($tokens as $token) {
			if (!$token['header']) {
				$lookup[] = $token['raw'];
			}
		}

		$hosts = [];
		$index = [];

		$remember = function (array $found, string $matched_on) use (&$hosts, &$index) {
			foreach ($found as $host) {
				$hosts[$host['hostid']] = $host;

				foreach (['host', 'name'] as $field) {
					if ($matched_on === $field) {
						$index[$field][mb_strtolower($host[$field])][] = $host['hostid'];
					}
				}
			}
		};

		$host_output = ['hostid', 'host', 'name', 'status', 'maintenance_status', 'maintenanceid'];

		if ($lookup) {
			// 1. Technical name, exact.
			$remember(API::Host()->get([
				'output' => $host_output,
				'selectInterfaces' => ['ip', 'dns'],
				'filter' => ['host' => $lookup],
				'preservekeys' => false
			]), 'host');

			// 2. Visible name, exact.
			$remember(API::Host()->get([
				'output' => $host_output,
				'selectInterfaces' => ['ip', 'dns'],
				'filter' => ['name' => $lookup],
				'preservekeys' => false
			]), 'name');

			// 3. Interface IP and DNS.
			$interfaces = API::HostInterface()->get([
				'output' => ['hostid', 'ip', 'dns'],
				'filter' => ['ip' => $lookup]
			]);

			$interfaces = array_merge($interfaces, API::HostInterface()->get([
				'output' => ['hostid', 'ip', 'dns'],
				'filter' => ['dns' => $lookup]
			]));

			$iface_hostids = [];

			foreach ($interfaces as $interface) {
				if ($interface['ip'] !== '') {
					$index['ip'][mb_strtolower($interface['ip'])][] = $interface['hostid'];
				}

				if ($interface['dns'] !== '') {
					$index['dns'][mb_strtolower($interface['dns'])][] = $interface['hostid'];
				}

				$iface_hostids[$interface['hostid']] = true;
			}

			$missing = array_diff(array_keys($iface_hostids), array_keys($hosts));

			if ($missing) {
				foreach (API::Host()->get([
					'output' => $host_output,
					'selectInterfaces' => ['ip', 'dns'],
					'hostids' => array_values($missing)
				]) as $host) {
					$hosts[$host['hostid']] = $host;
				}
			}
		}

		// 4. Case-insensitive second pass for whatever is still unmatched.
		// `filter` is an exact SQL match, which is case-sensitive on
		// PostgreSQL. People paste hostnames in whatever case their ticket
		// used, so fall back to a LIKE search and compare in PHP.
		$unmatched = [];

		foreach ($lookup as $raw) {
			$key = mb_strtolower($raw);

			if (!isset($index['host'][$key]) && !isset($index['name'][$key])
					&& !isset($index['ip'][$key]) && !isset($index['dns'][$key])) {
				$unmatched[] = $raw;
			}
		}

		if ($unmatched && count($unmatched) <= 500) {
			$found = API::Host()->get([
				'output' => $host_output,
				'selectInterfaces' => ['ip', 'dns'],
				'search' => ['host' => $unmatched, 'name' => $unmatched],
				'searchByAny' => true,
				'limit' => 5000
			]);

			$wanted = array_flip(array_map('mb_strtolower', $unmatched));

			foreach ($found as $host) {
				foreach (['host', 'name'] as $field) {
					$key = mb_strtolower($host[$field]);

					if (array_key_exists($key, $wanted)) {
						$hosts[$host['hostid']] = $host;
						$index[$field][$key][] = $host['hostid'];
					}
				}
			}
		}

		// Build the per-token result rows.
		$rows = [];

		foreach ($tokens as $token) {
			$raw = $token['raw'];

			if ($token['header']) {
				$rows[] = ['token' => $raw, 'status' => 'header'];
				continue;
			}

			$key = mb_strtolower($raw);
			$matched_on = null;
			$hostids = [];

			foreach (['host', 'name', 'ip', 'dns'] as $field) {
				if (isset($index[$field][$key])) {
					$matched_on = $field;
					$hostids = array_values(array_unique($index[$field][$key]));
					break;
				}
			}

			if (!$hostids) {
				$rows[] = ['token' => $raw, 'status' => 'notfound'];
				continue;
			}

			if (count($hostids) > 1) {
				$names = [];

				foreach ($hostids as $hostid) {
					$names[] = $hosts[$hostid]['host'];
				}

				$rows[] = [
					'token' => $raw,
					'status' => 'ambiguous',
					'matched_on' => $matched_on,
					'candidates' => $names
				];
				continue;
			}

			$host = $hosts[$hostids[0]];

			$rows[] = [
				'token' => $raw,
				'status' => 'ok',
				'matched_on' => $matched_on,
				'hostid' => $host['hostid'],
				'host' => $host['host'],
				'name' => $host['name'],
				'enabled' => ((int) $host['status'] === HOST_STATUS_MONITORED),
				'in_maintenance' => ((int) $host['maintenance_status'] === HOST_MAINTENANCE_STATUS_ON),
				'ip' => $this->firstIp($host)
			];
		}

		return ['rows' => $rows, 'hosts' => $hosts];
	}

	private function firstIp(array $host): string {
		foreach ((array) ($host['interfaces'] ?? []) as $interface) {
			if (($interface['ip'] ?? '') !== '') {
				return (string) $interface['ip'];
			}
		}

		foreach ((array) ($host['interfaces'] ?? []) as $interface) {
			if (($interface['dns'] ?? '') !== '') {
				return (string) $interface['dns'];
			}
		}

		return '';
	}

	/**
	 * Filter a list of hostids down to the ones the logged-in user can read.
	 * Never trust hostids that came back from the browser.
	 */
	protected function visibleHostids(array $hostids): array {
		if (!$hostids) {
			return [];
		}

		$hosts = API::Host()->get([
			'output' => ['hostid'],
			'hostids' => array_values(array_unique(array_map('strval', $hostids))),
			'preservekeys' => true
		]);

		return array_keys($hosts);
	}

	/**
	 * Full records for hostids, as the logged-in user. Anything they cannot
	 * read simply does not come back.
	 */
	protected function hostNames(array $hostids): array {
		if (!$hostids) {
			return [];
		}

		return API::Host()->get([
			'output' => ['hostid', 'host', 'name'],
			'hostids' => array_values(array_unique(array_map('strval', $hostids)))
		]);
	}

	/** Pull a "Label: value" line back out of a maintenance description. */
	protected function descriptionField(string $description, string $label): string {
		foreach (explode("\n", $description) as $line) {
			if (stripos($line, $label.':') === 0) {
				return trim(substr($line, strlen($label) + 1));
			}
		}

		return '';
	}
}
