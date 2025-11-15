# Debug Mode Vulnerability - Deep Dive Analysis

## Summary

**Vulnerability:** Unauthenticated modification of debug mode setting
**Location:** `src/SLN/Action/Ajax/CheckDate.php:37-38`
**Revised Severity:** **MEDIUM (P2)** _(downgraded from CRITICAL)_

---

## How to Enable Debug Mode

### Vulnerable Code Path

**File:** `src/SLN/Action/Ajax/CheckDate.php`

```php
public function execute()
{
    if(isset($_POST['sln'])){
        // ... date validation code ...

        // LINE 37-38: NO AUTHENTICATION CHECK!
        $settings = SLN_Plugin::getInstance()->getSettings();
        $settings->set('debug', $_POST['sln']['debug'] ?? false);
        $settings->save(); // Writes to wp_options table
    }
```

### Exploitation

**AJAX Endpoint:** `/wp-admin/admin-ajax.php`
**Action:** `salon`
**Method:** `CheckDate`

#### Enable Debug Mode:
```bash
curl -X POST 'https://target.com/wp-admin/admin-ajax.php' \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  --data 'action=salon&method=CheckDate&sln[debug]=1&sln[date]=2025-12-01&sln[time]=10:00'
```

#### Disable Debug Mode:
```bash
curl -X POST 'https://target.com/wp-admin/admin-ajax.php' \
  --data 'action=salon&method=CheckDate&sln[debug]=0&sln[date]=2025-12-01'
```

**Result:** The setting is written to `wp_options` table under the plugin's settings array.

---

## What Information Does Debug Mode Expose?

### CRITICAL FINDING: Debug Info Only Shown to Administrators

After analyzing the code, debug information is **ONLY** displayed when **BOTH** conditions are met:

1. ✅ `getSettings()->get('debug')` is `true` (the flag)
2. ✅ `current_user_can('administrator')` is `true`

### Code Evidence

**Location 1: AJAX Response** (`CheckDate.php:92-96`)
```php
if (true == SLN_Plugin::getInstance()->getSettings()->get('debug') &&
    current_user_can('administrator')) {
    // ⚠️ BOTH conditions required!
    $ret['debug']['times'] = SLN_Helper_Availability_AdminRuleLog::getInstance()->getLog();
    $ret['debug']['dates'] = SLN_Helper_Availability_AdminRuleLog::getInstance()->getDateLog();
    SLN_Helper_Availability_AdminRuleLog::getInstance()->clear();
}
```

**Location 2: Frontend HTML** (`views/shortcode/salon_date.php:26-27`)
```php
<?php if((bool)SLN_Plugin::getInstance()->getSettings()->get('debug') &&
          current_user_can('administrator')): ?>
    <!-- ⚠️ BOTH conditions required! -->
    data-debug="<?php echo esc_attr(wp_json_encode(
        SLN_Helper_Availability_AdminRuleLog::getInstance()->getDateLog()
    )); ?>"
<?php endif ?>
```

**Location 3: Log Collection** (`AdminRuleLog.php:25`)
```php
public function addLog(String $time, $value, String $key) {
    if (true == (bool)SLN_Plugin::getInstance()->getSettings()->get('debug') &&
        current_user_can('administrator')) {
        // ⚠️ BOTH conditions required!
        $this->ruleLog[esc_html($time)][esc_html($key)] = $value;
    }
}
```

### What Debug Info Contains (When Viewed by Admin)

**AdminRuleLog** tracks availability rule processing:

```json
{
  "debug": {
    "times": {
      "10:00": {
        "booking_rule_min_time": true,
        "assistant_available": true,
        "service_duration_fits": false
      },
      "11:00": {
        "booking_rule_min_time": true,
        "assistant_available": true,
        "service_duration_fits": true
      }
    },
    "dates": {
      "2025-12-01": "free",
      "2025-12-02": "Holiday rule: Christmas",
      "2025-12-03": "free"
    }
  }
}
```

**Information includes:**
- Time slot availability reasons
- Which booking rules passed/failed
- Assistant availability status
- Holiday/closure reasons
- Service duration validation

---

