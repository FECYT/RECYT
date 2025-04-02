<div id="customHeaderSettings">
	<div id="description">
		{translate key="plugins.generic.calidadfecyt.description"}
	</div>

	<div class="separator"></div>
	<br />

	<form id="exportForm" method="get" action="{$baseUrl|escape}">
		<input type="hidden" name="plugin" value="CalidadFECYTPlugin">
		<input type="hidden" name="category" value="generic">
		<input type="hidden" name="verb" id="verb" value="">
		<input type="hidden" name="exportIndex" id="exportIndex" value="">

		<fieldset class="pkpFormField pkpFormField--options">
			<legend>{translate key="plugins.generic.calidadfecyt.dateRange"}</legend>
			<label for="dateFrom">{translate key="plugins.generic.calidadfecyt.dateFrom"}</label>
			<input type="date" id="dateFrom" name="dateFrom" value="{$defaultDateFrom|date_format:'%Y-%m-%d'}"
				style="margin-right: 10px;">
			<label for="dateTo">{translate key="plugins.generic.calidadfecyt.dateTo"}</label>
			<input type="date" id="dateTo" name="dateTo" value="{$defaultDateTo|date_format:'%Y-%m-%d'}">
		</fieldset>

		<div class="separator"></div>
		<br />

		{if $exportAllAction}
			<p class="pkpHeader__title">
				<legend>{translate key="plugins.generic.calidadfecyt.export.all"}</legend>
			</p>
			<fieldset class="pkpFormField pkpFormField--options">
				<legend>
					{translate key="plugins.generic.calidadfecyt.exportAll.description"}
				</legend>
				<button id="exportAllButton" type="submit" class="pkpButton"
					onclick="document.getElementById('verb').value='exportAll';">
					{translate key="plugins.generic.calidadfecyt.exportAll"}
				</button>
			</fieldset>
		{/if}

		<div class="separator"></div>
		<br />

		<p class="pkpHeader__title">
			<legend>{translate key="plugins.generic.calidadfecyt.export.single"}</legend>
		</p>
		{if $linkActions}
			{foreach from=$linkActions item=exportAction}
				<fieldset class="pkpFormField pkpFormField--options">
					<legend>
						{translate key="plugins.generic.calidadfecyt.export."|cat:$exportAction->name|cat:".description"}
					</legend>
					<button id="{$exportAction->name|cat:'Button'}" type="submit" class="pkpButton"
						onclick="document.getElementById('verb').value='export'; document.getElementById('exportIndex').value='{$exportAction->index}';">
						{translate key="plugins.generic.calidadfecyt.exportAll"}
					</button>
				</fieldset>
			{/foreach}
		{/if}
		<div class="separator"></div>
		<br />

		<p class="pkpHeader__title">
			<legend>{translate key="plugins.generic.calidadfecyt.export.editorial"}</legend>
		</p>

		<fieldset class="pkpFormField pkpFormField--options">
			<legend>
				{translate key="plugins.generic.calidadfecyt.export.editorial.description"}
			</legend>
			<select name="submission" id="submission" style="width: 90%; margin-bottom: 10px">
				{foreach from=$submissions item=submission}
					<option value="{$submission['id']}">{$submission['id']} - {$submission['title']}</option>
				{/foreach}
			</select>
			<br />
			<button id="editorialButton" type="submit" class="pkpButton"
				onclick="document.getElementById('verb').value='editorial';">
				{translate key="plugins.generic.calidadfecyt.export.editorial"}
			</button>
		</fieldset>
	</form>
	<script>
		document.getElementById('exportForm').addEventListener('submit', function(e) {
			e.preventDefault();
			var form = this;
			var params = new URLSearchParams(new FormData(form)).toString();
			var url = form.action + '?' + params;
			window.location.href = url;
		});
	</script>
</div>