/* Hub builder: edit rows in place, attach content spreadsheets, then create row by row. */
(function ($) {
	'use strict';

	var busy = false;

	function redraw(data) {
		$('.hw-builder__rows').html(data.rows);
		$('.hw-builder__summary').html(data.summary);
		$('.hw-new-form').remove();
		refreshGo();
	}

	function refreshGo() {
		var n = $('.hw-row[data-go="1"]').length;
		$('.hw-builder-go').prop('disabled', !n || busy).text(n ? 'Create ' + n + ' draft page' + (n === 1 ? '' : 's') : 'Nothing ready to create');
	}

	function counts($scope) {
		$scope.find('.hw-count').each(function () {
			var $c = $(this), max = +$c.data('max');
			var len = $c.parent().find('input,textarea').val().length;
			$c.text(len + ' / ' + max).toggleClass('hw-warn', len > max).toggleClass('hw-muted', len <= max);
		});
	}

	function send(fd, $msg) {
		fd.append('action', 'hw_builder_row');
		fd.append('nonce', hwBuilder.nonce);
		if ($msg) { $msg.removeClass('hw-err').text('Checking…'); }
		return $.ajax({ url: hwBuilder.ajax, type: 'POST', data: fd, processData: false, contentType: false })
			.done(function (r) {
				if (r && r.success) { redraw(r.data); } else if ($msg) { $msg.addClass('hw-err').text(r && r.data ? r.data.message : 'Something went wrong.'); }
			})
			.fail(function () { if ($msg) { $msg.addClass('hw-err').text('The server didn\'t answer. Try again.'); } });
	}

	function collect($form, index) {
		var fd = new FormData();
		fd.append('op', 'save');
		fd.append('index', index);
		$form.find('[data-field]').each(function () {
			var key = $(this).data('field');
			if (key === 'content') {
				if (this.files && this.files[0]) { fd.append('content', this.files[0]); }
			} else {
				fd.append('fields[' + key + ']', $(this).val());
			}
		});
		return fd;
	}

	// Edit / cancel.
	$(document).on('click', '.hw-builder-edit', function () {
		var i = $(this).closest('.hw-row').data('index');
		var $edit = $('.hw-edit[data-index="' + i + '"]');
		$edit.prop('hidden', !$edit.prop('hidden'));
		counts($edit);
		$edit.find('input[type=text]').first().trigger('focus');
	});
	$(document).on('click', '.hw-builder-cancel', function () {
		var $edit = $(this).closest('.hw-edit');
		if ($edit.length) { $edit.prop('hidden', true); } else { $(this).closest('.hw-new-form').remove(); }
	});
	$(document).on('input', '.hw-form input, .hw-form textarea', function () { counts($(this).closest('.hw-form')); });

	// Save (and attach).
	$(document).on('click', '.hw-builder-save', function () {
		var $wrap = $(this).closest('.hw-edit, .hw-new-form');
		var index = $wrap.hasClass('hw-new-form') ? 'new' : $wrap.data('index');
		send(collect($wrap.find('.hw-form'), index), $wrap.find('.hw-form__msg'));
	});
	$(document).on('keydown', '.hw-form input[type=text]', function (e) {
		if (e.key === 'Enter') { e.preventDefault(); $(this).closest('.hw-form').find('.hw-builder-save').trigger('click'); }
	});
	$(document).on('click', '.hw-builder-detach', function () {
		var $wrap = $(this).closest('.hw-edit');
		var fd = collect($wrap.find('.hw-form'), $wrap.data('index'));
		fd.append('detach', '1');
		send(fd, $wrap.find('.hw-form__msg'));
	});

	// Create anyway.
	$(document).on('change', '.hw-builder-force', function () {
		var fd = new FormData();
		fd.append('op', 'force');
		if (this.checked) { fd.append('on', '1'); }
		send(fd);
	});

	// Remove.
	$(document).on('click', '.hw-builder-remove', function () {
		var $row = $(this).closest('.hw-row');
		if (!window.confirm('Remove this row from the batch? The spreadsheet itself is not changed.')) { return; }
		var fd = new FormData();
		fd.append('op', 'remove');
		fd.append('index', $row.data('index'));
		send(fd);
	});

	// Add a row.
	$(document).on('click', '.hw-builder-add', function () {
		if ($('.hw-new-form').length) { $('.hw-new-form input').first().trigger('focus'); return; }
		$.post(hwBuilder.ajax, { action: 'hw_builder_blank', nonce: hwBuilder.nonce }).done(function (r) {
			if (!r || !r.success) { return; }
			var $tr = $('<tr class="hw-new-form"><td colspan="6"><h3 style="margin:4px 0 8px">New row</h3></td></tr>');
			$tr.find('td').append(r.data.form);
			$('.hw-builder__rows').append($tr);
			counts($tr);
			$tr.find('input[type=text]').first().trigger('focus');
		});
	});

	// Create, one row at a time.
	$(document).on('click', '.hw-builder-go', function () {
		// Parents first, so a page always has its parent page to sit under.
		var $rows = $($('.hw-row[data-go="1"]').get().sort(function (a, b) { return $(a).data('depth') - $(b).data('depth'); }));
		var total = $rows.length, done = 0, failed = 0;
		if (!total) { return; }
		busy = true;
		refreshGo();
		$('.hw-builder-edit, .hw-builder-remove, .hw-builder-add, .hw-builder-force').prop('disabled', true);
		$('.hw-edit').prop('hidden', true);
		$(window).on('beforeunload.hwb', function () { return 'Pages are still being created.'; });

		function progress() {
			$('.hw-builder__progress').text('Creating… ' + done + ' of ' + total + (failed ? ' (' + failed + ' failed)' : ''));
		}

		function next(i) {
			if (i >= total) {
				$(window).off('beforeunload.hwb');
				busy = false;
				// Redraw from the server so every row shows its final state.
				var fd = new FormData();
				fd.append('op', 'refresh');
				send(fd).always(function () {
					$('.hw-builder__progress').text('Done: ' + (done - failed) + ' created' + (failed ? ', ' + failed + ' failed (see Status, fix and click Create again)' : '') + '. All are drafts.');
				});
				return;
			}
			var $row = $rows.eq(i);
			$row.find('.hw-status').html('<span class="spinner is-active" style="float:none;margin:0"></span>');
			$.post(hwBuilder.ajax, { action: 'hw_builder_create', nonce: hwBuilder.nonce, index: $row.data('index') })
				.done(function (r) { if (!r || !r.success) { failed++; } })
				.fail(function () { failed++; })
				.always(function () { done++; progress(); next(i + 1); });
		}

		progress();
		next(0);
	});

	$(refreshGo);
})(jQuery);
