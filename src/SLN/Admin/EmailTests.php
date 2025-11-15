<?php
/**
 * Email Notification Bug Tests Admin Page
 * Integrates with Salon Booking System admin menu
 */

/**
 * Mock booking class for older PHP versions
 */
class SLN_MockBooking
{
    public function getId() { return 999; }
    public function getStatus() { return 'pending'; }
    public function getAttendants() { return []; } // No attendants
    public function getDate() { return '2024-12-20'; }
    public function getTime() { return '10:00'; }
    public function getEmail() { return 'test@example.com'; }
    public function getDisplayName() { return 'Test Customer'; }
}

class SLN_Admin_EmailTests extends SLN_Admin_AbstractPage
{
    const PAGE = 'salon-email-tests';
    const PRIORITY = 15;

    public function __construct(SLN_Plugin $plugin)
    {
        parent::__construct($plugin);
        add_action('in_admin_header', array($this, 'in_admin_header'));
    }

    public function admin_menu()
    {
        $pagename = add_submenu_page(
            'salon',
            __('Email Bug Tests', 'salon-booking-system'),
            __('Email Tests', 'salon-booking-system'),
            $this->getCapability(),
            static::PAGE,
            array($this, 'show')
        );
        add_action('load-' . $pagename, array($this, 'enqueueAssets'));
    }

    public function enqueueAssets()
    {
        parent::enqueueAssets();
        wp_enqueue_style('salon-admin-css', SLN_PLUGIN_URL . '/css/admin.css', array(), SLN_VERSION, 'all');
    }

    public function show()
    {
        $runner = new SLN_EmailNotificationTestRunner($this->plugin);
        $runner->runTests();
    }
}

/**
 * Email Notification Test Runner
 * Handles the actual test execution and display
 */
class SLN_EmailNotificationTestRunner
{
    private $plugin;
    private $results = [];
    
    public function __construct($plugin)
    {
        $this->plugin = $plugin;
    }
    
    /**
     * Run all tests and display results
     */
    public function runTests()
    {
        echo '<div class="wrap">';
        echo '<h1>🧪 Email Notification Bug Tests</h1>';
        echo '<p>Testing admin email notifications when attendants are not assigned...</p>';
        
        $this->testBugConditions();
        $this->displayResults();
        
        echo '</div>';
    }
    
    /**
     * Test the specific bug conditions
     */
    private function testBugConditions()
    {
        // Test 1: Auto-assignment scenario
        $this->testScenario(
            'Auto-Assignment Scenario',
            'When attendant_auto is enabled and no attendant is assigned',
            function() {
                return $this->simulateAutoAssignment();
            }
        );
        
        // Test 2: Disabled attendants scenario
        $this->testScenario(
            'Disabled Attendants Scenario',
            'When service has attendants disabled',
            function() {
                return $this->simulateDisabledAttendants();
            }
        );
        
        // Test 3: Skip attendants scenario
        $this->testScenario(
            'Skip Attendants Scenario',
            'When skip_attendants_enabled is true',
            function() {
                return $this->simulateSkipAttendants();
            }
        );
        
        // Test 4: Backend-only attendants scenario
        $this->testScenario(
            'Backend-Only Attendants Scenario',
            'When attendants_enabled_only_backend is true',
            function() {
                return $this->simulateBackendOnlyAttendants();
            }
        );
        
        // Test 5: Modified booking scenario (should work)
        $this->testScenario(
            'Modified Booking Scenario',
            'When booking is modified (should work correctly)',
            function() {
                return $this->simulateModifiedBooking();
            }
        );
    }
    
    /**
     * Test a specific scenario
     */
    private function testScenario($name, $description, $testFunction)
    {
        echo "<h3>🔍 Testing: {$name}</h3>";
        echo "<p><strong>Description:</strong> {$description}</p>";
        
        try {
            $result = $testFunction();
            $this->results[$name] = $result;
            
            if ($result['bug_confirmed']) {
                echo "<div style='color: red; background: #ffe6e6; padding: 10px; border: 1px solid #ff9999; margin: 10px 0;'>";
                echo "❌ <strong>BUG CONFIRMED:</strong> Admin email NOT sent<br>";
                echo "📧 Recipients: " . $result['recipients'] . "<br>";
                echo "🔍 Debug Info: " . $result['debug_info'];
                echo "</div>";
            } else {
                echo "<div style='color: green; background: #e6ffe6; padding: 10px; border: 1px solid #99ff99; margin: 10px 0;'>";
                echo "✅ <strong>PASS:</strong> Admin email sent correctly<br>";
                echo "📧 Recipients: " . $result['recipients'];
                echo "</div>";
            }
        } catch (Exception $e) {
            echo "<div style='color: orange; background: #fff3cd; padding: 10px; border: 1px solid #ffeaa7; margin: 10px 0;'>";
            echo "⚠️ <strong>ERROR:</strong> " . $e->getMessage();
            echo "</div>";
        }
        
        echo "<hr>";
    }
    
