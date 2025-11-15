<?php
// phpcs:ignoreFile WordPress.Security.NonceVerification.Missing
// phpcs:ignoreFile WordPress.Security.ValidatedSanitizedInput.MissingUnslash

class SLN_Action_Ajax_SalonStep extends SLN_Action_Ajax_Abstract
{
    public function execute()
    {
        $requestedStep = null;
        $mode = null;
        if (isset($_POST['sln_step_page'])) {
            $requestedStep = sanitize_text_field(wp_unslash($_POST['sln_step_page']));
            $_GET['sln_step_page'] = $requestedStep;
        } elseif (isset($_GET['sln_step_page'])) {
            $requestedStep = sanitize_text_field(wp_unslash($_GET['sln_step_page']));
            $_GET['sln_step_page'] = $requestedStep;
        }
        if (isset($_POST['mode'])) {
            $mode = sanitize_text_field($_POST['mode']);
            $_GET['mode'] = $mode;
        } elseif (isset($_GET['mode'])) {
            $mode = sanitize_text_field($_GET['mode']);
            $_GET['mode'] = $mode;
        }

        if (isset($_POST['pay_remaining_amount'])) {
            $_GET['pay_remaining_amount'] = sanitize_text_field($_POST['pay_remaining_amount']);
        }

        if (isset($_POST['sln_client_id'])) {
            $_GET['sln_client_id'] = sanitize_text_field(wp_unslash($_POST['sln_client_id']));
        }

        SLN_Plugin::addLog(sprintf('[Wizard] Ajax SalonStep request step="%s" mode="%s"', $requestedStep ? $requestedStep : '(default)', $mode ? $mode : '(none)'));

        try {
            $ret = do_shortcode('[' . SLN_Shortcode_Salon::NAME . '][/' . SLN_Shortcode_Salon::NAME . ']');
            $ret = array(
                'content' => $ret,
                'nonce' => wp_create_nonce('ajax_post_validation')
            );
        } catch (SLN_Action_Ajax_RedirectException $ex) {
            SLN_Plugin::addLog(sprintf('[Wizard] Ajax SalonStep redirect to "%s"', $ex->getMessage()));
            $ret = array(
                'redirect' => $ex->getMessage()
            );
        }
        return $ret;
    }
}
