<?php
// phpcs:ignoreFile WordPress.Security.EscapeOutput.OutputNotEscaped
class SLN_Shortcode_Salon_SummaryStep extends SLN_Shortcode_Salon_Step
{
    const SLOT_UNAVAILABLE    = 'slotunavailable';
    const SERVICES_DATA_EMPTY = 'servicesdataempty';

    private $op;

    protected function dispatchForm()
    {
        // Session validation
        if (session_status() !== PHP_SESSION_ACTIVE) {
            SLN_Plugin::addLog("ERROR: Session not active in SummaryStep::dispatchForm");
            $this->addError(__('Your session has expired. Please start the booking process again.', 'salon-booking-system'));
            return false;
        }

        $bookingBuilder = $this->getPlugin()->getBookingBuilder();

        $bb     = $bookingBuilder->getLastBooking();
        $value = isset($_POST['sln']) && isset($_POST['sln']['note']) ? sanitize_text_field(wp_unslash($_POST['sln']['note'])) : '';
        $plugin = $this->getPlugin();
        $isCreateAfterPay = /*$plugin->getSettings()->get('create_booking_after_pay') &&*/ $plugin->getSettings()->isPayEnabled();
        if(isset($_GET['sln_booking_id']) && intval($_GET['sln_booking_id'])){
            $bb = $plugin->createBooking(intval(sanitize_text_field($_GET['sln_booking_id'])));
        }

        if(empty($bb) && isset($_GET['op'])){
            $bb = $plugin->createBooking(explode('-', sanitize_text_field($_GET['op']))[1]);
        }

        // Validate booking object after all retrieval attempts
        if(empty($bb)) {
            SLN_Plugin::addLog("ERROR: LastBooking is empty/null in SummaryStep::dispatchForm after all retrieval attempts");
            SLN_Plugin::addLog("Session state: " . (isset($_SESSION) ? 'isset' : 'not set'));
            $this->addError(__('Booking data was lost. Please start the booking process again.', 'salon-booking-system'));
            return false;
        }
        if(!empty($value)){
            $bb->setMeta('note', SLN_Func::filter($value));
        }
        $handler = new SLN_Action_Ajax_CheckDateAlt( $plugin );

        $paymentMethod = $plugin->getSettings()->isPayEnabled() ? SLN_Enum_PaymentMethodProvider::getService($plugin->getSettings()->getPaymentMethod(), $plugin) : false;
        $mode = isset($_GET['mode']) ? sanitize_text_field(wp_unslash($_GET['mode'])) : null;

        if($mode == 'confirm' || empty($paymentMethod) || $bb->getAmount() <= 0.0){
            $errors = $handler->checkDateTimeServicesAndAttendants($bb->getAttendantsIds(), $bb->getStartsAt());
            if(!empty($errors) && !class_exists('\\SalonMultishop\\Addon')){
                $this->addError(self::SLOT_UNAVAILABLE);
                return false;
            }
            foreach ($bb->getMeta('discounts') as $discount){
                $discount_ = new SLB_Discount_Wrapper_Discount($discount);
                $discount_->incrementUsagesNumber($bb->getUserId());
                $discount_->incrementTotalUsagesNumber();
            }
            if($bb->getStatus() == SLN_Enum_BookingStatus::DRAFT){
                ///$bb->setStatus($bb->getCreateStatus()); // SLN_Wrapper_Booking::getCreateStatus
                if($bb->getAmount() <= 0.0 && !SLN_Plugin::getInstance()->getSettings()->get('confirmation')){
                    $bb->setStatus(SLN_Enum_BookingStatus::CONFIRMED);
                } else if(SLN_Plugin::getInstance()->getSettings()->get('confirmation')) {
                    $bb->setStatus(SLN_Enum_BookingStatus::PENDING);
                } else if(empty($paymentMethod)) {
                    $bb->setStatus(SLN_Enum_BookingStatus::CONFIRMED);
                }  else {
                    $bb->setStatus(SLN_Enum_BookingStatus::PAID);
                }
            }
            $bb->setPrepaidServices();
            $bookingBuilder->clear($bb->getId());
            $this->cleanupBookingLock($bb);
            return !$this->hasErrors();
        } elseif($mode == 'later'){
            $errors = $handler->checkDateTimeServicesAndAttendants($bb->getAttendantsIds(), $bb->getStartsAt());
            if(!empty($errors)){
                $this->addError(self::SLOT_UNAVAILABLE);
                return false;
            }
            if(in_array($bb->getStatus(), array(SLN_Enum_BookingStatus::PENDING_PAYMENT, SLN_Enum_BookingStatus::DRAFT))){
                if($bb->getAmount() > 0.0){
                    $bb->setStatus(SLN_Enum_BookingStatus::PAY_LATER);
                }else{
                    $bb->setPrepaidServices();
                    $bb->setStatus($bb->getCreateStatus()); // SLN_Wrapper_Booking::getCreateStatus
                }
            }
            
            $bookingBuilder->clear($bb->getId());
            $this->cleanupBookingLock($bb);
            return !$this->hasErrors();
        }elseif(isset($_GET['op']) || $mode){
            if ($bookingBuilder && method_exists($bookingBuilder, 'forceTransientStorage')) {
                $bookingBuilder->forceTransientStorage();
            }
            if($error = $paymentMethod->dispatchThankyou($this, $bb)){
                $this->addError($error);
            }
        }
        if(!$this->hasErrors()){
            $bookingBuilder->clear($bb->getId());
            $this->cleanupBookingLock($bb);
        }
        if(!empty($paymentMethod) && in_array($bb->getStatus(), array(SLN_Enum_BookingStatus::PAY_LATER, SLN_Enum_BookingStatus::PENDING_PAYMENT))){
            return false;
        }
        if($bb->getStatus() == SLN_Enum_BookingStatus::DRAFT){
            $bb->setStatus($bb->getCreateStatus());
        }

        return !$this->hasErrors();
    }

