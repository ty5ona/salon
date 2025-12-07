# Security Audit Report: API Endpoints & SQL Escaping Issues

**Date:** 2025-12-07
**Scope:** WordPress Salon Booking Plugin
**Focus:** API Security & esc_like/SQL Escaping Patterns

---

## Executive Summary

This audit identified **6 security vulnerabilities** across the codebase:
- **3 Missing esc_like()** issues in LIKE queries (allows wildcard injection)
- **2 SQL Injection** vulnerabilities via string concatenation in REST API stats endpoints
- **1 Improper escaping** using internal WordPress function

**No instances of esc_like() used in wrong order** were found because the codebase doesn't use `esc_like()` at all - which is itself the problem.

---

## Part 1: API Endpoints Inventory

### REST API Endpoints (78 total)

| Namespace | Controller Count | Purpose |
|-----------|-----------------|---------|
| `salon/api/v1` | 36 | Desktop Application API |
| `salon/api/mobile/v1` | 36 | Mobile Application API |

### AJAX Handlers (13 total)

| Hook | File | Auth Required |
|------|------|---------------|
| `salon_discount` | SLB_Discount/Plugin.php:49 | No |
| `sln_discount` | SLB_Discount/PostType/Discount.php:18 | Yes |
| `sln_attendant` | SLN/PostType/Attendant.php:17 | Yes |
| `sln_service` | SLN/PostType/Service.php:19 | Yes |
| `sln_resource` | SLN/PostType/Resource.php:27 | Yes |
| `googleoauth-callback` | SLN/Third/GoogleScope.php:87 | No |
| `startsynch` | SLN/Third/GoogleScope.php:89 | Yes |
| `deleteallevents` | SLN/Third/GoogleScope.php:90 | Yes |
| `salon` | SLN/Action/Init.php:425 | No |
| `saloncalendar` | SLN/Action/Init.php:427 | Yes |
| `sln_send_feedback_email` | SLN/Action/Init.php:428 | Yes |

**Total API Entry Points: 91**

---

## Part 2: esc_like() Security Rule

### The Rule
> `esc_like()` must ONLY be used **BEFORE** `wpdb::prepare()` or `esc_sql()`. Reversing the order is a security vulnerability.

### Correct Pattern
```php
// CORRECT: esc_like BEFORE prepare
$search = '%' . $wpdb->esc_like($user_input) . '%';
$results = $wpdb->get_results(
    $wpdb->prepare("SELECT * FROM table WHERE column LIKE %s", $search)
);
```

### Wrong Pattern
```php
// WRONG: esc_like AFTER prepare (NEVER DO THIS)
$query = $wpdb->prepare("SELECT * FROM table WHERE column LIKE %s", $user_input);
$query = $wpdb->esc_like($query); // TOO LATE - damage already done
```

### Audit Result
**The codebase contains ZERO instances of `esc_like()`** - This means all LIKE queries with user input are vulnerable to wildcard injection attacks.

---

## Part 3: Vulnerabilities Found

### VULNERABILITY #1: Missing esc_like() in Customer Search (CRITICAL)

**File:** `src/SLN/Admin/Customers/List.php:100-117`

**Vulnerable Code:**
```php
$usersearch = !empty($_REQUEST['s'])
    ? '%'.wp_unslash(trim(sanitize_text_field(wp_unslash($_REQUEST['s'])))).'%'
    : '';

// Later used in LIKE queries without esc_like():
$where = "AND
    (
        usermeta2.meta_key = 'first_name' AND usermeta2.meta_value LIKE %s
        OR
        usermeta2.meta_key = 'last_name' AND usermeta2.meta_value LIKE %s
        ...
    )";
```

**Issue:** User input can contain `%` or `_` wildcards that manipulate the LIKE query behavior.

**Attack Vector:** An attacker can search for `%` to match all customers, or use `_` to match single characters for enumeration attacks.

**Fix Required:**
```php
$usersearch = !empty($_REQUEST['s'])
    ? '%' . $wpdb->esc_like(trim(sanitize_text_field(wp_unslash($_REQUEST['s'])))) . '%'
    : '';
```

---

### VULNERABILITY #2: Missing esc_like() in User Search AJAX (CRITICAL)

