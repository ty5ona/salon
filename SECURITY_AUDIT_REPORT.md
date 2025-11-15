# Security Audit Report: Salon Booking System WordPress Plugin
**Date:** 2025-11-15
**Version Audited:** 10.30.3
**Auditor:** Security Review
**Scope:** Complete codebase security assessment focusing on OWASP Top 10 vulnerabilities

---

## Executive Summary

This security audit has identified **CRITICAL** vulnerabilities that expose the plugin to severe security risks including unauthorized data access, privilege escalation, CSRF attacks, SSRF, and potential data breaches. **Immediate remediation is required.**

**Risk Level: CRITICAL**

### Vulnerability Summary
- **Critical Severity:** 8 vulnerabilities
- **High Severity:** 6 vulnerabilities
- **Medium Severity:** 3 vulnerabilities
- **Total:** 17 vulnerabilities identified

---

## CRITICAL SEVERITY VULNERABILITIES (P0)

### 1. Missing CSRF Protection on Core AJAX Handler
**OWASP Category:** A01:2021 – Broken Access Control
**CWE:** CWE-352 (Cross-Site Request Forgery)
**Location:** `src/SLN/Plugin.php:236-254`
**Severity:** CRITICAL (P0)

**Description:**
The main AJAX handler `ajax()` function processes all AJAX requests without ANY nonce verification. This function is registered for both authenticated (`wp_ajax_salon`) and unauthenticated (`wp_ajax_nopriv_salon`) users.

**Vulnerable Code:**
```php
public function ajax()
{
    SLN_TimeFunc::startRealTimezone();
    try {
        $method = sanitize_text_field(wp_unslash( $_REQUEST['method'] ));
        $className = 'SLN_Action_Ajax_'.ucwords($method);
        // No nonce verification!
        $obj = new $className($this);
        $ret = $obj->execute();
```

**Impact:**
- Attackers can execute ANY AJAX action by crafting malicious requests
- No CSRF protection allows cross-site attacks
- Unauthenticated users can trigger sensitive operations
- Affects ALL AJAX endpoints in the plugin

**Exploitation Example:**
An attacker can create a malicious webpage that triggers booking cancellations, status changes, or data modifications when a logged-in user visits it.

**Recommendation:**
- Implement nonce verification at the main handler level
- Verify nonces in each individual AJAX action
- Remove `wp_ajax_nopriv_salon` for sensitive operations

---

### 2. Unauthenticated Booking Creation via REST API
**OWASP Category:** A01:2021 – Broken Access Control
**CWE:** CWE-284 (Improper Access Control)
**Location:** `src/SLB_API/Controller/Bookings_Controller.php:100`
**Severity:** CRITICAL (P0)

**Description:**
The REST API endpoint for creating bookings has `'permission_callback' => '__return_true'`, allowing ANYONE to create bookings without authentication.

**Vulnerable Code:**
```php
array(
    'methods'   => WP_REST_Server::CREATABLE,
    'callback'  => array( $this, 'create_item' ),
    'permission_callback' => '__return_true',
```

**Impact:**
- Unauthenticated attackers can create unlimited fake bookings
- Denial of Service through booking system flooding
- Revenue loss and system disruption
- Database pollution

**Additional Affected Endpoints:**
The following REST API endpoints also use `__return_true` permission callbacks:
- `src/SLB_API/Controller/ServicesCategories_Controller.php` (multiple endpoints)
- `src/SLB_API/Controller/Customers_Controller.php` (multiple endpoints)
- `src/SLB_API/Controller/Users_Controller.php:24`
- `src/SLB_API/Controller/AvailabilityAssistants_Controller.php:61`
- `src/SLB_API/Controller/AvailabilityIntervals_Controller.php:60`
- `src/SLB_API/Controller/AvailabilityServices_Controller.php` (multiple endpoints)
- `src/SLB_API_Mobile/Controller/Bookings_Controller.php:124`
- And 20+ more endpoints across the API

**Recommendation:**
- Implement proper authentication checks for all endpoints
- Use WP REST API authentication mechanisms
- Add rate limiting for booking creation

---

### 3. Server-Side Request Forgery (SSRF) via Image Upload
**OWASP Category:** A10:2021 – Server-Side Request Forgery
**CWE:** CWE-918 (Server-Side Request Forgery)
**Location:** `src/SLB_API/Controller/REST_Controller.php:41-61`
**Severity:** CRITICAL (P0)

**Description:**
The `save_item_image()` function accepts arbitrary URLs and fetches them using `file_get_contents()` without validation.