    public function render()
    {
        $bb = $this->getPlugin()->getBookingBuilder();
        if($bb->get('services') && $bb->isValid()){
            $data = $this->getViewData();
            $service_ids   = implode('-', $bb->getServicesIds());
            $attendant_ids = implode('-', array_values($bb->getAttendantsIds()));
            $start_time    = $bb->getDateTime()->format('Y-m-d H:i:s');

            $lock_key = 'booking_lock_' . md5($service_ids . '_' . $attendant_ids . '_' . $start_time);

            if ( get_transient($lock_key) ) {
                $this->addError(self::SLOT_UNAVAILABLE);
                $bb->create(SLN_Enum_BookingStatus::DRAFT);
                return parent::render();
            }

            set_transient($lock_key, 1, 15);

            do_action('sln.shortcode.summary.dispatchForm.before_booking_creation', $this, $bb);
            if ( ! $this->hasErrors() ) {
                $bb->create(SLN_Enum_BookingStatus::DRAFT);
            }
            do_action('sln.shortcode.summary.dispatchForm.after_booking_creation', $bb);
            return parent::render();
        }elseif($bb->getLastBooking()){
            $data = $this->getViewData();
            $bb = $bb->getLastBooking();
            
            $custom_url = apply_filters('sln.shortcode.render.custom_url', false, $this->getStep(), $this->getShortcode(), $bb);
            if ($custom_url) {
                $this->redirect($custom_url);
                wp_die();
            }
            return parent::render();
        }else{
            if(empty($bb->get('services'))){
                $this->addError(self::SERVICES_DATA_EMPTY);
                $this->redirect(add_query_arg(array('sln_step_page' => 'services')));
                return parent::render(); // Return content after redirect for AJAX
            }else{
                $this->addError(self::SLOT_UNAVAILABLE);
                return parent::render();
            }
        }
    }

    public function setOp($op){
        $this->op = $op;
    }

