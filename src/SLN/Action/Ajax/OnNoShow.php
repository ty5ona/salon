<?php
// phpcs:ignoreFile WordPress.Security.NonceVerification.Missing

class SLN_Action_Ajax_OnNoShow extends SLN_Action_Ajax_Abstract
{
    const STATUS_ERROR = -1;
    const STATUS_UNCHECKED = 0;
    const STATUS_CHECKED = 1;

    /** @var  SLN_Wrapper_Booking_Builder */
    protected $bb;
    /** @var  SLN_Helper_Availability */
    protected $ah;

    protected $date;
    protected $time;
    protected $errors = array();

    public function execute()
    {
        $bookingId = (int)$_POST['bookingId'];
        $noShow = (int)$_POST['noShow'];

        // Toggle the value
        if ($noShow === 0) {
            $noShow = 1;
        } else {
            $noShow = 0;
        }

        // Update the post meta
        update_post_meta($bookingId, 'no_show', $noShow);

        return array(
            'id'      => $bookingId,
            'noShow'      => $noShow,
        );
    }
}