**Vulnerable Code:**
```php
protected function save_item_image($image_url = '', $id = 0)
{
    // ...
    $filename  = basename($image_url);
    // Only checks file extension, not URL!
    $contents = file_get_contents($image_url); // SSRF HERE!
    $savefile = fopen($uploadfile, 'w');
    fwrite($savefile, $contents);
```

**Impact:**
- Attackers can make the server fetch content from internal network resources
- Port scanning of internal infrastructure
- Access to cloud metadata endpoints (AWS, Azure, GCP)
- Potential credential theft from metadata services
- Reading local files via file:// protocol

**Exploitation Example:**
```
POST /wp-json/slb-api/v1/services
{
  "image_url": "http://169.254.169.254/latest/meta-data/iam/security-credentials/"
}
```

**Recommendation:**
- Validate URL schemes (only allow http/https)
- Implement URL whitelist
- Use WordPress HTTP API with strict timeouts
- Block internal IP ranges and cloud metadata endpoints

---

### 4. Missing Authorization on Booking Duplication
**OWASP Category:** A01:2021 – Broken Access Control
**CWE:** CWE-862 (Missing Authorization)
**Location:** `src/SLN/Action/Ajax/DuplicateClone.php:19-74`
**Severity:** CRITICAL (P0)

**Description:**
The `DuplicateClone` AJAX action has NO authentication or authorization checks, allowing anyone to duplicate any booking.

**Vulnerable Code:**
```php
public function execute()
{
    $bookingId = (int)$_POST['bookingId'];
    $unit = (int)$_POST['unit'];
    // No authentication check!
    // No nonce verification!
    for ($i = 0; $i < $unit; $i++) {
        $booking = SLN_Plugin::getInstance()->createBooking($bookingId);
        // ... creates duplicate booking
```

**Impact:**
- Unauthenticated users can duplicate any booking
- Resource exhaustion through massive duplication
- Calendar flooding and system disruption

**Recommendation:**
- Add `current_user_can()` check
- Verify nonce
- Validate ownership of the booking

---

### 5. Missing CSRF on Booking Cancellation
**OWASP Category:** A01:2021 – Broken Access Control
**CWE:** CWE-352 (Cross-Site Request Forgery)
**Location:** `src/SLN/Action/Ajax/CancelBooking.php:8-42`
**Severity:** CRITICAL (P0)

**Description:**
While the action checks user authentication, it lacks nonce verification, allowing CSRF attacks.

**Vulnerable Code:**
```php
public function execute()
{
    if (!is_user_logged_in()) {
        return array( 'redirect' => wp_login_url());
    }
    // No nonce verification!
    $booking = $plugin->createBooking(intval($_POST['id']));
    // ... cancels booking
```

**Impact:**
- Attackers can force logged-in users to cancel their bookings
- Customer dissatisfaction and loss of business

**Recommendation:**
- Add nonce verification
- Implement CSRF tokens

---

### 6. Missing CSRF on Booking Status Change
**OWASP Category:** A01:2021 – Broken Access Control
**CWE:** CWE-352 (Cross-Site Request Forgery)
**Location:** `src/SLN/Action/Ajax/SetBookingStatus.php:8-35`
**Severity:** CRITICAL (P0)

**Description:**
Booking status can be changed without nonce verification. File header explicitly disables nonce checking: `// phpcs:ignoreFile WordPress.Security.NonceVerification.Missing`

**Vulnerable Code:**
```php
public function execute()
{
    if (!is_user_logged_in()) {
        return array( 'redirect' => wp_login_url());
    }
    // No nonce check despite being required!
    $booking = $plugin->createBooking(intval($_POST['booking_id']));
    if (in_array($_POST['status'], array(...))) {
        $booking->setStatus(wp_unslash($_POST['status']));
```

**Impact:**
- Force status changes on bookings (confirm, cancel)
- Business logic bypass
- Financial fraud potential

**Recommendation:**
- Add nonce verification immediately
- Remove phpcs ignore directive

---

### 7. Unauthenticated Discount Code Application
**OWASP Category:** A01:2021 – Broken Access Control
**CWE:** CWE-862 (Missing Authorization)
**Location:** `src/SLB_Discount/Action/Ajax/ApplyDiscountCode.php:10-76`
**Severity:** CRITICAL (P0)

**Description:**
Discount codes can be applied without ANY authentication or nonce verification.

**Vulnerable Code:**
```php
public function execute()
{
    $plugin = $this->plugin;
    $code   = sanitize_text_field(wp_unslash($_POST['sln']['discount']));
    // No auth check, no nonce!
    // Applies discount to booking
```

**Impact:**
- Unauthorized discount application
- Revenue loss
- Abuse of promotional codes

**Recommendation:**
- Add nonce verification
- Implement rate limiting

---