## Actual Attack Impact

### ❌ What an Attacker CANNOT Do:

1. **Cannot see debug information themselves**
   - Debug output requires `current_user_can('administrator')`
   - Unauthenticated attacker gets no additional data

2. **Cannot access sensitive data**
   - No database credentials exposed
   - No file paths shown to non-admins
   - No user information leaked to attacker

3. **Cannot disrupt service**
   - Debug mode doesn't change booking functionality
   - Only adds extra logging (invisible to non-admins)

### ✅ What an Attacker CAN Do:

1. **Modify database setting** (wp_options)
   - Toggle debug flag on/off
   - Minor database pollution

2. **Slightly increase server load**
   - Debug logging adds minimal overhead
   - AdminRuleLog creates in-memory arrays
   - Negligible performance impact

3. **Potentially confuse administrators**
   - If admin notices debug mode enabled unexpectedly
   - Could cause minor support overhead

4. **Use as reconnaissance**
   - Confirms plugin is installed and active
   - Confirms AJAX endpoint is accessible

---

## Revised Severity Assessment

### Initial Assessment: CRITICAL ❌

**Reasoning (Incorrect):**
- Assumed debug mode exposed sensitive information to attackers
- Thought it could leak database structure, paths, credentials

### Revised Assessment: MEDIUM (P2) ✅

