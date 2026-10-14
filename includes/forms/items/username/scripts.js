fwEvents.on('fw-builder:form-builder:register-items', function (builder) {
	fwFormBuilderSimpleItem(builder, 'member-username', {
		preview: function () {
			return '<input type="text" disabled placeholder="username" style="width:100%">';
		}
	});
});
