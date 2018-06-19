/**
 * Mirtesen.
 *
 * TopicsWizard Container.
 *
 * @author       Oleg Kravchenko.
 * @copyright    Copyright (c) 2018, Mirtesen LLC.
 * @license      Property of Mirtesen LLC. All rights reserved.
 */

import React, { Component } from 'react';
import { connect } from 'react-redux';
import { bindActionCreators } from 'redux';
import {
    Nav, NavItem, NavLink,
    TabContent, TabPane
} from 'reactstrap';
import PropTypes from 'prop-types';
import config from '../../../../etc/config.js';
import classnames from 'classnames';
import * as actions from './topicsWizardActions';
import TopicsWizardTopics from '../../components/topicsWizard/TopicsWizardTopics';
import TopicsWizardSites from '../../components/topicsWizard/TopicsWizardSites';
import TopicsWizardInterests from '../../components/topicsWizard/TopicsWizardInterests';
import TopicsWizardFooter from '../../components/topicsWizard/TopicsWizardFooter';

class TopicsWizard extends Component {
    static propTypes = {
        activeTabInd: PropTypes.number,
        topics: PropTypes.arrayOf(PropTypes.object),
        selectedData: PropTypes.object
    };

    isValidNewSite = (input) => {
        return (input.label && input.label.length > 3 && /^([a-z0-9]+(-[a-z0-9]+)*\.)+[a-z]{2,}$/.test(input.label));
    }

    getSites = (input, callback) => {
        return this.props.actions.topicsWizardSearchSites(input, callback);
    };

    onSiteChange = (value) => {
        return this.props.actions.topicsWizardSetSites(value);
    }

    getInterests = (input, callback) => {
        return this.props.actions.topicsWizardSearchTags(input, callback);
    };

    onInterestChange = (value) => {
        return this.props.actions.topicsWizardSetInterests(value);
    }

    onTopicKeyDown = (event) => {
        if (event.key == 'Enter') {
            event.preventDefault();
        }
    }

    render() {
        const { toggleTopicsWizard, topicsWizardPrevTab, topicsWizardNextTab, topicsWizardSkipTab,
            topicsWizardToggleTopic } = this.props.actions;
        this.props.topics.map(function (item, i) {
            let color = (this.props.selectedData.topics.indexOf(item.key) != -1) ? 'primary' : 'secondary';
            item.props = {
                outline: true,
                onKeyDown: this.onTopicKeyDown,
                onClick: topicsWizardToggleTopic.bind(this, item.key),
                color: color,
                refkey: item.key
            };
        }.bind(this));

        const tabs = [
            {
                name: 'topics', caption: 'Выбор тематик', component: TopicsWizardTopics,
                props: {
                    activeTabInd: this.props.activeTabInd,
                    items: this.props.topics,
                    selectedData: this.props.selectedData,
                    nextTabHandler: this.props.actions.toggleTopicsWizard.bind(this, 1)
                }
            },
            {
                name: 'sites', caption: 'Любимые сайты', component: TopicsWizardSites,
                props: {
                    activeTabInd: this.props.activeTabInd,
                    value: this.props.selectedData.sites,
                    labelKey: 'domain',
                    placeholder: 'Например, ushilapychvost.ru',
                    isValidNewOption: this.isValidNewSite,
                    loadOptions: this.getSites,
                    onChange: this.onSiteChange
                }
            },
            {
                name: 'interests', caption: 'Интересы', component: TopicsWizardInterests,
                props: {
                    activeTabInd: this.props.activeTabInd,
                    value: this.props.selectedData.interests,
                    labelKey: 'name',
                    placeholder: 'Пожалуйста, введите хотя бы 2 символа',
                    loadOptions: this.getInterests,
                    onChange: this.onInterestChange
                }
            }
        ];

        return (
            <div className="topics-wizard">
                <Nav tabs>
                    {
                        tabs.map(function (item, i) {
                            return <NavItem key={i}>
                                <NavLink
                                    className={classnames({ active: tabs[this.props.activeTabInd].name === item.name })}
                                    onClick={toggleTopicsWizard.bind(this, i)}
                                    >
                                    {item.caption}
                                </NavLink>
                            </NavItem>
                        }, this)
                    }
                </Nav>
                <TabContent activeTab={tabs[this.props.activeTabInd].name}>
                    {
                        tabs.map(function (item, i) {
                            const TabName = item.component;
                            return <TabPane key={i} tabId={item.name}>
                                <TabName {...item.props}/>
                            </TabPane>
                        })
                    }
                </TabContent>
                <TopicsWizardFooter activeTabInd={this.props.activeTabInd} prevTabHandler={topicsWizardPrevTab}
                                    nextTabHandler={topicsWizardNextTab.bind(this, tabs.length)}
                                    skipTabHandler={topicsWizardSkipTab.bind(this, tabs.length)}/>
            </div>
        );
    }
}

const mapStateToProps = state => {
    return {
        activeTabInd: state.topicsWizard.activeTabInd,
        selectedData: state.topicsWizard.selectedData
    };
};

const mapDispatchToProps = (dispatch) => ({
    actions: bindActionCreators(actions, dispatch)
});

export default connect(mapStateToProps, mapDispatchToProps)(TopicsWizard);