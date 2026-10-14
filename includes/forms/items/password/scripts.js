fwEvents.on('fw-builder:form-builder:register-items', function (builder) {
	fwFormBuilderSimpleItem(builder, 'member-password', {
		preview: function (o) {
			var twice = fw.opg('confirm', o);
			return '<input type="password" disabled value="password" style="width:100%">' +
				(twice ? '<input type="password" disabled value="password" style="width:100%;margin-top:6px">' : '');
		}
	});
});
