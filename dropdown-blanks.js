/**
 * SERU Dropdown Blanks
 * Turns LearnDash "Fill in the blank" inputs into dropdowns for converted questions.
 * The original text input stays in the page (hidden) so LearnDash grades it as usual.
 */
(function ($) {
	'use strict';
	if (!window.SDDB || !SDDB.endpoint) { return; }

	var cache = {};       // pro id -> config
	var requested = {};   // pro id -> true

	function shuffle(a) {
		a = a.slice();
		for (var i = a.length - 1; i > 0; i--) {
			var j = Math.floor(Math.random() * (i + 1));
			var t = a[i]; a[i] = a[j]; a[j] = t;
		}
		return a;
	}

	function fire(el) {
		['input', 'keyup', 'change'].forEach(function (type) {
			var ev;
			try { ev = new Event(type, { bubbles: true }); } catch (e) { ev = document.createEvent('Event'); ev.initEvent(type, true, true); }
			el.dispatchEvent(ev);
		});
		$(el).trigger('keyup').trigger('change');
	}

	function enhance(list, cfg) {
		if (list.getAttribute('data-sddb') === '1') { return; }
		var inputs = list.querySelectorAll('.wpProQuiz_cloze input[type="text"], .wpProQuiz_cloze input:not([type])');
		if (!inputs.length) { return; }
		if (inputs.length !== cfg.blanks.length) {
			// Safety: never half-convert a question; leave LearnDash's own inputs in place.
			list.setAttribute('data-sddb', 'skip');
			if (window.console) { console.warn('[SDDB] blank count mismatch for question', list.getAttribute('data-question_id'), inputs.length, cfg.blanks.length); }
			return;
		}
		list.setAttribute('data-sddb', '1');

		Array.prototype.forEach.call(inputs, function (input, i) {
			var vals = cfg.shuffle ? shuffle(cfg.blanks[i]) : cfg.blanks[i];
			var sel = document.createElement('select');
			sel.className = 'sddb-select';
			sel.setAttribute('aria-label', 'Blank ' + (i + 1));

			var ph = document.createElement('option');
			ph.value = '';
			var phText = (cfg.placeholders && cfg.placeholders[i]) ? cfg.placeholders[i] : SDDB.placeholder;
			ph.textContent = cfg.blank_first ? '' : phText;
			ph.disabled = !cfg.blank_first;
			ph.selected = true;
			sel.appendChild(ph);

			vals.forEach(function (v) {
				var o = document.createElement('option');
				o.value = v;
				o.textContent = v;
				sel.appendChild(o);
			});

			// Restore a value LearnDash may already have put back (e.g. resumed quiz).
			if (input.value) { sel.value = input.value; }

			sel.addEventListener('change', function () {
				input.value = sel.value;
				fire(input);
			});

			input.classList.add('sddb-hidden-input');
			input.setAttribute('tabindex', '-1');
			input.setAttribute('aria-hidden', 'true');
			input.parentNode.insertBefore(sel, input);

			// Mirror LearnDash locking the answer after "Check" / quiz end.
			new MutationObserver(function () {
				sel.disabled = input.disabled || input.readOnly;
				if (input.value !== sel.value && input.value) { sel.value = input.value; }
			}).observe(input, { attributes: true, attributeFilter: ['disabled', 'readonly', 'value'] });
		});
	}

	function scan(root) {
		var lists = (root || document).querySelectorAll('ul.wpProQuiz_questionList[data-type="cloze_answer"]:not([data-sddb])');
		if (!lists.length) { return; }
		var need = [];
		Array.prototype.forEach.call(lists, function (list) {
			var id = list.getAttribute('data-question_id');
			if (!id) { return; }
			if (cache[id]) { enhance(list, cache[id]); }
			else if (!requested[id]) { requested[id] = true; need.push(id); }
		});
		if (!need.length) { return; }
		for (var k = 0; k < need.length; k += 100) {
			(function (chunk) {
				$.getJSON(SDDB.endpoint, { ids: chunk.join(',') }).done(function (data) {
					$.each(data || {}, function (id, cfg) { cache[id] = cfg; });
					scan(document);
				});
			})(need.slice(k, k + 100));
		}
	}

	$(function () {
		scan(document);
		// LearnDash can render or reload questions after page load.
		var t;
		new MutationObserver(function () {
			clearTimeout(t);
			t = setTimeout(function () { scan(document); }, 150);
		}).observe(document.body, { childList: true, subtree: true });
	});
})(jQuery);