### 8. Credentials Transmitted via GET Request
**OWASP Category:** A07:2021 – Identification and Authentication Failures
**CWE:** CWE-598 (Use of GET Request Method With Sensitive Query Strings)
**Location:** `src/SLB_API_Mobile/Controller/Auth_Controller.php:38-42`
**Severity:** CRITICAL (P0)

**Description:**
Login endpoint uses GET method instead of POST, exposing credentials in URLs.

**Vulnerable Code:**
```php
register_rest_route( $this->namespace, '/login', array(
    array(
        'methods'   => WP_REST_Server::READABLE, // GET method!
        'callback'  => array( $this, 'login' ),
        'permission_callback' => '__return_true',
```

**Impact:**
- Credentials logged in server access logs
- Credentials stored in browser history
- Credentials exposed in referrer headers
- Proxy server credential exposure

**Recommendation:**
- Change to WP_REST_Server::CREATABLE (POST)
- Implement proper authentication mechanism

---

## HIGH SEVERITY VULNERABILITIES (P1)

### 9. SQL Injection via Unprepared Statement
**OWASP Category:** A03:2021 – Injection
**CWE:** CWE-89 (SQL Injection)
**Location:** `src/SLN/PostType/Booking.php:823`
**Severity:** HIGH (P1)

**Description:**
Direct SQL query without using `wpdb->prepare()`.

**Vulnerable Code:**
```php
$rows = $wpdb->get_results("SELECT mv1.meta_value as firstname, {$wpdb->prefix}postmeta.meta_value as lastname FROM {$wpdb->prefix}postmeta INNER JOIN {$wpdb->prefix}postmeta AS mv1 ON {$wpdb->prefix}postmeta.post_id = mv1.post_id AND mv1.meta_key = '_sln_booking_firstname' WHERE {$wpdb->prefix}postmeta.meta_key='_sln_booking_lastname';");
```

**Impact:**
- Potential SQL injection if table prefix is manipulated
- Database compromise

**Recommendation:**
- Use `$wpdb->prepare()` for all queries
- Parameterize all SQL statements

---

### 10. Reflected XSS in Calendar Search Results
**OWASP Category:** A03:2021 – Injection
**CWE:** CWE-79 (Cross-Site Scripting)
**Location:** `views/admin/_calendar_search_result.php:33`
**Severity:** HIGH (P1)

**Description:**
Unescaped output of booking amount.

**Vulnerable Code:**
```php
<div class="search-result__amount">
    <?php echo $booking['amount']; ?> <!-- Not escaped! -->
</div>
```

**Impact:**
- XSS attacks in admin panel
- Session hijacking
- Admin account compromise

**Recommendation:**
- Use `esc_html()` for all output
- Implement Content Security Policy

---

### 11. Missing CSRF on Booking Rescheduling
**OWASP Category:** A01:2021 – Broken Access Control
**CWE:** CWE-352 (Cross-Site Request Forgery)
**Location:** `src/SLN/Action/Ajax/RescheduleBooking.php:4-63`
**Severity:** HIGH (P1)

**Description:**
Booking rescheduling lacks nonce verification.

**Vulnerable Code:**
```php
public function execute() {
    if ( ! is_user_logged_in() ) {
        return array( 'redirect' => wp_login_url() );
    }
    $id = $_POST['_sln_booking_id']; // No nonce check!
```

**Impact:**
- CSRF attacks to reschedule bookings
- Service disruption

**Recommendation:**
- Add nonce verification

---

### 12. Missing CSRF on Rating Submission
**OWASP Category:** A01:2021 – Broken Access Control
**CWE:** CWE-352 (Cross-Site Request Forgery)
**Location:** `src/SLN/Action/Ajax/SetBookingRating.php:8-55`
**Severity:** HIGH (P1)

**Description:**
Rating and comment submission without nonce.

**Impact:**
- Fake reviews injection
- Reputation manipulation

**Recommendation:**
- Add nonce verification

---

### 13. Debug Mode Can Be Enabled by Unauthenticated Users
**OWASP Category:** A05:2021 – Security Misconfiguration
**CWE:** CWE-489 (Active Debug Code)
**Location:** `src/SLN/Action/Ajax/CheckDate.php:37-38`
**Severity:** HIGH (P1)

**Description:**
Debug mode setting can be modified via POST without authentication.

**Vulnerable Code:**
```php
$settings = SLN_Plugin::getInstance()->getSettings();
$settings->set( 'debug', $_POST['sln']['debug'] ?? false );
$settings->save();
```

**Impact:**
- Exposure of sensitive debug information
- Information disclosure
- Potential exploitation of debug features

**Recommendation:**
- Remove this functionality from client-accessible code
- Restrict debug mode changes to administrators only

---

