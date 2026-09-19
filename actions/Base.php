<?php declare(strict_types = 1);

namespace Modules\MaintWin\Actions;

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

	protected function config(?string $key = null, $default = null) {
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
		$prefix = trim((string) $this->config('name_prefix', '[MW]'));

		return $prefix === '' ? '[MW]' : $prefix;
	}

	/** Did this module create the window? The guard on every write path. */
	protected function isOwnWindow(string $name): bool {
		return strpos($name, $this->namePrefix()) === 0;
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
			'Created via Maintenance Windows.',
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

	/**
	 * Validation failure on an AJAX action.
	 *
	 * The default CControllerResponseFatal renders a full HTML error page,
	 * which a fetch() expecting JSON can only report as a wall of markup.
	 * Answer in the shape the caller asked for instead.
	 */
	protected function jsonInvalidInput(): void {
		$this->json(['error' => _('The request was rejected as malformed. This is a bug in the module, not something you did wrong.')]);
	}

	/*
	 * ----------------------------------------------------------------------
	 * Input parsing
	 * ----------------------------------------------------------------------
	 */

	/**
	 * Break a pasted blob or CSV into rows.
	 *
	 * ONE LINE IS ONE ENTRY. Newlines separate rows; commas, semicolons and
	 * tabs separate columns within a row. A row of "core-sw-01,10.20.30.1" is
	 * a single host described two ways, not two hosts, so resolution walks the
	 * columns left to right and stops at the first one that hits.
	 *
	 * @return array [['raw' => string, 'fields' => [string, ...]], ...]
	 */
	protected function parseRows(string $blob): array {
		$out = [];

		foreach (preg_split('/\r\n|\r|\n/', $blob) as $line) {
			$raw = trim((string) $line);

			if ($raw === '') {
				continue;
			}

			$fields = [];

			foreach (preg_split('/[,;\t]+/', $raw) as $field) {
				$field = trim(trim(trim((string) $field), "\"'"));

				if ($field !== '') {
					$fields[] = $field;
				}
			}

			if ($fields) {
				// Identical rows are kept, not silently merged. The hostid
				// pass flags the second one as a duplicate, which is more
				// honest than quietly changing the operator's row count.
				$out[] = ['raw' => $raw, 'fields' => $fields];
			}
		}

		return $out;
	}

	private function looksLikeHeader(string $field): bool {
		return in_array(
			preg_replace('/\s+/', '', mb_strtolower($field)), self::HEADER_WORDS, true
		);
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
	 * Resolve rows to hosts.
	 *
	 * Two nested orderings, and they are not the same thing:
	 *
	 *   outer, by column   the operator's column order wins. Column 1 is the
	 *                      authoritative identifier; later columns are only
	 *                      consulted when the earlier ones miss.
	 *   inner, by kind     within one column value, technical name beats
	 *                      visible name beats interface IP beats interface DNS.
	 *
	 * Every lookup goes through the in-process API as the logged-in user, so
	 * a host the user cannot read simply does not resolve. This is the
	 * permission boundary for the whole module.
	 *
	 * @return array ['rows' => [...], 'hosts' => hostid => host]
	 */
	protected function resolveRows(array $rows): array {
		// Every distinct column value across every row, looked up in bulk.
		$lookup = [];

		foreach ($rows as $row) {
			foreach ($row['fields'] as $field) {
				$lookup[mb_strtolower($field)] = $field;
			}
		}

		$lookup = array_values($lookup);

		$hosts = [];
		$index = [];

		$remember = function (array $found, string $matched_on) use (&$hosts, &$index) {
			foreach ($found as $host) {
				$hosts[$host['hostid']] = $host;
				$index[$matched_on][mb_strtolower($host[$matched_on])][] = $host['hostid'];
			}
		};

		$host_output = ['hostid', 'host', 'name', 'status', 'maintenance_status', 'maintenanceid'];

		if ($lookup) {
			// 1. Technical name, exact.
			$remember(API::Host()->get([
				'output' => $host_output,
				'selectInterfaces' => ['ip', 'dns'],
				'filter' => ['host' => $lookup]
			]), 'host');

			// 2. Visible name, exact.
			$remember(API::Host()->get([
				'output' => $host_output,
				'selectInterfaces' => ['ip', 'dns'],
				'filter' => ['name' => $lookup]
			]), 'name');

			// 3. Interface IP and DNS.
			$interfaces = array_merge(
				API::HostInterface()->get([
					'output' => ['hostid', 'ip', 'dns'],
					'filter' => ['ip' => $lookup]
				]),
				API::HostInterface()->get([
					'output' => ['hostid', 'ip', 'dns'],
					'filter' => ['dns' => $lookup]
				])
			);

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

		foreach ($lookup as $value) {
			if (!$this->indexHit($index, $value)) {
				$unmatched[] = $value;
			}
		}

		if ($unmatched && count($unmatched) <= 500) {
			$wanted = array_flip(array_map('mb_strtolower', $unmatched));

			foreach (API::Host()->get([
				'output' => $host_output,
				'selectInterfaces' => ['ip', 'dns'],
				'search' => ['host' => $unmatched, 'name' => $unmatched],
				'searchByAny' => true,
				'limit' => 5000
			]) as $host) {
				foreach (['host', 'name'] as $field) {
					$key = mb_strtolower($host[$field]);

					if (array_key_exists($key, $wanted)) {
						$hosts[$host['hostid']] = $host;
						$index[$field][$key][] = $host['hostid'];
					}
				}
			}
		}

		return ['rows' => $this->judgeRows($rows, $index, $hosts), 'hosts' => $hosts];
	}

	/** First index entry for a column value, honouring the kind priority. */
	private function indexHit(array $index, string $value): ?array {
		$key = mb_strtolower($value);

		foreach (['host', 'name', 'ip', 'dns'] as $kind) {
			if (isset($index[$kind][$key])) {
				return [
					'matched_on' => $kind,
					'hostids' => array_values(array_unique($index[$kind][$key]))
				];
			}
		}

		return null;
	}

	/** Turn the index into one verdict per row. */
	private function judgeRows(array $rows, array $index, array $hosts): array {
		$out = [];

		foreach ($rows as $row) {
			$winner = null;
			$winning_col = 0;
			$conflicts = [];

			foreach ($row['fields'] as $i => $field) {
				$hit = $this->indexHit($index, $field);

				if ($hit === null) {
					continue;
				}

				if ($winner === null) {
					$winner = $hit;
					$winner['field'] = $field;
					$winning_col = $i + 1;
					continue;
				}

				// A later column resolving to a different host means the CSV
				// has drifted from reality. Column order still decides, but
				// say so rather than picking silently.
				if (count($winner['hostids']) === 1 && count($hit['hostids']) === 1
						&& $hit['hostids'][0] !== $winner['hostids'][0]) {
					$conflicts[] = sprintf(_('column %1$d (%2$s) points at %3$s'),
						$i + 1, $field, $hosts[$hit['hostids'][0]]['host']
					);
				}
			}

			if ($winner === null) {
				// Only call it a header once we know nothing in it resolved,
				// so a host legitimately named "device" is not skipped.
				$out[] = [
					'token' => $row['raw'],
					'status' => $this->looksLikeHeader($row['fields'][0]) ? 'header' : 'notfound'
				];
				continue;
			}

			if (count($winner['hostids']) > 1) {
				$names = [];

				foreach ($winner['hostids'] as $hostid) {
					$names[] = $hosts[$hostid]['host'];
				}

				$out[] = [
					'token' => $row['raw'],
					'status' => 'ambiguous',
					'column' => $winning_col,
					'matched_on' => $winner['matched_on'],
					'candidates' => $names
				];
				continue;
			}

			$host = $hosts[$winner['hostids'][0]];

			$out[] = [
				'token' => $row['raw'],
				'status' => 'ok',
				'column' => $winning_col,
				'columns' => count($row['fields']),
				'matched_on' => $winner['matched_on'],
				'matched_value' => $winner['field'],
				'conflicts' => $conflicts,
				'hostid' => $host['hostid'],
				'host' => $host['host'],
				'name' => $host['name'],
				'enabled' => ((int) $host['status'] === HOST_STATUS_MONITORED),
				'in_maintenance' => ((int) $host['maintenance_status'] === HOST_MAINTENANCE_STATUS_ON),
				'ip' => $this->firstIp($host)
			];
		}

		return $out;
	}

	protected function firstIp(array $host): string {
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
