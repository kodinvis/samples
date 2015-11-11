/* Page header components */

function escapeHtml(text) {
    return text
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

var LogoutButton = React.createClass({
    getInitialState: function () {
        return {disabled: !gTokens};
    },
    handleClick: function (event) {
        this.setState({disabled: true});
        location.href = gMainPageUrl + 'auth.php?logout=1';
    },
    render: function () {
        var className = (this.state.disabled) ? 'btn btn-default pull-right disabled' : 'btn btn-default pull-right';
        return (
            <button type="button" className={className} onClick={this.handleClick}>
                <span className="glyphicon glyphicon-log-out"></span>
            &nbsp;Log out all connections
            </button>
        );
    }
});

var Header = React.createClass({
    render: function () {
        return (
            <h2>FormAssembly Form Transfer Wizard
                <LogoutButton />
            </h2>
        );
    }
});


/* Panel header components */

var PanelHeaderCopyButton = React.createClass({
    propTypes: {
        id: React.PropTypes.oneOf(['leftPanel', 'rightPanel']),
        selectedInstances: React.PropTypes.object,
        selectedFormsIds: React.PropTypes.arrayOf(React.PropTypes.number),
        notify: React.PropTypes.func,
        setForms: React.PropTypes.func,
        recountSelectedForms: React.PropTypes.func,
        unselectForms: React.PropTypes.func
    },
    getInitialState: function () {
        return {formsNumToProcess: 0};
    },
    processCopyFormResponse: function(res) {
        var defaultErrorMsg = 'An error occurred while copying the form: ' + JSON.stringify(res);
        // Cancel selection of current form and show copy status icon near form name
        if (typeof res.form_id != 'undefined') {
            this.props.recountSelectedForms(-1 * res.form_id);
            var statusIconClass, statusIconTitle;
            if (typeof res.status != 'undefined' && res.status == 'ok') {
                statusIconClass = 'glyphicon-ok';
                statusIconTitle = 'Form copied successfully';
            } else {
                statusIconClass = 'glyphicon-exclamation-sign';
                statusIconTitle = (typeof res.msg != 'undefined' && res.msg.length) ? res.msg : escapeHtml(defaultErrorMsg);
            }
            var formInputId = this.props.id + '_form_id_' + res.form_id;
            $('#'+formInputId).siblings('label:first').after('<span class="glyphicon status ' + statusIconClass + '" title="' + statusIconTitle + '"></span>');
        } else {
            this.props.notify({msg: defaultErrorMsg, status: 'error'});
        }
        this.setState({formsNumToProcess: this.state.formsNumToProcess - 1});
        if (this.state.formsNumToProcess <= 0) { // all forms processed
            this.props.unselectForms();

            // Hide modal
            $('.modal').modal('hide');
        }

        // Refresh forms on the opposite panel after successful copying
        if (typeof res.opposite_panel_forms != 'undefined') {
            var oppositePanelId = (this.props.id == 'leftPanel') ? 'rightPanel' : 'leftPanel';
            var toInst = this.props.selectedInstances[oppositePanelId];

            gForms[oppositePanelId][toInst] = res.opposite_panel_forms;
            this.props.setForms(oppositePanelId, toInst, res.opposite_panel_forms);

            // If the same user authorized on both panels and the same instance - set these forms into initial panel too
            if (gForms[oppositePanelId][toInst][0]['Form']['id'] == gForms[this.props.id][toInst][0]['Form']['id']) {
                gForms[this.props.id][toInst] = res.opposite_panel_forms;
                this.props.setForms(this.props.id, toInst, res.opposite_panel_forms);
            }
        }
    },
    handleClick: function (event) {
        this.setState({formsNumToProcess: this.props.selectedFormsIds.length});
        var oppositePanelId = (this.props.id == 'leftPanel') ? 'rightPanel' : 'leftPanel';
        var fromInst = this.props.selectedInstances[this.props.id];
        var toInst = this.props.selectedInstances[oppositePanelId];
        var options = {
            action: 'copy_form',
            from_inst: fromInst,
            from_inst_token: gTokens[this.props.id][fromInst],
            to_inst: toInst,
            to_inst_token: gTokens[oppositePanelId][toInst]
        };
        // Clear status icons
        $('.forms .list-group-item .status').remove();

        // Show modal
        $('.modal').modal({
            backdrop: 'static',
            keyboard: false
        });
        // Loop by all selected forms and copy one by one
        for (var i in this.props.selectedFormsIds) {
            options.form_id = this.props.selectedFormsIds[i];
            $.ajax({
                type: 'POST',
                url: 'ajax.php',
                data: options,
                success: function (res) {
                    this.processCopyFormResponse(res);
                }.bind(this),
                error: function(res) {
                    this.processCopyFormResponse(res);
                }.bind(this),
                dataType: 'json'
            });
        }
    },
    render: function () {
        var buttonClass, iconClass, oppositePanelId;
        if (this.props.id == 'leftPanel') {
            buttonClass = 'btn btn-default pull-right';
            iconClass = 'glyphicon glyphicon-arrow-right';
            oppositePanelId = 'rightPanel';
        } else {
            buttonClass = 'btn btn-default pull-left';
            iconClass = 'glyphicon glyphicon-arrow-left';
            oppositePanelId = 'leftPanel';
        }
        var isUserAuthorizedInOppositePanel = gTokens && typeof(gTokens[oppositePanelId]) != 'undefined';
        if (!this.props.selectedFormsIds.length || !isUserAuthorizedInOppositePanel) {
            buttonClass += ' disabled';
        }
        var formsText = (this.props.selectedFormsIds.length == 1) ? 'form' : 'forms';
        return (this.props.id == 'leftPanel') ? (
            <button type="button" className={buttonClass} onClick={this.handleClick}>Copy selected {this.props.selectedFormsIds.length} {formsText}&nbsp;
                <span className={iconClass}></span>
            </button>
        ) : (
            <button type="button" className={buttonClass} onClick={this.handleClick}>
                <span className={iconClass}></span>
            &nbsp;Copy selected {this.props.selectedFormsIds.length} {formsText}
            </button>
        );
    }
});

var InstancesSelection = React.createClass({
    propTypes: {
        id: React.PropTypes.oneOf(['leftPanel', 'rightPanel']),
        items: React.PropTypes.arrayOf(React.PropTypes.string),
        selectedInstance: React.PropTypes.string,
        selectInstance: React.PropTypes.func
    },
    getDefaultProps: function getDefaultProps() {
        return {items: gInstances};
    },
    handleChange: function (event) {
        this.props.selectInstance(this.props.id, event.target.value);
    },
    render: function () {
        var isUserAuthorized = gTokens && typeof(gTokens[this.props.id]) != 'undefined';
        return (
            <select className="form-control" disabled={isUserAuthorized} onChange={this.handleChange} value={this.props.selectedInstance}>
            {
                this.props.items.map(function (el, i) {
                    return <option key={i} value={el}>{el}</option>
                })
                }
            </select>
        );
    }
});

var PanelHeader = React.createClass({
    propTypes: {
        id: React.PropTypes.oneOf(['leftPanel', 'rightPanel']),
        selectedInstances: React.PropTypes.object,
        selectedFormsIds: React.PropTypes.arrayOf(React.PropTypes.number),
        notify: React.PropTypes.func,
        selectInstance: React.PropTypes.func,
        setForms: React.PropTypes.func,
        recountSelectedForms: React.PropTypes.func,
        unselectForms: React.PropTypes.func
    },
    render: function () {
        var isUserAuthorized = gTokens && typeof(gTokens[this.props.id]) != 'undefined';
        var titleClass = (this.props.id == 'rightPanel') ? 'panel-title text-right' : 'panel-title';
        var connectLabel = (isUserAuthorized) ? 'Connected to' : 'Connect to';
        return (
            <div className="panel-heading">
                <h3 className={titleClass}>
                    <span className="connect-label">{connectLabel}</span>
                    <InstancesSelection id={this.props.id} selectInstance={this.props.selectInstance}
                            selectedInstance={this.props.selectedInstances[this.props.id]}/>
                    <PanelHeaderCopyButton id={this.props.id} notify={this.props.notify} selectedInstances={this.props.selectedInstances}
                            selectedFormsIds={this.props.selectedFormsIds} recountSelectedForms={this.props.recountSelectedForms}
                            unselectForms={this.props.unselectForms} setForms={this.props.setForms} />
                </h3>
            </div>
        );
    }
});


/* Panel body components */

var LoginButton = React.createClass({
    propTypes: {
        id: React.PropTypes.oneOf(['leftPanel', 'rightPanel']),
        selectedInstance: React.PropTypes.string
    },
    getInitialState: function () {
        return {disabled: false};
    },
    handleClick: function (event) {
        this.setState({disabled: true});
        location.href = gMainPageUrl + 'auth.php?panel=' + this.props.id + '&inst=' + this.props.selectedInstance;
    },
    render: function () {
        var loginBtnClassName = (this.state.disabled) ? 'btn btn-default disabled' : 'btn btn-default';
        var connectionStatusClassName = (this.state.disabled) ? 'label label-info connection-status' : 'label label-info connection-status hidden';
        return (
            <div id="loginBtn">
                <button type="button" className={loginBtnClassName} onClick={this.handleClick}>
                    <span className="glyphicon glyphicon-log-in"></span>
                &nbsp;Log in
                </button>
                <span className={connectionStatusClassName}>Connecting...</span>
            </div>
        );
    }
});

var Notification = React.createClass({
    propTypes: {
        data: React.PropTypes.object,
        notify: React.PropTypes.func
    },
    hideAfter: function (delay) {
        this._timeout = setTimeout(function () {
            this.props.notify({});
        }.bind(this), delay);
    },
    componentWillUnmount: function () {
        clearTimeout(this._timeout);
    },
    render: function () {
        var msg = this.props.data.msg;
        if (!msg) {
            return null;
        }
        this.hideAfter(10000);
        return (this.props.data.status == 'ok') ? (
            <div className="alert alert-success" role="alert">
                <span className="glyphicon glyphicon-ok" aria-hidden="true"></span>
            &nbsp;{msg}
            </div>
        ) : (
            <div className="alert alert-danger" role="alert">
                <span className="glyphicon glyphicon-exclamation-sign" aria-hidden="true"></span>
            &nbsp;{msg}
            </div>
        );
    }
});

var Form = React.createClass({
    propTypes: {
        id: React.PropTypes.oneOf(['leftPanel', 'rightPanel']),
        data: React.PropTypes.object,
        selectedFormsIds: React.PropTypes.arrayOf(React.PropTypes.number),
        recountSelectedForms: React.PropTypes.func
    },
    recountSelectedForms: function (event) {
        var formId = (event.target.checked) ? event.target.value : -1 * event.target.value;
        this.props.recountSelectedForms(formId);
    },
    render: function () {
        var formData = this.props.data.Form;
        var formId = this.props.id + '_form_id_' + formData.id;
        var checked = ($.inArray(parseInt(formData.id), this.props.selectedFormsIds) != -1) ? 'checked' : null;
        return (
            <li className="list-group-item">
                <input type="checkbox" id={formId} name="form_id[]" value={formData.id} checked={checked} onChange={this.recountSelectedForms}/>
            &nbsp;
                <label htmlFor={formId} className="form-name">{formData.name} (ID={formData.id})</label><br/>
                <span className="form-desc">ID {formData.id} - Revision {formData.version_id} - Last modification: {formData.modified}</span>
            </li>
        );
    }
});

var Forms = React.createClass({
    propTypes: {
        id: React.PropTypes.oneOf(['leftPanel', 'rightPanel']),
        items: React.PropTypes.arrayOf(React.PropTypes.object),
        selectedFormsIds: React.PropTypes.arrayOf(React.PropTypes.number),
        recountSelectedForms: React.PropTypes.func
    },
    recountSelectedForms: function (formId) {
        this.props.recountSelectedForms(formId);
    },
    render: function () {
        return (this.props.items && this.props.items.length) ? (
            <div className="forms">
                <h4>User: {this.props.items[0].User.username}</h4>
                <h4>Forms:</h4>
                <ul className="list-group">
                {
                    this.props.items.map(function (data) {
                        return <Form key={data.Form.id} data={data} id={this.props.id} selectedFormsIds={this.props.selectedFormsIds} recountSelectedForms={this.recountSelectedForms} />
                    }.bind(this))
                    }
                </ul>
            </div>
        ) : (
            <div id="forms">
                <div className="alert alert-info" role="alert">
                    <span className="glyphicon glyphicon-info-sign" aria-hidden="true"></span>
                &nbsp;Forms not found
                </div>
            </div>
        )
    }
});

var PanelBody = React.createClass({
    propTypes: {
        id: React.PropTypes.oneOf(['leftPanel', 'rightPanel']),
        notification: React.PropTypes.object,
        selectedInstance: React.PropTypes.string,
        selectedFormsIds: React.PropTypes.arrayOf(React.PropTypes.number),
        forms: React.PropTypes.arrayOf(React.PropTypes.object),
        notify: React.PropTypes.func,
        recountSelectedForms: React.PropTypes.func
    },
    render: function () {
        var isUserAuthorized = gTokens && typeof(gTokens[this.props.id]) != 'undefined';
        var errorNotificationData = (gErrors && typeof(gErrors[this.props.id]) != 'undefined') ? {
            msg: gErrors[this.props.id],
            status: 'error'
        } : {};
        return (isUserAuthorized) ? (
            <div className="panel-body">
                <Notification id={this.props.id} data={this.props.notification} notify={this.props.notify} />
                <Forms items={this.props.forms} id={this.props.id} selectedFormsIds={this.props.selectedFormsIds} recountSelectedForms={this.props.recountSelectedForms} />
            </div>
        ) : (
            <div className="panel-body">
                <Notification id={this.props.id} data={errorNotificationData} notify={this.props.notify} />
                <LoginButton id={this.props.id} selectedInstance={this.props.selectedInstance} />
            </div>
        )
    }
});


/* Page with panels */

var Panel = React.createClass({
    propTypes: {
        id: React.PropTypes.oneOf(['leftPanel', 'rightPanel']),
        selectedInstances: React.PropTypes.object,
        forms: React.PropTypes.arrayOf(React.PropTypes.object),
        setForms: React.PropTypes.func,
        selectInstance: React.PropTypes.func
    },
    getInitialState: function () {
        return {selectedFormsIds: [], notification: {msg: '', status: ''}};
    },
    recountSelectedForms: function (formId) {
        var selectedFormsIds = this.state.selectedFormsIds;
        if (formId > 0) {
            selectedFormsIds.push(parseInt(formId));
        }
        else {
            var index = selectedFormsIds.indexOf(Math.abs(formId));
            if (index != -1)
                selectedFormsIds.splice(index, 1);
        }
        this.setState({selectedFormsIds: selectedFormsIds});
    },
    unselectForms: function() {
        this.setState({selectedFormsIds: []});
    },
    notify: function (notification) {
        this.setState({notification: notification});
    },
    render: function () {
        var isUserAuthorized = gTokens && typeof(gTokens[this.props.id]) != 'undefined';
        var className = (isUserAuthorized) ? 'panel panel-success' : 'panel panel-default';
        return (
            <div id={this.props.id} className="col-lg-6">
                <div className={className}>
                    <PanelHeader id={this.props.id} notify={this.notify} selectedInstances={this.props.selectedInstances}
                            selectInstance={this.props.selectInstance} selectedFormsIds={this.state.selectedFormsIds}
                            recountSelectedForms={this.recountSelectedForms} unselectForms={this.unselectForms} setForms={this.props.setForms} />
                    <PanelBody id={this.props.id} notify={this.notify} selectedInstance={this.props.selectedInstances[this.props.id]}
                            notification={this.state.notification} forms={this.props.forms} selectedFormsIds={this.state.selectedFormsIds}
                            recountSelectedForms={this.recountSelectedForms} />
                </div>
            </div>
        );
    }
});

var Panels = React.createClass({
    propTypes: {
        panels: React.PropTypes.arrayOf(React.PropTypes.string)
    },
    getDefaultProps: function () {
        return {panels: ['leftPanel', 'rightPanel']};
    },
    getInitialState: function () {
        var selectedInstances = {};
        var forms = {};
        var panels = this.props.panels;
        for (var i in panels) {
            var panel = panels[i];
            if (gTokens && typeof gTokens[panel] != 'undefined') {
                selectedInstances[panel] = Object.keys(gTokens[panel])[0];
            } else {
                selectedInstances[panel] = gInstances[0];
            }
            if (gForms && typeof gForms[panel] != 'undefined' && typeof gForms[panel][selectedInstances[panel]] != 'undefined') {
                forms[panel] = gForms[panel][selectedInstances[panel]];
            }
        }
        return {forms: forms, selectedInstances: selectedInstances};
    },
    selectInstance: function (panelId, selectedInstance) {
        this.state.selectedInstances[panelId] = selectedInstance;
        this.setState({selectedInstances: this.state.selectedInstances});
    },
    setForms: function (panel, inst, forms) {
        var sForms = this.state.forms;
        sForms[panel] = forms;
        this.setState({forms: sForms});
    },
    render: function () {
        return (
            <div className="row">
            {
                this.props.panels.map(function (el, i) {
                    return <Panel id={el} key={el} selectedInstances={this.state.selectedInstances} selectInstance={this.selectInstance} forms={this.state.forms[el]} setForms={this.setForms} />
                }.bind(this))
                }
            </div>
        );
    }
});

var Modal = React.createClass({
    render: function () {
        return (
            <div className="modal fade" tabIndex="-1" role="dialog" aria-hidden="true">
                <div className="modal-dialog modal-sm">
                    <div className="modal-content">
                        Copying...
                    </div>
                </div>
            </div>
        );
    }
});
var Page = React.createClass({
    render: function () {
        return (
            <div className="content">
                <Header />
                <Panels />
                <Modal />
            </div>
        );
    }
});

React.render(
    <Page />,
    document.body
);