### 14. Information Disclosure in Error Messages
**OWASP Category:** A05:2021 – Security Misconfiguration
**CWE:** CWE-209 (Information Exposure Through an Error Message)
**Location:** `src/SLN/Plugin.php:298-301`
**Severity:** HIGH (P1)

**Description:**
Detailed error messages including stack traces returned when debug is enabled.

**Impact:**
- Path disclosure
- Code structure exposure
- Aids in exploitation

**Recommendation:**
- Log detailed errors server-side only
- Return generic errors to clients

---

## MEDIUM SEVERITY VULNERABILITIES (P2)

### 15. Hardcoded API Credentials
**OWASP Category:** A07:2021 – Identification and Authentication Failures
**CWE:** CWE-798 (Use of Hard-coded Credentials)
**Location:** `salon.php:58-59`
**Severity:** MEDIUM (P2)

**Description:**
API key and token are hardcoded in the main plugin file.

**Vulnerable Code:**
```php
define('SLN_API_KEY', '0b47c255778d646aaa89b6f40859b159');
define('SLN_API_TOKEN', '7c901a98fa10dd3af65b038d6f5f190c');
```

**Impact:**
- Credentials exposed in source code
- Cannot be rotated without code changes

**Recommendation:**
- Move to WordPress options or environment variables
- Implement credential rotation

---

### 16. Insecure Session Configuration
**OWASP Category:** A07:2021 – Identification and Authentication Failures
**CWE:** CWE-614 (Sensitive Cookie in HTTPS Session Without 'Secure' Attribute)
**Location:** `salon.php:188-222`
**Severity:** MEDIUM (P2)

**Description:**
Session security depends on `is_ssl()` which may not always detect HTTPS properly behind proxies.

**Impact:**
- Session hijacking in mixed HTTP/HTTPS environments

**Recommendation:**
- Force secure cookies in production
- Implement proper proxy detection

---

### 17. Weak Token Generation
**OWASP Category:** A02:2021 – Cryptographic Failures
**CWE:** CWE-330 (Use of Insufficiently Random Values)
**Location:** `src/SLB_API_Mobile/Helper/TokenHelper.php:33-40`
**Severity:** MEDIUM (P2)

**Description:**
Access tokens generated using SHA1 with time-based component.

**Vulnerable Code:**
```php
public function createUserAccessToken($userId) {
    do {
        $accessToken = sha1($this->userApiTokenSalt.'-'.$userId.'-'.time());
    } while($this->getUserIdByAccessToken($accessToken));
```

**Impact:**
- Predictable tokens if salt is known
- Token collision potential

**Recommendation:**
- Use `wp_generate_password()` or secure random generators
- Increase entropy

---

## OWASP Top 10 2021 Mapping

1. **A01:2021 – Broken Access Control** - 9 vulnerabilities
2. **A02:2021 – Cryptographic Failures** - 1 vulnerability
3. **A03:2021 – Injection** - 2 vulnerabilities
4. **A05:2021 – Security Misconfiguration** - 2 vulnerabilities
5. **A07:2021 – Identification and Authentication Failures** - 3 vulnerabilities
6. **A10:2021 – Server-Side Request Forgery** - 1 vulnerability

---

## Remediation Priority

### Immediate (Within 24 hours):
1. Disable unauthenticated REST API endpoints
2. Add CSRF protection to main AJAX handler
3. Fix SSRF vulnerability in image upload
4. Add authentication to DuplicateClone action

### Short-term (Within 1 week):
5. Add nonce verification to all AJAX actions
6. Fix XSS in calendar search results
7. Change login endpoint to POST method
8. Remove debug mode modification from client code

### Medium-term (Within 1 month):
9. Fix SQL injection with prepared statements
10. Implement proper token generation
11. Review and fix all permission callbacks
12. Add rate limiting

---

## Testing Recommendations

1. **Penetration Testing:** Conduct full penetration test focusing on:
   - CSRF attacks on all forms and AJAX endpoints
   - REST API security
   - Authentication bypass attempts

2. **Code Review:** Complete line-by-line review of:
   - All AJAX handlers
   - All REST API endpoints
   - Input validation and output escaping

3. **Automated Scanning:**
   - WordPress security scanner (WPScan)
   - OWASP ZAP
   - Static code analysis tools

---

## Conclusion

This plugin contains **CRITICAL** security vulnerabilities that put customer data, bookings, and the entire system at significant risk. The most concerning issues are:

1. Complete lack of CSRF protection on the main AJAX handler
2. Unauthenticated access to numerous REST API endpoints
3. SSRF vulnerability allowing internal network access
4. Multiple missing authorization checks

**These vulnerabilities must be addressed immediately before the plugin is used in production.**

---

**Report End**
