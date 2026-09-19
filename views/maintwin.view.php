<?php declare(strict_types = 1);

/**
 * Maintenance Windows view.
 *
 * @var CView $this
 * @var array $data
 */

$boot = json_encode([
	'page' => $data['page'],
	'edit' => $data['edit'],
	'schedule_url' => 'zabbix.php?action=maintwin.view',
	'windows_url' => 'zabbix.php?action=maintwin.windows',
	'name_prefix' => $data['name_prefix'],
	'max_hosts' => $data['max_hosts'],
	'max_duration' => $data['max_duration'],
	'default_collect_data' => $data['default_collect_data'],
	'user' => $data['user'],
	'blocked' => ($data['config_error'] !== null || $data['api_error'] !== null)
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

$e = static function ($value): string {
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

?>
<div class="ml-page">
	<h1 class="ml-title"><?= $e([
		'windows' => _('Windows in flight'),
		'devices' => _('Devices in maintenance')
	][$data['page']] ?? _('Schedule maintenance')) ?></h1>

<?php if ($data['config_error'] !== null): ?>
	<div class="ml-banner ml-banner-bad">
		<strong><?= $e(_('Not configured.')) ?></strong>
		<?= $e($data['config_error']) ?>
	</div>
<?php elseif ($data['api_error'] !== null): ?>
	<div class="ml-banner ml-banner-bad">
		<strong><?= $e(_('Service token is not working.')) ?></strong>
		<?= $e($data['api_error']) ?>
	</div>
<?php endif; ?>


	<!-- ============================ SCHEDULE =========================== -->
<?php if ($data['page'] === 'schedule'): ?>
	<section id="ml-panel-new" class="ml-panel">

		<div class="ml-step">
			<h2 class="ml-step-title"><span class="ml-step-num">1</span><?= $e(_('Paste the hosts')) ?></h2>
			<p class="ml-hint"><?= $e(_('One host per line. Columns separated by comma, semicolon or tab are alternative identifiers for that same host: column 1 is tried first, then column 2, and so on until one matches. Each column is matched against technical name, visible name, interface IP and interface DNS in that order.')) ?></p>

			<textarea id="ml-input" class="ml-textarea" spellcheck="false" autocapitalize="off" autocorrect="off"
				placeholder="core-sw-01,10.20.30.41&#10;core-sw-02,10.20.30.42&#10;esx-host-07.corp.local&#10;10.20.30.9"></textarea>

			<div class="ml-row">
				<button type="button" id="ml-verify" class="ml-btn ml-btn-primary"><?= $e(_('Verify')) ?></button>
				<label class="ml-file">
					<input type="file" id="ml-file" accept=".csv,.txt,text/csv,text/plain">
					<span><?= $e(_('Load a file')) ?></span>
				</label>
				<button type="button" id="ml-clear" class="ml-btn ml-btn-plain"><?= $e(_('Clear')) ?></button>
				<span id="ml-input-count" class="ml-muted"></span>
			</div>
		</div>

		<div class="ml-step ml-hidden" id="ml-step-verify">
			<h2 class="ml-step-title"><span class="ml-step-num">2</span><?= $e(_('Check the matches')) ?></h2>
			<div id="ml-summary" class="ml-summary"></div>
			<div class="ml-row ml-filter-row">
				<label class="ml-check"><input type="checkbox" id="ml-only-problems"> <?= $e(_('Only show entries that need attention')) ?></label>
				<button type="button" id="ml-copy-missing" class="ml-btn ml-btn-plain ml-hidden"><?= $e(_('Copy unmatched rows')) ?></button>
			</div>
			<div class="ml-table-wrap">
				<table class="ml-table" id="ml-results">
					<thead>
						<tr>
							<th class="ml-col-status"></th>
							<th><?= $e(_('Row')) ?></th>
							<th><?= $e(_('Matched on')) ?></th>
							<th><?= $e(_('Host')) ?></th>
							<th><?= $e(_('Address')) ?></th>
							<th><?= $e(_('Note')) ?></th>
						</tr>
					</thead>
					<tbody></tbody>
				</table>
			</div>
		</div>

		<div class="ml-step ml-hidden" id="ml-step-window">
			<h2 class="ml-step-title"><span class="ml-step-num">3</span><span id="ml-step3-label"><?= $e(_('Set the window')) ?></span></h2>

			<div id="ml-editing" class="ml-editing ml-hidden">
				<span><?= $e(_('Editing')) ?> <b id="ml-editing-name" class="ml-mono"></b></span>
				<button type="button" id="ml-cancel-edit" class="ml-btn ml-btn-plain"><?= $e(_('Cancel edit')) ?></button>
			</div>

			<div class="ml-form">
				<div class="ml-field">
					<label for="ml-name"><?= $e(_('Label')) ?></label>
					<input type="text" id="ml-name" maxlength="80" placeholder="<?= $e(_('Switch stack firmware')) ?>">
				</div>

				<div class="ml-field">
					<label for="ml-ticket"><?= $e(_('Ticket')) ?></label>
					<input type="text" id="ml-ticket" maxlength="64" placeholder="CHG-12345">
				</div>

				<div class="ml-field">
					<label for="ml-sched"><?= $e(_('Repeats')) ?></label>
					<select id="ml-sched">
						<option value="onetime" selected><?= $e(_('One time only')) ?></option>
						<option value="daily"><?= $e(_('Daily')) ?></option>
						<option value="weekly"><?= $e(_('Weekly')) ?></option>
						<option value="monthly"><?= $e(_('Monthly')) ?></option>
					</select>
				</div>

				<div class="ml-field ml-sched-onetime">
					<label><?= $e(_('Starts')) ?></label>
					<div class="ml-seg" id="ml-start-mode">
						<button type="button" class="ml-seg-btn ml-seg-active" data-mode="now"><?= $e(_('Now')) ?></button>
						<button type="button" class="ml-seg-btn" data-mode="at"><?= $e(_('Scheduled')) ?></button>
					</div>
					<input type="datetime-local" id="ml-start-at" class="ml-hidden">
				</div>

				<div class="ml-field ml-sched-recurring ml-hidden">
					<label for="ml-start-time"><?= $e(_('Starts at')) ?></label>
					<input type="time" id="ml-start-time" value="02:00">
				</div>

				<div class="ml-field ml-sched-daily ml-sched-weekly ml-hidden">
					<label for="ml-every"><?= $e(_('Repeat every')) ?></label>
					<div class="ml-inline">
						<input type="number" id="ml-every" min="1" max="999" value="1" class="ml-num">
						<span id="ml-every-unit" class="ml-muted"><?= $e(_('day(s)')) ?></span>
					</div>
				</div>

				<div class="ml-field ml-field-wide ml-sched-monthly ml-hidden">
					<label><?= $e(_('Months')) ?></label>
					<div class="ml-checkrow" id="ml-months"></div>
				</div>

				<div class="ml-field ml-field-wide ml-sched-monthly ml-hidden">
					<label><?= $e(_('On')) ?></label>
					<div class="ml-inline">
						<div class="ml-seg" id="ml-monthly-mode">
							<button type="button" class="ml-seg-btn ml-seg-active" data-mmode="day"><?= $e(_('Day of month')) ?></button>
							<button type="button" class="ml-seg-btn" data-mmode="dow"><?= $e(_('Day of week')) ?></button>
						</div>
						<input type="number" id="ml-day" min="1" max="31" value="1" class="ml-num ml-monthly-day">
						<select id="ml-week" class="ml-hidden ml-monthly-dow">
							<option value="1"><?= $e(_('first')) ?></option>
							<option value="2"><?= $e(_('second')) ?></option>
							<option value="3"><?= $e(_('third')) ?></option>
							<option value="4"><?= $e(_('fourth')) ?></option>
							<option value="5"><?= $e(_('last')) ?></option>
						</select>
					</div>
				</div>

				<div class="ml-field ml-field-wide ml-dow-block ml-hidden">
					<label><?= $e(_('Days of the week')) ?></label>
					<div class="ml-checkrow" id="ml-dow"></div>
				</div>

				<div class="ml-field ml-field-wide ml-sched-recurring ml-hidden">
					<label><?= $e(_('Recurrence runs between')) ?></label>
					<div class="ml-inline">
						<input type="date" id="ml-active-from">
						<span class="ml-muted">&rarr;</span>
						<input type="date" id="ml-active-to">
					</div>
					<p class="ml-hint ml-hint-inline"><?= $e(_('Outside this range the window never fires. Zabbix keeps expired windows forever, so an end date is not optional in practice.')) ?></p>
				</div>

				<div class="ml-field">
					<label for="ml-duration"><?= $e(_('Lasts')) ?></label>
					<select id="ml-duration">
						<option value="15m">15 minutes</option>
						<option value="30m">30 minutes</option>
						<option value="1h" selected>1 hour</option>
						<option value="2h">2 hours</option>
						<option value="4h">4 hours</option>
						<option value="8h">8 hours</option>
						<option value="12h">12 hours</option>
						<option value="1d">24 hours</option>
						<option value="2d">2 days</option>
						<option value="3d">3 days</option>
						<option value="1w">1 week</option>
						<option value="2w">2 weeks</option>
						<option value="30d">30 days</option>
						<option value="90d">90 days</option>
						<option value="180d">180 days</option>
						<option value="365d">365 days</option>
						<option value="custom"><?= $e(_('Custom...')) ?></option>
					</select>
					<input type="text" id="ml-duration-custom" class="ml-hidden" placeholder="1h30m" maxlength="20">
				</div>

				<div class="ml-field">
					<label><?= $e(_('Data collection')) ?></label>
					<div class="ml-seg" id="ml-collect">
						<button type="button" class="ml-seg-btn<?= $data['default_collect_data'] ? ' ml-seg-active' : '' ?>" data-collect="1"><?= $e(_('Keep collecting')) ?></button>
						<button type="button" class="ml-seg-btn<?= $data['default_collect_data'] ? '' : ' ml-seg-active' ?>" data-collect="0"><?= $e(_('No data')) ?></button>
					</div>
					<p class="ml-hint ml-hint-inline" id="ml-collect-hint"></p>
				</div>

				<div class="ml-field ml-field-wide">
					<label for="ml-note"><?= $e(_('Note')) ?></label>
					<textarea id="ml-note" rows="2" maxlength="1000" placeholder="<?= $e(_('Anything the next person on shift should know.')) ?>"></textarea>
				</div>
			</div>

			<div class="ml-confirm">
				<div id="ml-preview" class="ml-preview"></div>
				<button type="button" id="ml-create" class="ml-btn ml-btn-go"><?= $e(_('Put them in maintenance')) ?></button>
			</div>

			<div id="ml-create-result"></div>
		</div>
	</section>
<?php elseif ($data['page'] === 'windows'): ?>

	<!-- ============================ IN FLIGHT ========================== -->
	<section id="ml-panel-active" class="ml-panel">
		<div class="ml-row ml-filter-row">
			<button type="button" id="ml-refresh" class="ml-btn ml-btn-plain"><?= $e(_('Refresh')) ?></button>
			<label class="ml-check"><input type="checkbox" id="ml-show-expired"> <?= $e(_('Include expired')) ?></label>
			<span class="ml-muted"><?= $e(sprintf(_('Only windows created here (name starts with %s) are listed and only those can be ended here.'), $data['name_prefix'])) ?></span>
		</div>
		<div id="ml-active-list" class="ml-active-list"></div>
	</section>
<?php else: ?>

	<!-- ========================= DEVICES IN MAINT ====================== -->
	<section id="ml-panel-devices" class="ml-panel">
		<p class="ml-hint"><?= $e(_('Every host Zabbix currently has suppressed, whoever put it there. Built from host status rather than from this module\'s own records, so windows scheduled by hand in Data collection, and hosts swept in by a host-group target, show up too. Limited to hosts you have permission to see.')) ?></p>

		<div class="ml-row ml-filter-row">
			<button type="button" id="ml-dev-refresh" class="ml-btn ml-btn-plain"><?= $e(_('Refresh')) ?></button>
			<input type="search" id="ml-dev-filter" class="ml-search" placeholder="<?= $e(_('Filter by host, IP or tag')) ?>">
			<label class="ml-check"><input type="checkbox" id="ml-dev-external"> <?= $e(_('Only windows not created here')) ?></label>
			<button type="button" id="ml-dev-csv" class="ml-btn"><?= $e(_('Export CSV')) ?></button>
			<span id="ml-dev-count" class="ml-muted"></span>
		</div>

		<div id="ml-dev-message"></div>

		<div class="ml-table-wrap ml-table-tall">
			<table class="ml-table ml-table-sortable" id="ml-dev-table">
				<thead>
					<tr>
						<th data-sort="name"><?= $e(_('Host')) ?></th>
						<th data-sort="ip"><?= $e(_('IP')) ?></th>
						<th data-sort="tags"><?= $e(_('Tags')) ?></th>
						<th data-sort="collect"><?= $e(_('Collection')) ?></th>
						<th data-sort="window"><?= $e(_('Window')) ?></th>
						<th data-sort="since"><?= $e(_('Since')) ?></th>
						<th data-sort="until"><?= $e(_('Until')) ?></th>
						<th data-sort="source"><?= $e(_('Source')) ?></th>
					</tr>
				</thead>
				<tbody></tbody>
			</table>
		</div>
	</section>
<?php endif; ?>
</div>

<script type="text/javascript">
	window.MAINTWIN = <?= $boot ?>;
</script>
