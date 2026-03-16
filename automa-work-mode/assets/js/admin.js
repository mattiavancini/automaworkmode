(function () {
	function formatCountdown(seconds) {
		var safeSeconds = Math.max(0, seconds);

		if (safeSeconds >= 3600) {
			var hours = Math.floor(safeSeconds / 3600);
			var minutes = Math.floor((safeSeconds % 3600) / 60);
			return hours + 'h ' + minutes + 'm';
		}

		var mins = Math.floor(safeSeconds / 60);
		var secs = safeSeconds % 60;
		return mins + 'm ' + String(secs).padStart(2, '0') + 's';
	}

	function updateCountdowns() {
		var now = Math.floor(Date.now() / 1000);

		document.querySelectorAll('.automa-work-mode-countdown[data-automa-end-timestamp]').forEach(function (node) {
			var endTimestamp = parseInt(node.getAttribute('data-automa-end-timestamp'), 10) || 0;
			node.textContent = formatCountdown(endTimestamp - now);
		});
	}

	updateCountdowns();
	window.setInterval(updateCountdowns, 1000);
}());
