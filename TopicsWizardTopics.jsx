/**
 * Mirtesen.
 *
 * TopicsWizardTopics Component.
 *
 * @author       Oleg Kravchenko.
 * @copyright    Copyright (c) 2018, Mirtesen LLC.
 * @license      Property of Mirtesen LLC. All rights reserved.
 */

import React, { Component } from 'react';
import ReactDOM from 'react-dom';
import { Button } from 'reactstrap';
import PropTypes from 'prop-types';
import Topics from "../Topics";

class TopicsWizardTopics extends Component {
    static propTypes = {
        activeTabInd: PropTypes.number,
        items: PropTypes.arrayOf(PropTypes.object).isRequired,
        selectedData: PropTypes.object,
        nextTabHandler: PropTypes.func
    };

    onKeyUp = (event) => {
        if (event.key == 'Enter') { // go to next tab
            this.props.nextTabHandler();
        } else if (event.key == 'ArrowRight') { // focus next topic
            if (document.activeElement.nextSibling) {
                document.activeElement.nextSibling.focus();
            } else {
                this.setFocus(0);
            }
        } else if (event.key == 'ArrowLeft') { // focus previous topic
            if (document.activeElement.previousSibling) {
                document.activeElement.previousSibling.focus();
            } else {
                const refs = this.refs.topicsWizardTopics.refs;
                this.setFocus(Object.keys(refs).length - 1);
            }
        }
    }
    
    setFocus = (ind) => {
        const refs = this.refs.topicsWizardTopics.refs;
        const topics = this.props.selectedData.topics;
        if (typeof ind != 'undefined' && (ind == 0 || ind < Object.keys(refs).length)) {
            ReactDOM.findDOMNode(refs[Object.keys(refs)[ind]]).focus();
        } else if (topics.length) {
            const lastSelectedTopicRef = 'topic_' + topics[topics.length - 1];
            ReactDOM.findDOMNode(refs[lastSelectedTopicRef]).focus();
        } else {
            ReactDOM.findDOMNode(refs[Object.keys(refs)[0]]).focus();
        }
    }

    componentDidMount() {
        setTimeout(function(){this.setFocus()}.bind(this), 100);
    }

    componentDidUpdate() {
        if (this.props.activeTabInd == 0) {
            this.setFocus();
        }
    }

    render() {
        return (
            <div className='topics' onKeyUp={this.onKeyUp}>
                <p>Выберите тематики, которые вам больше всего интересны</p>
                <Topics ref="topicsWizardTopics" items={this.props.items}/>
            </div>
        );
    }
}

export default TopicsWizardTopics;