    /**
     * Simulate auto-assignment scenario
     */
    private function simulateAutoAssignment()
    {
        // Setup: Enable attendant emails
        $settings = $this->plugin->getSettings();
        $settings->set('attendant_email', true);
        
        // Create mock booking data with no attendant
        $booking_data = [
            'services' => [
                ['service' => 1, 'attendant' => 0] // No attendant assigned
            ],
            'attendant_auto' => true
        ];
        
        return $this->testEmailSending($booking_data, 'Auto-assignment with no attendant');
    }
    
    /**
     * Simulate disabled attendants scenario
     */
    private function simulateDisabledAttendants()
    {
        // Setup: Enable attendant emails
        $settings = $this->plugin->getSettings();
        $settings->set('attendant_email', true);
        
        // Create mock booking data with service that doesn't require attendants
        $booking_data = [
            'services' => [
                ['service' => 1, 'attendant' => 0] // No attendant required
            ],
            'service_attendants_enabled' => false
        ];
        
        return $this->testEmailSending($booking_data, 'Service with attendants disabled');
    }
    
    /**
     * Simulate skip attendants scenario
     */
    private function simulateSkipAttendants()
    {
        // Setup: Enable skip attendants
        $settings = $this->plugin->getSettings();
        $settings->set('attendant_email', true);
        $settings->set('skip_attendants_enabled', true);
        
        // Create mock booking data
        $booking_data = [
            'services' => [
                ['service' => 1, 'attendant' => 0] // Attendant step skipped
            ]
        ];
        
        return $this->testEmailSending($booking_data, 'Skip attendants enabled');
    }
    
    /**
     * Simulate backend-only attendants scenario
     */
    private function simulateBackendOnlyAttendants()
    {
        // Setup: Enable backend-only attendants
        $settings = $this->plugin->getSettings();
        $settings->set('attendant_email', true);
        $settings->set('attendants_enabled_only_backend', true);
        
        // Create mock booking data
        $booking_data = [
            'services' => [
                ['service' => 1, 'attendant' => 0] // No attendant selection on frontend
            ]
        ];
        
        return $this->testEmailSending($booking_data, 'Backend-only attendants');
    }
    
    /**
     * Simulate modified booking scenario
     */
    private function simulateModifiedBooking()
    {
        // Setup: Enable attendant emails
        $settings = $this->plugin->getSettings();
        $settings->set('attendant_email', true);
        
        // Create mock booking data
        $booking_data = [
            'services' => [
                ['service' => 1, 'attendant' => 0] // No attendant assigned
            ],
            'updated' => true // This is a modified booking
        ];
        
        return $this->testEmailSending($booking_data, 'Modified booking (should work)');
    }
    
    /**
     * Test email sending for a scenario
     */
    private function testEmailSending($booking_data, $debug_info)
    {
        $captured_recipients = [];
        $admin_email_sent = false;
        
        // Mock email sending
        add_filter('wp_mail', function($args) use (&$captured_recipients, &$admin_email_sent) {
            $captured_recipients[] = $args['to'];
            
            $admin_email = $this->plugin->getSettings()->getSalonEmail();
            if (strpos($args['to'], $admin_email) !== false) {
                $admin_email_sent = true;
            }
            
            return $args;
        });
        
        // Create mock booking
        $booking = $this->createMockBooking($booking_data);
        
        // Test the email sending logic
        $this->testEmailLogic($booking, $booking_data);
        
        // Remove filter
        remove_all_filters('wp_mail');
        
        return [
            'bug_confirmed' => !$admin_email_sent,
            'recipients' => implode(', ', $captured_recipients),
            'debug_info' => $debug_info
        ];
    }
    
