/* Use WordPress's existing Media Library picker. */
document.addEventListener('click', function (event) {
	const button = event.target.closest('button[data-imazeo-picker]');
	if (!button || !window.wp || !window.wp.media) {
		return;
	}
	const input = document.getElementById(button.dataset.imazeoPicker);
	if (!input) {
		return;
	}
	const mimeTypes = {
		image: 'image',
		pdf: 'application/pdf',
		docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		pptx: 'application/vnd.openxmlformats-officedocument.presentationml.presentation'
	};
	const mimeType = mimeTypes[button.dataset.imazeoFamily];
	const library = mimeType ? { type: mimeType } : {};
	const frame = window.wp.media({ library, multiple: !button.dataset.imazeoSingle });
	frame.on('select', function () {
		const chosen = frame.state().get('selection').map(function (item) {
			return item.get('id');
		});
		input.value = chosen.join(',');
		input.dispatchEvent(new Event('input', { bubbles: true }));
		input.focus();
	});
	frame.open();
});