**File:** `src/SLN/Action/Ajax/SearchUser.php:80-97`

**Vulnerable Code:**
```php
$wpdb->prepare(
    "SELECT DISTINCT user_id FROM $wpdb->usermeta
     WHERE ... LOWER(meta_value) LIKE %s",
    '%' . $qstr . '%'  // NO esc_like()
);

$wpdb->prepare(
    "SELECT DISTINCT $wpdb->users.ID FROM $wpdb->users
     JOIN $wpdb->usermeta ON ...
     WHERE LOWER($wpdb->users.user_nicename) LIKE %s
     OR LOWER($wpdb->users.user_email) LIKE %s ...",
    '%' . $qstr . '%',  // NO esc_like()
    '%' . $qstr . '%',  // NO esc_like()
    ...
);
```

**Issue:** `$qstr` (user search query) is not escaped with `esc_like()` before being used in LIKE patterns.

**Fix Required:**
```php
$escaped_search = $wpdb->esc_like($qstr);
// Then use '%' . $escaped_search . '%' in prepare()
```

---

### VULNERABILITY #3: Missing esc_like() in Staff Member Search (CRITICAL)

**File:** `src/SLN/Action/Ajax/SearchAssistantStaffMember.php:78-85`

**Vulnerable Code:**
```php
$wpdb->prepare(
    "SELECT DISTINCT ID FROM $wpdb->users u
     INNER JOIN $wpdb->usermeta um ON u.ID = um.user_id
     WHERE LOWER(u.user_email) LIKE %s
     AND meta_key='{$wpdb->prefix}capabilities'
     AND ( meta_value LIKE %s OR meta_value LIKE %s)",
    '%' . $wp_user_query . '%',  // NO esc_like()
    ...
);
```

**Issue:** `$wp_user_query` is not escaped for LIKE special characters.

**Fix Required:**
```php
'%' . $wpdb->esc_like($wp_user_query) . '%'
```

---

### VULNERABILITY #4: SQL Injection in Desktop API Stats (HIGH)

**File:** `src/SLB_API/Controller/Bookings_Controller.php:384-405`

**Vulnerable Code:**
```php
$format = $formats[$request->get_param('group_by')];

$sql_joins = "INNER JOIN {$wpdb->prefix}postmeta pm ON p.id = pm.post_id
    AND pm.meta_key = '_sln_booking_date'
    AND DATE(pm.meta_value) >= '".(new \SLN_DateTime($request->get_param('start_date')))->format('Y-m-d')."'
    AND DATE(pm.meta_value) <= '".(new \SLN_DateTime($request->get_param('end_date')))->format('Y-m-d')."'";

$results = $wpdb->get_results("
    SELECT COUNT(DISTINCT p.ID) as bookings_count,
           DATE_FORMAT(pm.meta_value, '".$format."') as unit_value
    FROM {$wpdb->prefix}posts p
    {$sql_joins}
    WHERE p.post_type = '".self::POST_TYPE."'
    ...
    GROUP BY DATE_FORMAT(pm.meta_value, '".$format."')",
    OBJECT
);
```

**Issues:**
1. `$format` variable derived from `group_by` param is interpolated directly into SQL
2. `start_date` and `end_date` are passed through `SLN_DateTime` but still concatenated into SQL string
3. No use of `$wpdb->prepare()` with placeholders

**Attack Vector:** If input validation on `group_by` is weak, attacker could inject SQL via the format string.

**Fix Required:** Use `$wpdb->prepare()` with proper placeholders for all dynamic values.

---

### VULNERABILITY #5: SQL Injection in Mobile API Stats (HIGH)

**File:** `src/SLB_API_Mobile/Controller/Bookings_Controller.php:293-314`

**Vulnerable Code:** Identical pattern to Desktop API (copy-paste code)

```php
$sql_joins = "INNER JOIN {$wpdb->prefix}postmeta pm ON p.id = pm.post_id
    AND pm.meta_key = '_sln_booking_date'
    AND DATE(pm.meta_value) >= '".(new \SLN_DateTime($request->get_param('start_date')))->format('Y-m-d')."'
    AND DATE(pm.meta_value) <= '".(new \SLN_DateTime($request->get_param('end_date')))->format('Y-m-d')."'";
```

