import React from 'react'
import AnyCommentComponent from "./AnyCommentComponent"

class DataProcessing extends AnyCommentComponent {
    render() {
        const settings = this.getSettings();
        const i18 = settings.i18;

        if (!('accept_user_agreement' in i18) || !settings.options.userAgreementLink) {
            return (null);
        }

        return (
            <div className="anycomment-form-submit__user-agreement">
                <label htmlFor="accept-user-agreement">
                    <input type="checkbox" required={true} checked={this.props.isAgreementAccepted}
                           id="accept-user-agreement"
                           onClick={(e) => this.props.onAccept(e)}/>
                    <span dangerouslySetInnerHTML={{__html: i18.accept_user_agreement}}/>
                    <span className="checkmark"></span>
                </label>
                <div className="clearfix"></div>
            </div>
        )
    }
}

export default DataProcessing;