    public function getViewData(){
        $ret = parent::getViewData();
        $formAction = $ret['formAction'];

        $requestArgs = $this->getSanitizedRequestArgs();
        $baseAction  = $this->buildBaseUrl($formAction, $requestArgs);

        $bookingBuilder = $this->getPlugin()->getBookingBuilder();
        $lastBooking = $bookingBuilder->getLastBooking();
        $lastBookingId = $lastBooking ? $lastBooking->getId() : null;
        $clientId = $bookingBuilder->getClientId();

        if ($this->getPlugin()->getSettings()->isPayEnabled() && empty($clientId) && method_exists($bookingBuilder, 'forceTransientStorage')) {
            $clientId = $bookingBuilder->forceTransientStorage();
        }

        $commonArgs = $this->getCommonUrlArgs($requestArgs, $clientId);

        $laterUrl = add_query_arg(
            array_merge(
                $commonArgs,
                array(
                    'mode' => 'later',
                    'submit_'. $this->getStep() => 'next',
                    'sln_step_page' => $this->getStep(),
                )
            ),
            $baseAction
        );

        $confirmUrl = add_query_arg(
            array_merge(
                $commonArgs,
                array(
                    'mode' => 'confirm',
                    'submit_'. $this->getStep() => 'next',
                    'sln_step_page' => $this->getStep(),
                )
            ),
            $baseAction
        );
        $confirmUrl = apply_filters('sln.booking.thankyou-step.get-confirm-url', $confirmUrl);

        if (!empty($clientId)) {
            $confirmUrl = add_query_arg('sln_client_id', $clientId, $confirmUrl);
            $laterUrl   = add_query_arg('sln_client_id', $clientId, $laterUrl);
        }

        if (!empty($lastBookingId)) {
            $confirmUrl = add_query_arg('sln_booking_id', $lastBookingId, $confirmUrl);
            $laterUrl   = add_query_arg('sln_booking_id', $lastBookingId, $laterUrl);
        }

        $data = array(
            'booking' => $lastBooking,
            'confirmUrl' => $confirmUrl,
            'laterUrl' => $laterUrl,
        );
        
        if($this->getPlugin()->getSettings()->isPayEnabled()){
            $payBase = $this->getPlugin()->getSettings()->getPayPageId()
                ? get_permalink($this->getPlugin()->getSettings()->getPayPageId())
                : $baseAction;

            $payUrl = add_query_arg(
                array_merge(
                    $commonArgs,
                    array(
                        'mode' => $this->getPlugin()->getSettings()->getPaymentMethod(),
                        'submit_'. $this->getStep() => 'next',
                        'sln_step_page' => $this->getStep(),
                    )
                ),
                $payBase
            );

            if (!empty($clientId)) {
                $payUrl = add_query_arg('sln_client_id', $clientId, $payUrl);
            }
            if (!empty($lastBookingId)) {
                $payUrl = add_query_arg('sln_booking_id', $lastBookingId, $payUrl);
            }

            $payUrl = apply_filters('sln.booking.thankyou-step.get-pay-url', $payUrl);
            $data['payUrl'] = $payUrl;
            $data['payOp'] = $this->op;
        }
        return array_merge($ret, $data);
    }

    private function getSanitizedRequestArgs()
    {
        $args = $_GET;
        if (isset($args['page_id']) && strpos($args['page_id'], '?') !== false) {
            $parts = explode('?', $args['page_id'], 2);
            $args['page_id'] = $parts[0];
            if (!empty($parts[1])) {
                parse_str($parts[1], $extra);
                $args = array_merge($extra, $args);
            }
        }
        return $args;
    }

    private function buildBaseUrl($url, array $queryArgs)
    {
        $base = remove_query_arg(array_keys($queryArgs), $url);
        if (isset($queryArgs['page_id'])) {
            $base = add_query_arg('page_id', $queryArgs['page_id'], $base);
        }
        return $base;
    }

    private function getCommonUrlArgs(array $requestArgs, $clientId)
    {
        $common = array();
        if (isset($requestArgs['lang'])) {
            $common['lang'] = $requestArgs['lang'];
        }
        if (isset($requestArgs['pay_remaining_amount'])) {
            $common['pay_remaining_amount'] = $requestArgs['pay_remaining_amount'];
        }
        if (isset($requestArgs['mode']) && !in_array($requestArgs['mode'], array('confirm', 'later'), true)) {
            $common['mode'] = $requestArgs['mode'];
        }
        if (!empty($clientId) && !isset($common['sln_client_id'])) {
            $common['sln_client_id'] = $clientId;
        }
        return $common;
    }

    public function redirect($url)
    {
        if ($this->isAjax()) {
            throw new SLN_Action_Ajax_RedirectException($url);
        } else {
            wp_redirect($url);die;
        }
    }

    public function isAjax()
    {
        return defined('DOING_AJAX') && DOING_AJAX;
    }

    public function getTitleKey(){
        return 'Booking summary';
    }

    public function getTitleLabel(){
        return __('Booking summary', 'salon-booking-system');
    }

    /**
     * Clean up the transient lock when a booking is deleted
     * 
     * @param SLN_Wrapper_Booking $booking
     */
    private function cleanupBookingLock($booking){
        $service_ids   = implode('-', $booking->getServicesIds());
        $attendant_ids = implode('-', array_values($booking->getAttendantsIds()));
        $start_time    = $booking->getStartsAt()->format('Y-m-d H:i:s');
        
        $lock_key = 'booking_lock_' . md5($service_ids . '_' . $attendant_ids . '_' . $start_time);
        delete_transient($lock_key);
    }
}