**Fix Required:** Same as Vulnerability #4 - use `$wpdb->prepare()`.

---

### VULNERABILITY #6: Improper Escaping in ORDER BY (MODERATE)

**File:** `src/SLN/Admin/Customers/List.php:159`

**Vulnerable Code:**
```php
$sqlSelect = "SELECT DISTINCT ID
    FROM {$wpdb->users} AS users
    ...
    ORDER BY ".$wpdb->_real_escape($orderby)." ".$wpdb->_real_escape($order)." LIMIT %d, %d";
```

**Issues:**
1. `$wpdb->_real_escape()` is an internal WordPress function not intended for public use
2. It's deprecated and may be removed in future WordPress versions
3. ORDER BY injection should use whitelist validation, not escaping

**Current Mitigation:** The code does validate `$order` against `['asc', 'desc']` (line 123) and `$orderby` against a whitelist (lines 124-131), but then still uses `_real_escape()` unnecessarily.

**Fix Required:** Remove `_real_escape()` calls since whitelist validation is already in place, or use `esc_sql()` if escaping is needed.

---

## Part 4: Risk Assessment

| Vulnerability | Severity | Exploitability | Impact |
|--------------|----------|----------------|--------|
| #1 Missing esc_like (Customer) | Critical | Easy | Data Enumeration |
| #2 Missing esc_like (User Search) | Critical | Easy | Data Enumeration |
| #3 Missing esc_like (Staff Search) | Critical | Easy | Data Enumeration |
| #4 SQL Injection (Desktop API) | High | Medium | Data Breach |
| #5 SQL Injection (Mobile API) | High | Medium | Data Breach |
| #6 Improper ORDER BY Escape | Moderate | Low | Code Maintainability |

---

## Part 5: Recommended Fixes

### Global Pattern for LIKE Queries

**Before ANY user input is used in a LIKE query:**
```php
// Step 1: Sanitize input
$input = sanitize_text_field($_REQUEST['search']);

// Step 2: Escape LIKE special characters BEFORE prepare()
$escaped = $wpdb->esc_like($input);

// Step 3: Add wildcards
$search = '%' . $escaped . '%';

// Step 4: Use in prepared statement
$results = $wpdb->get_results(
    $wpdb->prepare("SELECT * FROM {$wpdb->users} WHERE display_name LIKE %s", $search)
);
```

### For SQL Injection in API Stats

Replace string concatenation with prepared statements:
```php
$results = $wpdb->get_results(
    $wpdb->prepare("
        SELECT COUNT(DISTINCT p.ID) as bookings_count,
               DATE_FORMAT(pm.meta_value, %s) as unit_value
        FROM {$wpdb->prefix}posts p
        INNER JOIN {$wpdb->prefix}postmeta pm ON p.id = pm.post_id
        WHERE pm.meta_key = '_sln_booking_date'
        AND DATE(pm.meta_value) >= %s
        AND DATE(pm.meta_value) <= %s
        AND p.post_type = %s
        AND p.post_status <> 'trash'
        GROUP BY DATE_FORMAT(pm.meta_value, %s)",
        $format,
        $start_date,
        $end_date,
        self::POST_TYPE,
        $format
    ),
    OBJECT
);
```

---

## Part 6: Files Requiring Changes

| File | Lines | Issue |
|------|-------|-------|
| `src/SLN/Admin/Customers/List.php` | 100, 159 | Add esc_like, remove _real_escape |
| `src/SLN/Action/Ajax/SearchUser.php` | 82, 93-96 | Add esc_like |
| `src/SLN/Action/Ajax/SearchAssistantStaffMember.php` | 81 | Add esc_like |
| `src/SLB_API/Controller/Bookings_Controller.php` | 384-405 | Use wpdb::prepare() |
| `src/SLB_API_Mobile/Controller/Bookings_Controller.php` | 293-314 | Use wpdb::prepare() |

---

## Conclusion

The codebase has significant SQL escaping issues, primarily due to:
1. **Complete absence of `esc_like()`** for LIKE query user input
2. **String concatenation** instead of prepared statements in API endpoints

These vulnerabilities could allow attackers to enumerate sensitive data or potentially inject malicious SQL. Immediate remediation is recommended for all identified issues.