**Reasoning (Correct):**
- ✅ **Database modification confirmed** (wp_options table)
- ❌ **No information disclosure to attacker** (requires admin capability)
- ❌ **No functional impact** (doesn't change booking behavior)
- ❌ **No privilege escalation** (can't use debug to gain admin access)

**Impact Analysis:**
- **Confidentiality:** NONE (debug only shown to admins)
- **Integrity:** LOW (modifies one boolean setting)
- **Availability:** NONE (no DoS potential)

**CVSS 3.1 Score:** ~4.3 (Medium)
- **Attack Vector:** Network (AV:N)
- **Attack Complexity:** Low (AC:L)
- **Privileges Required:** None (PR:N)
- **User Interaction:** None (UI:N)
- **Scope:** Unchanged (S:U)
- **Confidentiality:** None (C:N)
- **Integrity:** Low (I:L)
- **Availability:** None (A:N)

---

## Comparison with Similar Vulnerabilities

### Why This Is NOT Like Other Debug Mode Issues

**Typical debug mode vulnerability:**
```php
if ($debug) {
    echo "SQL: " . $query;           // ⚠️ SQL visible to anyone
    echo "Path: " . __FILE__;        // ⚠️ Paths exposed
    phpinfo();                        // ⚠️ Environment exposed
}
```

**This plugin's debug mode:**
```php
if ($debug && current_user_can('administrator')) {
    $ret['debug'] = $adminData;      // ✅ Only shown to admins
}
```

**Key difference:** This plugin has a **secondary authorization check** that prevents information disclosure.

---

## Attack Scenarios

### Scenario 1: Reconnaissance (Low Impact)
```bash
# Attacker enables debug mode
curl -X POST 'https://target.com/wp-admin/admin-ajax.php' \
  --data 'action=salon&method=CheckDate&sln[debug]=1&sln[date]=2025-12-01'

# Attacker checks response
# Result: No additional information visible to attacker
```

**Impact:** Attacker confirms plugin is active. No data leaked.

### Scenario 2: Admin Confusion (Low Impact)
```bash
# Attacker toggles debug on/off repeatedly
while true; do
  curl -X POST '.../admin-ajax.php' --data 'action=salon&method=CheckDate&sln[debug]=1&sln[date]=2025-12-01'
  sleep 5
  curl -X POST '.../admin-ajax.php' --data 'action=salon&method=CheckDate&sln[debug]=0&sln[date]=2025-12-01'
  sleep 5
done
```

**Impact:** If admin is watching, they see debug mode flipping. Minor annoyance.

### Scenario 3: Social Engineering (Theoretical, Low Impact)
1. Attacker enables debug mode
2. Contacts admin: "Your site is in debug mode, are you being hacked?"
3. Attempts to trick admin into revealing information

**Impact:** Requires social engineering. Low success probability.

---

## Why It's Still Worth Fixing

Even though the impact is limited, this should be fixed because:

1. **Principle of Least Privilege**
   - Unauthenticated users shouldn't modify ANY settings
   - Even harmless settings should require authentication

2. **Defense in Depth**
   - What if future code adds debug output without admin check?
   - Currently safe, but fragile design

3. **Code Quality**
   - Shows poor security practices
   - Indicates possible other issues

4. **Compliance**
   - Security audits flag unauthenticated database writes
   - Even low-impact issues can fail audits

---

## Remediation

### Option 1: Remove Client-Side Debug Toggle (Recommended)

**Remove lines 37-38 entirely:**
```php
public function execute()
{
    if(isset($_POST['sln'])){
        $date = isset($_POST['sln']['date']) ? sanitize_text_field(wp_unslash($_POST['sln']['date'])) : '';
        $time = isset($_POST['sln']['time']) ? sanitize_text_field(wp_unslash($_POST['sln']['time'])) : '';

        // ❌ REMOVE THESE LINES:
        // $settings = SLN_Plugin::getInstance()->getSettings();
        // $settings->set('debug', $_POST['sln']['debug'] ?? false);
        // $settings->save();
    }
```

**Rationale:**
- Debug mode should be controlled in admin panel only
- No legitimate reason for client-side toggling
- Simplest and safest fix

### Option 2: Add Admin Capability Check

```php
public function execute()
{
    if(isset($_POST['sln'])){
        // ... date processing ...

        // ✅ ONLY allow admins to change debug setting
        if (current_user_can('manage_options') && isset($_POST['sln']['debug'])) {
            $settings = SLN_Plugin::getInstance()->getSettings();
            $settings->set('debug', (bool)$_POST['sln']['debug']);
            $settings->save();
        }
    }
```

**Rationale:**
- Preserves functionality for admins
- Adds proper authorization

### Option 3: Add Admin Setting in WP Admin

Create a proper admin settings page:

```php
// In admin settings page
add_settings_field(
    'sln_debug_mode',
    __('Debug Mode', 'salon-booking-system'),
    'sln_debug_mode_callback',
    'salon-settings',
    'salon_settings_section'
);

function sln_debug_mode_callback() {
    $settings = SLN_Plugin::getInstance()->getSettings();
    $debug = $settings->get('debug');
    ?>
    <input type="checkbox" name="sln_debug" value="1" <?php checked($debug, true); ?> />
    <p class="description">Enable debug mode for troubleshooting availability issues</p>
    <?php
}
```

---

## Testing

### Verify Vulnerability Exists

```bash
# 1. Check current debug setting (requires database access)
wp option get salon_settings --path=/var/www/html

# 2. Enable debug mode without authentication
curl -X POST 'http://localhost/wp-admin/admin-ajax.php' \
  -v \
  --data 'action=salon&method=CheckDate&sln[debug]=1&sln[date]=2025-12-01&sln[time]=10:00'

# 3. Verify setting was changed
wp option get salon_settings --path=/var/www/html | grep debug

# Expected: "debug": true
```

### Verify Fix Works

```bash
# After applying Option 1 fix:
curl -X POST 'http://localhost/wp-admin/admin-ajax.php' \
  --data 'action=salon&method=CheckDate&sln[debug]=1&sln[date]=2025-12-01'

# Check database
wp option get salon_settings --path=/var/www/html | grep debug

# Expected: "debug" setting should NOT change
```

---

## Conclusion

**Final Severity: MEDIUM (P2)**

This vulnerability allows unauthenticated modification of a debug mode setting, but:
- ✅ No information disclosure to attacker
- ✅ No functional impact on booking system
- ✅ Debug output only visible to administrators
- ✅ Minimal security impact

**However**, it should still be fixed because:
- Violates principle of least privilege
- Poor security practice
- Could become dangerous if code changes in future

**Recommended Fix:** Remove client-side debug toggling (Option 1)

**Priority:** Fix within 30 days (not urgent, but should be addressed)

---

**Analysis Date:** 2025-11-15
**Analyst:** Security Review Team
