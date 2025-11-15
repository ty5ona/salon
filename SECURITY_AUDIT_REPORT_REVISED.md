# Security Audit Report: Salon Booking System WordPress Plugin (REVISED)
**Date:** 2025-11-15
**Version Audited:** 10.30.3
**Auditor:** Security Review - Revised Analysis
**Scope:** Complete codebase security assessment focusing on EXPLOITABLE vulnerabilities

---

## Executive Summary

This revised audit focuses on **actually exploitable vulnerabilities** where:
1. Actions **MODIFY data** (settings, database, posts, files, access levels)
2. **Missing authorization checks** (not just missing nonces)
3. **Real security impact** (not theoretical CSRF on read-only admin actions)

**Risk Level: HIGH**

### Revised Vulnerability Summary
- **Critical Severity (P0):** 5 vulnerabilities (immediate action required)
- **High Severity (P1):** 4 vulnerabilities (fix within 1 week)
- **Medium Severity (P2):** 3 vulnerabilities (fix within 1 month)
- **Total:** 12 exploitable vulnerabilities

---

## CRITICAL SEVERITY VULNERABILITIES (P0)

### 1. ⚠️ ANYONE Can Modify Plugin Settings (Debug Mode)
**OWASP Category:** A01:2021 – Broken Access Control
**CWE:** CWE-862 (Missing Authorization)
**Location:** `src/SLN/Action/Ajax/CheckDate.php:37-38`
**Severity:** CRITICAL (P0)

**Description:**
The `CheckDate` AJAX action modifies plugin settings without ANY authentication or authorization checks.

**Vulnerable Code:**
```php
public function execute()
{
    if(isset($_POST['sln'])){
        // ... date processing ...

        $settings = SLN_Plugin::getInstance()->getSettings();
        $settings->set( 'debug', $_POST['sln']['debug'] ?? false );
        $settings->save(); // ⚠️ NO AUTH CHECK!
    }
```

**Impact:**
- ✅ **Modifies database** (wp_options table)
- Unauthenticated users can enable debug mode
- Debug output may expose sensitive information
- Can be toggled repeatedly (no rate limiting)

**Exploitation:**
```bash
curl -X POST 'https://site.com/wp-admin/admin-ajax.php' \
  --data 'action=salon&method=CheckDate&sln[debug]=1'
```

**Recommendation:**
```php
// Remove this functionality from client-side code entirely
// OR add proper checks:
if (current_user_can('manage_options')) {
    $settings->set('debug', $_POST['sln']['debug'] ?? false);
    $settings->save();
}
```

---

### 2. ⚠️ Insecure Direct Object Reference - ANY User Can Change ANY Booking Status
**OWASP Category:** A01:2021 – Broken Access Control
**CWE:** CWE-639 (Authorization Bypass Through User-Controlled Key) + CWE-352 (CSRF)
**Location:** `src/SLN/Action/Ajax/SetBookingStatus.php:8-35`
**Severity:** CRITICAL (P0)

**Description:**
ANY logged-in user can change the status of ANY booking (not just their own).

**Vulnerable Code:**
```php
// phpcs:ignoreFile WordPress.Security.NonceVerification.Missing

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
    // ⚠️ NO NONCE CHECK!
    if (in_array($_POST['status'], array(SLN_Enum_BookingStatus::CONFIRMED,
                                          SLN_Enum_BookingStatus::CANCELED))) {
        $booking->setStatus(wp_unslash($_POST['status']));
    }
```

