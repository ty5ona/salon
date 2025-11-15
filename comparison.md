# CancelBooking vs SetBookingStatus - Ownership Check Comparison

## CancelBooking.php (Line 18-28)

```php
public function execute()
{
    if (!is_user_logged_in()) {
        return array( 'redirect' => wp_login_url());
    }

    $booking = $plugin->createBooking(intval($_POST['id']));

    // ✅ OWNERSHIP CHECK HERE!
    $available = $booking->getUserId() == get_current_user_id();
    
    $cancellationEnabled = $plugin->getSettings()->get('cancellation_enabled');
    $outOfTime = ($booking->getStartsAt()->getTimestamp() - time() ) < ...;

    // ✅ Only proceeds if $available is true
    if ($cancellationEnabled && !$outOfTime && $available) {
        $booking->setStatus(SLN_Enum_BookingStatus::CANCELED);
    } elseif (!$available) {
        $this->addError(__("You don't have access", 'salon-booking-system'));
    }
}
```

**Protection:** ✅ YES
- Line 18: `$available = $booking->getUserId() == get_current_user_id();`
- Line 22: Checks `$available` before allowing cancellation
- Line 27: Returns error if not available

---

## SetBookingStatus.php (Line 8-35)

```php
public function execute()
{
    if (!is_user_logged_in()) {
        return array( 'redirect' => wp_login_url());
    }

    if (!defined("SLN_VERSION_PAY")) {
        return array();
    }

    $booking = $plugin->createBooking(intval($_POST['booking_id']));

    // ⚠️ NO OWNERSHIP CHECK!
    if (in_array($_POST['status'], array(SLN_Enum_BookingStatus::CONFIRMED,
                                          SLN_Enum_BookingStatus::CANCELED))) {
        $booking->setStatus(wp_unslash($_POST['status']));
    }

    return array('success' => 1, 'status' => $statusLabel);
}
```

**Protection:** ❌ NO
- No line checking `$booking->getUserId() == get_current_user_id()`
- No check for admin capability
- Any logged-in user can change any booking status

---

## Conclusion

**CancelBooking:** ✅ PROTECTED - Has ownership check
**SetBookingStatus:** ❌ VULNERABLE - No ownership check

This is the IDOR vulnerability!
