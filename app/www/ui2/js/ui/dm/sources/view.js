Scalr.regPage('Scalr.ui.dm.sources.view', function (loadParams, moduleParams) {
	var store = Ext.create('store.store', {
		fields: [
			'id', 'url', 'type', 'auth_type'
		],
		proxy: {
			type: 'scalr.paging',
			url: '/dm/sources/xListSources'
		},
		remoteSort: true
	});

	return Ext.create('Ext.grid.Panel', {
		scalrOptions: {
			reload: false,
			maximize: 'all',
            menuTitle: 'Deployments Sources'
		},
		store: store,
		stateId: 'grid-dm-sources-view',
		stateful: true,
        plugins: [ 'gridstore', 'applyparams' ],

		viewConfig: {
			emptyText: 'No sources found',
			loadingText: 'Loading sources ...'
		},

		columns: [
			{ header: "ID", width: 80, dataIndex: 'id', sortable: true },
			{ header: "URL", flex: 1, dataIndex: 'url', sortable: true },
			{ header: "Type", width: 120, dataIndex: 'type', sortable: true },
			{ header: "Auth type", width: 120, dataIndex: 'auth_type', sortable: false },
			{
				xtype: 'optionscolumn',
				menu: [{
					text: 'Edit',
					iconCls: 'x-menu-icon-edit',
                    showAsQuickAction: true,
					href: '#/dm/sources/{id}/edit'
				}, {
					text: 'Delete',
					iconCls: 'x-menu-icon-delete',
                    showAsQuickAction: true,
					request: {
						confirmBox: {
							msg: 'Are you sure want to remove deployment source "{url}"?',
							type: 'delete'
						},
						processBox: {
							type: 'delete',
							msg: 'Removing demployment source ...'
						},
						url: '/dm/sources/xRemoveSources',
						dataHandler: function (data) {
							return {
								sourceId: data['id']
							};
						},
						success: function(data) {
							store.load();
						}
					}
				}]
			}
		],

		dockedItems: [{
            dock: 'top',
			xtype: 'displayfield',
			cls: 'x-form-field-warning x-form-field-warning-fit',
			value: Scalr.strings['deprecated_warning']
        },{
			xtype: 'scalrpagingtoolbar',
			store: store,
			dock: 'top',
			beforeItems: [{
                text: 'Add source',
                cls: 'x-btn-green',
				handler: function() {
					Scalr.event.fireEvent('redirect', '#/dm/sources/create');
				}
			}],
            items: [{
                xtype: 'filterfield',
                store: store
            }]
		}]
	});
});