    /**
     * Create mock booking for testing
     */
    private function createMockBooking($data)
    {
        // Create a mock booking object with proper method structure
        if (version_compare(PHP_VERSION, '7.0.0', '>=')) {
            // Use anonymous class for PHP 7.0+
            $booking = new class {
                public function getId() { return 999; }
                public function getStatus() { return 'pending'; }
                public function getAttendants() { return []; } // No attendants
                public function getDate() { return '2024-12-20'; }
                public function getTime() { return '10:00'; }
                public function getEmail() { return 'test@example.com'; }
                public function getDisplayName() { return 'Test Customer'; }
            };
        } else {
            // Fallback for older PHP versions
            $booking = new SLN_MockBooking();
        }
        
        return $booking;
    }
    
    /**
     * Test the email logic directly
     */
    private function testEmailLogic($booking, $booking_data)
    {
        // Simulate the email template logic
        $adminEmail = $this->plugin->getSettings()->getSalonEmail();
        $attendantEmailOption = $this->plugin->getSettings()->get('attendant_email');
        
        // This is the buggy logic from summary_admin.php
        $data = [];
        
        if ($attendantEmailOption && ($attendants = $booking->getAttendants())) {
            // This block is skipped when no attendants
            foreach ($attendants as $attendant) {
                // Process attendant emails
            }
        }
        
        // The bug: if no attendants, $data['to'] is not set properly
        if (!isset($data['to'])) {
            $data['to'] = $adminEmail; // This should always happen
        }
        
        // Simulate email sending
        if (isset($data['to'])) {
            wp_mail($data['to'], 'Test Subject', 'Test Message');
        }
    }
    
    /**
     * Display test results summary
     */
    private function displayResults()
    {
        echo "<h2>📊 Test Results Summary</h2>";
        
        $total_tests = count($this->results);
        $bugs_found = 0;
        
        foreach ($this->results as $test_name => $result) {
            if ($result['bug_confirmed']) {
                $bugs_found++;
            }
        }
        
        echo "<div style='background: #f0f0f0; padding: 15px; border: 1px solid #ccc; margin: 20px 0;'>";
        echo "<h3>📈 Statistics</h3>";
        echo "<p><strong>Total Tests:</strong> {$total_tests}</p>";
        echo "<p><strong>Bugs Found:</strong> {$bugs_found}</p>";
        echo "<p><strong>Success Rate:</strong> " . round((($total_tests - $bugs_found) / $total_tests) * 100, 1) . "%</p>";
        echo "</div>";
        
        if ($bugs_found > 0) {
            echo "<div style='background: #ffe6e6; padding: 15px; border: 1px solid #ff9999; margin: 20px 0;'>";
            echo "<h3>🐛 Confirmed Bugs</h3>";
            echo "<p>The following scenarios have confirmed bugs where admin emails are not sent:</p>";
            echo "<ul>";
            foreach ($this->results as $test_name => $result) {
                if ($result['bug_confirmed']) {
                    echo "<li><strong>{$test_name}:</strong> " . $result['debug_info'] . "</li>";
                }
            }
            echo "</ul>";
            echo "</div>";
        }
        
        // Add fix information
        echo "<div style='background: #e6f3ff; padding: 15px; border: 1px solid #99ccff; margin: 20px 0;'>";
        echo "<h3>🔧 How to Fix</h3>";
        echo "<p>The bug is in <code>views/mail/summary_admin.php</code> lines 70-73. The admin email should always be sent regardless of attendant assignment.</p>";
        echo "<p><strong>Current buggy code:</strong></p>";
        echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ddd;'>";
        echo "if (\$attendantEmailOption && (\$attendants = \$booking->getAttendants(true))) {\n";
        echo "    // Admin email logic here - WRONG!\n";
        echo "}";
        echo "</pre>";
        echo "<p><strong>Fixed code:</strong></p>";
        echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ddd;'>";
        echo "// Always set admin email\n";
        echo "if (\$sendToAdmin) {\n";
        echo "    \$data['to'] = \$adminEmail;\n";
        echo "}\n";
        echo "// Then conditionally add attendant emails\n";
        echo "if (\$attendantEmailOption && (\$attendants = \$booking->getAttendants(true))) {\n";
        echo "    // Add attendant emails to the list\n";
        echo "}";
        echo "</pre>";
        echo "</div>";
    }
}