**Impact:**
- ✅ **Modifies database** (booking status)
- Horizontal privilege escalation (access other users' bookings)
- Business logic bypass (confirm/cancel any booking)
- CSRF vulnerability (force logged-in users to modify bookings)
- Financial fraud potential

**Exploitation:**
```bash
# Any logged-in user can cancel any booking
curl -X POST 'https://site.com/wp-admin/admin-ajax.php' \
  --cookie "wordpress_logged_in_..." \
  --data 'action=salon&method=SetBookingStatus&booking_id=123&status=sln-b-canceled'
```

**Recommendation:**
```php
public function execute()
{
    if (!is_user_logged_in()) {
        return array('redirect' => wp_login_url());
    }

    // Add nonce check
    if (!check_ajax_referer('sln_booking_status', 'nonce', false)) {
        return array('error' => 'Invalid request');
    }

    $booking = $plugin->createBooking(intval($_POST['booking_id']));

    // Check ownership OR admin capability
    if ($booking->getUserId() != get_current_user_id() &&
        !current_user_can('manage_salon')) {
        return array('error' => 'Unauthorized');
    }

    // ... rest of code
}
```

---

### 3. ⚠️ Unauthenticated Booking Duplication
**OWASP Category:** A01:2021 – Broken Access Control
**CWE:** CWE-862 (Missing Authorization)
**Location:** `src/SLN/Action/Ajax/DuplicateClone.php:19-74`
**Severity:** CRITICAL (P0)

**Description:**
No authentication or authorization checks - anyone can duplicate any booking.

**Vulnerable Code:**
```php
// phpcs:ignoreFile WordPress.Security.NonceVerification.Missing

public function execute()
{
    $bookingId = (int)$_POST['bookingId'];
    $unit = (int)$_POST['unit'];
    // ⚠️ NO AUTH CHECK!
    // ⚠️ NO NONCE CHECK!

    for ($i = 0; $i < $unit; $i++) {
        $booking = SLN_Plugin::getInstance()->createBooking($bookingId);

        $bb = new SLN_Wrapper_Booking_Builder(SLN_Plugin::getInstance());
        // ... copies booking data ...
        $bb->create(); // ⚠️ CREATES NEW POST!

        $booking = $bb->getLastBooking();
        $booking->setStatus('sln-b-confirmed'); // ⚠️ AUTO-CONFIRMS!
    }
```

**Impact:**
- ✅ **Creates posts** (new bookings)
- ✅ **Modifies database** (booking entries)
- Resource exhaustion (create thousands of bookings)
- Calendar flooding and system disruption
- Revenue manipulation

**Exploitation:**
```bash
# Create 100 duplicate bookings
curl -X POST 'https://site.com/wp-admin/admin-ajax.php' \
  --data 'action=salon&method=DuplicateClone&bookingId=1&unit=100&week_time=1'
```

**Recommendation:**
```php
public function execute()
{
    // Add authentication and authorization
    if (!is_user_logged_in()) {
        wp_die('Unauthorized', 403);
    }

    if (!check_ajax_referer('sln_duplicate_booking', 'nonce', false)) {
        wp_die('Invalid request', 403);
    }

    $bookingId = (int)$_POST['bookingId'];
    $booking = SLN_Plugin::getInstance()->createBooking($bookingId);

    // Verify ownership or admin capability
    if ($booking->getUserId() != get_current_user_id() &&
        !current_user_can('manage_salon')) {
        wp_die('Unauthorized', 403);
    }

    // ... rest of code
}
```

---

### 4. ⚠️ Server-Side Request Forgery (SSRF) via Image Upload
**OWASP Category:** A10:2021 – Server-Side Request Forgery
**CWE:** CWE-918 (Server-Side Request Forgery)
**Location:** `src/SLB_API/Controller/REST_Controller.php:41-61`
**Severity:** CRITICAL (P0)

**Description:**
The REST API accepts arbitrary URLs and fetches them without validation.

**Vulnerable Code:**
```php
protected function save_item_image($image_url = '', $id = 0)
{
    if (!$image_url) {
        delete_post_thumbnail($id);
        return;
    }

    $filename  = basename($image_url);

    // Only checks file extension
    $wp_check_image = $this->is_image(basename($filename));

    if(!$wp_check_image){
        throw new \Exception(esc_html__( 'Upload image error.', 'salon-booking-system' ));
    }

    // ⚠️ SSRF: Fetches ANY URL!
    $contents = file_get_contents($image_url);
    $savefile = fopen($uploadfile, 'w');
    fwrite($savefile, $contents);
```

**Impact:**
- ✅ **Writes files** to server
- Access internal network resources (192.168.x.x, 10.x.x.x)
- Read cloud metadata (AWS: http://169.254.169.254/latest/meta-data/)
- Port scanning
- Potential credential theft

**Exploitation:**
```bash
# Attempt to access AWS metadata
POST /wp-json/slb-api/v1/services
{
  "image_url": "http://169.254.169.254/latest/meta-data/iam/security-credentials/"
}

# Access internal services
{
  "image_url": "http://localhost:3306/"
}
```

**Recommendation:**
```php
protected function save_item_image($image_url = '', $id = 0)
{
    if (!$image_url) {
        delete_post_thumbnail($id);
        return;
    }

    // Validate URL scheme
    $parsed_url = parse_url($image_url);
    if (!in_array($parsed_url['scheme'], ['http', 'https'])) {
        throw new \Exception('Invalid URL scheme');
    }

    // Block internal IPs and cloud metadata
    $blocked_patterns = [
        '/^127\./', '/^localhost/', '/^10\./', '/^172\.(1[6-9]|2\d|3[01])\./',
        '/^192\.168\./', '/^169\.254\./', '/^::1$/', '/^fc00:/', '/^fe80:/'
    ];

    $host = $parsed_url['host'];
    $ip = gethostbyname($host);

    foreach ($blocked_patterns as $pattern) {
        if (preg_match($pattern, $host) || preg_match($pattern, $ip)) {
            throw new \Exception('Blocked IP range');
        }
    }

    // Use WordPress HTTP API with timeout
    $response = wp_remote_get($image_url, array(
        'timeout' => 10,
        'redirection' => 0,  // Prevent redirect-based bypasses
    ));

    if (is_wp_error($response)) {
        throw new \Exception('Failed to fetch image');
    }

    $contents = wp_remote_retrieve_body($response);
    // ... rest of code
}
```

---

### 5. ⚠️ Credentials Transmitted via GET Request
**OWASP Category:** A07:2021 – Identification and Authentication Failures
**CWE:** CWE-598 (Use of GET Request Method With Sensitive Query Strings)
**Location:** `src/SLB_API_Mobile/Controller/Auth_Controller.php:24-42`
**Severity:** CRITICAL (P0)

**Description:**
Login credentials sent via GET request instead of POST.

**Vulnerable Code:**
```php
register_rest_route( $this->namespace, '/login', array(
    'args' => array(
        'name' => array(
            'description' => __( 'User login.', 'salon-booking-system' ),
            'type'        => 'string',
            'required'    => true,
        ),
        'password' => array(
            'description' => __( 'User password.', 'salon-booking-system' ),
            'type'        => 'string',
            'required'    => true,
        ),
    ),
    array(
        'methods'   => WP_REST_Server::READABLE, // ⚠️ GET METHOD!
        'callback'  => array( $this, 'login' ),
        'permission_callback' => '__return_true',
    ),
) );
```

**Impact:**
- Credentials logged in web server access logs
- Credentials stored in browser history
- Credentials exposed in referrer headers
- Proxy servers can see credentials
- Shoulder surfing (visible in URL bar)

**Exploitation:**
```bash
# Credentials visible in logs and browser history
https://site.com/wp-json/slb-api-mobile/v1/login?name=admin&password=secret123
```

**Recommendation:**
```php
register_rest_route( $this->namespace, '/login', array(
    array(
        'methods'   => WP_REST_Server::CREATABLE, // POST method
        'callback'  => array( $this, 'login' ),
        'permission_callback' => '__return_true',
        'args' => array(
            'name' => array(
                'required' => true,
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'password' => array(
                'required' => true,
                'type' => 'string',
            ),
        ),
    ),
) );
```

---

## HIGH SEVERITY VULNERABILITIES (P1)

### 6. CSRF on Settings Modification (Admins Only)
**OWASP Category:** A01:2021 – Broken Access Control
**CWE:** CWE-352 (Cross-Site Request Forgery)
**Location:** `src/SLN/Action/Ajax/SetCustomText.php:8-30`
**Severity:** HIGH (P1)

**Description:**
Admin can be tricked into modifying settings via CSRF (has capability check but no nonce).

**Vulnerable Code:**
```php
public function execute()
{
    if (!is_user_logged_in()) {
        return array( 'redirect' => wp_login_url());
    }

    if(current_user_can('manage_options')) { // ✅ Has capability check
        $plugin->getSettings()->setCustomText(...); // ⚠️ Modifies settings
        $plugin->getSettings()->save();
    }
    // ⚠️ NO NONCE CHECK
}
```

**Impact:**
- ✅ **Modifies database** (settings)
- Limited to admins (capability check present)
- CSRF can force admin to change settings
- Lower impact than missing authorization

**Recommendation:**
Add nonce verification:
```php
if (!check_ajax_referer('sln_custom_text', 'nonce', false)) {
    return array('error' => 'Invalid request');
}
```

---

### 7. CSRF on Booking Cancellation (Users Can Cancel Their Own)
**OWASP Category:** A01:2021 – Broken Access Control
**CWE:** CWE-352 (Cross-Site Request Forgery)
**Location:** `src/SLN/Action/Ajax/CancelBooking.php:8-42`
**Severity:** HIGH (P1)

**Description:**
Users can be tricked into canceling their own bookings (has ownership check but no nonce).

**Vulnerable Code:**
```php
public function execute()
{
    if (!is_user_logged_in()) {
        return array( 'redirect' => wp_login_url());
    }

    $booking = $plugin->createBooking(intval($_POST['id']));

    $available = $booking->getUserId() == get_current_user_id(); // ✅ Ownership check

    if ($cancellationEnabled && !$outOfTime && $available) {
        $booking->setStatus(SLN_Enum_BookingStatus::CANCELED); // ⚠️ Modifies status
    }
    // ⚠️ NO NONCE CHECK
}
```

**Impact:**
- ✅ **Modifies database** (booking status)
- Limited to user's own bookings (ownership check present)
- CSRF can force user to cancel their booking
- Business disruption

**Recommendation:**
Add nonce verification.

---

### 8. CSRF on Booking Rescheduling
**OWASP Category:** A01:2021 – Broken Access Control
**CWE:** CWE-352 (Cross-Site Request Forgery)
**Location:** `src/SLN/Action/Ajax/RescheduleBooking.php:4-63`
**Severity:** HIGH (P1)

**Description:**
Users can be tricked into rescheduling their bookings (has ownership check but no nonce).

**Vulnerable Code:**
```php
if (get_current_user_id() != get_post_field('post_author', $id, 'edit')) { // ✅ Ownership
    wp_die('Sorry, you are not allowed...', 403);
}

update_post_meta($id, '_sln_booking_date', $date); // ⚠️ Modifies post meta
update_post_meta($id, '_sln_booking_time', $time);
// ⚠️ NO NONCE CHECK
```

**Impact:**
- ✅ **Modifies database** (post meta)
- Limited to user's own bookings
- CSRF can force rescheduling

**Recommendation:**
Add nonce verification.

---

### 9. SQL Injection Risk (Unprepared Statement)
**OWASP Category:** A03:2021 – Injection
**CWE:** CWE-89 (SQL Injection)
**Location:** `src/SLN/PostType/Booking.php:823`
**Severity:** HIGH (P1)

**Description:**
Direct SQL query without proper preparation.

**Vulnerable Code:**
```php
$rows = $wpdb->get_results("SELECT mv1.meta_value as firstname, {$wpdb->prefix}postmeta.meta_value as lastname FROM {$wpdb->prefix}postmeta INNER JOIN {$wpdb->prefix}postmeta AS mv1 ON {$wpdb->prefix}postmeta.post_id = mv1.post_id AND mv1.meta_key = '_sln_booking_firstname' WHERE {$wpdb->prefix}postmeta.meta_key='_sln_booking_lastname';");
```

**Note:** While `$wpdb->prefix` is controlled by WordPress, using unprepared statements is bad practice and could lead to injection if the code is modified or the prefix is ever user-controlled.

**Impact:**
- Potential SQL injection
- Violates WordPress coding standards

**Recommendation:**
```php
$rows = $wpdb->get_results(
    "SELECT mv1.meta_value as firstname, pm.meta_value as lastname
     FROM {$wpdb->postmeta} pm
     INNER JOIN {$wpdb->postmeta} AS mv1
       ON pm.post_id = mv1.post_id
       AND mv1.meta_key = '_sln_booking_firstname'
     WHERE pm.meta_key='_sln_booking_lastname'"
);
```

---

## MEDIUM SEVERITY VULNERABILITIES (P2)

### 10. Reflected XSS in Admin Search Results
**OWASP Category:** A03:2021 – Injection
**CWE:** CWE-79 (Cross-Site Scripting)
**Location:** `views/admin/_calendar_search_result.php:33`
**Severity:** MEDIUM (P2)

**Description:**
Unescaped output in admin area (limited to admin users who can already execute JS).

**Vulnerable Code:**
```php
<div class="search-result__amount">
    <?php echo $booking['amount']; ?> <!-- Not escaped! -->
</div>
```

**Context:**
- Only accessible to users with `manage_salon` capability (see SearchBookings.php:7)
- Admins can already execute arbitrary code via plugin editor
- Still should be fixed for defense in depth

**Recommendation:**
```php
<div class="search-result__amount">
    <?php echo esc_html($booking['amount']); ?>
</div>
```

---

### 11. Weak Token Generation
**OWASP Category:** A02:2021 – Cryptographic Failures
**CWE:** CWE-330 (Use of Insufficiently Random Values)
**Location:** `src/SLB_API_Mobile/Helper/TokenHelper.php:33-40`
**Severity:** MEDIUM (P2)

**Description:**
Access tokens use SHA1 with time-based component.

**Vulnerable Code:**
```php
public function createUserAccessToken($userId) {
    do {
        $accessToken = sha1($this->userApiTokenSalt.'-'.$userId.'-'.time());
    } while($this->getUserIdByAccessToken($accessToken));

    return $accessToken;
}
```

**Impact:**
- Predictable tokens if salt is leaked
- SHA1 is deprecated
- Time-based component is weak

**Recommendation:**
```php
public function createUserAccessToken($userId) {
    return wp_generate_password(64, true, true);
}
```

---

### 12. Hardcoded API Credentials
**OWASP Category:** A05:2021 – Security Misconfiguration
**CWE:** CWE-798 (Use of Hard-coded Credentials)
**Location:** `salon.php:58-59`
**Severity:** MEDIUM (P2)

**Description:**
API credentials hardcoded in source.

**Vulnerable Code:**
```php
define('SLN_API_KEY', '0b47c255778d646aaa89b6f40859b159');
define('SLN_API_TOKEN', '7c901a98fa10dd3af65b038d6f5f190c');
```

**Impact:**
- Credentials exposed in version control
- Cannot rotate without code deployment

**Recommendation:**
Move to wp_options or environment variables.

---

## INTENTIONALLY PUBLIC ENDPOINTS (NOT VULNERABILITIES)

### ✅ REST API Booking Creation
**Location:** `src/SLB_API/Controller/Bookings_Controller.php:100`

**Analysis:**
```php
array(
    'methods'   => WP_REST_Server::CREATABLE,
    'callback'  => array( $this, 'create_item' ),
    'permission_callback' => '__return_true', // Public by design
```

**Why This Is OK:**
- Booking forms need to work for non-logged-in visitors
- This is the intended functionality of a booking system
- Input validation is performed in the `create_item` method
- **Recommendation:** Add rate limiting to prevent abuse

### ✅ Other Public Endpoints
These endpoints appear intentionally public for the booking flow:
- Service categories (public catalog)
- Availability checking (users need to see available slots)
- Services listing (public information)

**Recommendation:** Add rate limiting but don't require authentication.

---

## AJAX ACTIONS WITH PROPER PROTECTION (✅ Good Examples)

### ✅ SaveNote.php
```php
public function execute()
{
    check_ajax_referer('ajax_post_validation', 'security'); // ✅ Has nonce check
    // ... saves note
}
```

### ✅ SearchUser.php
```php
public function execute()
{
    if(!current_user_can( 'manage_salon' )) throw new Exception('not allowed'); // ✅ Capability check
    // ... only reads data (search)
}
```

### ✅ SearchBookings.php
```php
function execute(){
    if( !current_user_can( 'manage_salon' ) ){ // ✅ Capability check
        return array( 'status' => '403' );
    }
    // ... only reads data (search)
}
```

**Note:** Read-only actions with capability checks still benefit from CSRF protection but are lower priority.

---

## Remediation Priority

### 🚨 IMMEDIATE (24 hours):
1. **CheckDate debug setting** - Remove or add auth check
2. **SetBookingStatus IDOR** - Add ownership verification
3. **DuplicateClone** - Add authentication
4. **SSRF in image upload** - Add URL validation
5. **GET credentials** - Change to POST

### ⚠️ SHORT-TERM (1 week):
6. Add nonce checks to admin settings modifications
7. Add nonce checks to user booking modifications (cancel, reschedule)
8. Fix SQL injection with prepared statements
9. Add rate limiting to public REST API endpoints

### 📋 MEDIUM-TERM (1 month):
10. Fix XSS in admin area
11. Improve token generation
12. Move hardcoded credentials to options

---

## Testing Recommendations

1. **Manual Testing:**
   - Test all AJAX actions with Burp Suite
   - Verify ownership checks on booking modifications
   - Test SSRF with internal IP ranges

2. **Automated:**
   - WPScan for known vulnerabilities
   - Static analysis with Psalm/PHPStan

3. **Code Review:**
   - Review all AJAX actions that call `update_post_meta()`, `wp_update_post()`, or `$settings->save()`
   - Verify capability checks on all admin functions

---

## Conclusion

**5 CRITICAL vulnerabilities** require immediate attention:
1. Unauthenticated settings modification (debug mode)
2. IDOR on booking status changes
3. Unauthenticated booking duplication
4. SSRF in image upload
5. Credentials via GET

The plugin's main security issues stem from:
- Missing authorization checks on data-modifying actions
- Missing CSRF protection (though less critical when combined with capability checks)
- Improper HTTP method usage

**Action Required:** Fix the 5 critical issues before production use.

---

**Report End**
